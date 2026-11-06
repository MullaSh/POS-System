<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Cash\Models\CashHandover;
use App\Modules\Cash\Models\Expense;
use App\Modules\Cash\Models\PettyCashRefill;
use App\Modules\Cash\Services\HandoverService;
use App\Modules\Cash\Services\ReconciliationService;
use App\Modules\Location\Models\Location;
use App\Modules\Sales\Models\CashierShift;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Biaya operasional, arus kas, dan rekonsiliasi kas (C16; keputusan Owner irisan 9 butir 4 dan 6). */
class CashReport
{
    public function __construct(private readonly HandoverService $handovers, private readonly ReconciliationService $recon) {}

    /** Biaya per kategori dan lokasi. Yang ditolak/dibatalkan tidak dihitung; yang menunggu diperiksa ditandai. @return array<string, mixed> */
    public function expenses(Period $p, ?int $locationId): array
    {
        $base = DB::table('expenses as e')->join('expense_categories as c', 'c.id', '=', 'e.expense_category_id')->join('locations as loc', 'loc.id', '=', 'e.location_id')
            ->whereBetween('e.business_date', [$p->from, $p->to])->when($locationId, fn ($q) => $q->where('e.location_id', $locationId));
        $rows = (clone $base)->whereIn('e.status', [Expense::STATUS_RECORDED, Expense::STATUS_VERIFIED])
            ->groupBy('c.id', 'c.name', 'loc.id', 'loc.name', 'loc.type')
            ->selectRaw("c.id cat_id, c.name cat_name, loc.id loc_id, loc.name loc_name, loc.type loc_type, count(*) n, sum(e.amount) amount,
                sum(CASE WHEN e.status = 'RECORDED' THEN e.amount ELSE 0 END) pending")
            ->orderBy('c.name')->get()
            ->map(fn ($r) => ['category' => ['id' => (int) $r->cat_id, 'name' => $r->cat_name],
                'location' => ['id' => (int) $r->loc_id, 'name' => $r->loc_type === Location::TYPE_WAREHOUSE ? 'Gudang & pusat' : $r->loc_name],
                'count' => (int) $r->n, 'amount' => (int) $r->amount, 'pending' => (int) $r->pending])->all();
        $rejected = (clone $base)->where('e.status', Expense::STATUS_REJECTED)->orderBy('e.business_date')
            ->get(['e.id', 'e.number', 'e.business_date', 'e.amount', 'e.description', 'e.decision_note', 'loc.name as loc_name'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'number' => $r->number, 'date' => $r->business_date, 'amount' => (int) $r->amount,
                'description' => $r->description, 'reason' => $r->decision_note, 'location' => $r->loc_name])->all();

        $byCategory = [];
        foreach ($rows as $r) {
            $byCategory[$r['category']['id']] ??= ['category' => $r['category'], 'amount' => 0];
            $byCategory[$r['category']['id']]['amount'] += $r['amount'];
        }
        usort($byCategory, fn ($a, $b) => $b['amount'] <=> $a['amount']);

        return [
            'period' => $p->toArray(), 'rows' => $rows, 'by_category' => array_values($byCategory), 'rejected' => $rejected,
            'total' => ['amount' => array_sum(array_column($rows, 'amount')), 'pending' => array_sum(array_column($rows, 'pending')),
                'rejected' => array_sum(array_column($rejected, 'amount'))],
        ];
    }

    /**
     * Uang masuk dan keluar per periode, tanpa saldo awal (saldo kas/bank Finance belum dicatat; menyusul bersama
     * neraca, OD-23). Ditambah posisi uang yang masih di jalan saat ini.
     *
     * @return array<string, mixed>
     */
    public function cashFlow(Period $p): array
    {
        $between = [$p->from, $p->to];
        $refundDate = Period::dateOf('r.applied_at');
        $salesIn = DB::table('sale_payments as sp')->join('sales as s', 's.id', '=', 'sp.sale_id')->whereBetween('s.business_date', $between)
            ->selectRaw('sum(CASE WHEN sp.is_cash THEN sp.amount ELSE 0 END) cash, sum(CASE WHEN sp.is_cash THEN 0 ELSE sp.amount END) noncash')->first();
        $refundOut = DB::table('sale_refund_payments as rp')->join('sale_refunds as r', 'r.id', '=', 'rp.sale_refund_id')
            ->whereNotNull('r.applied_at')->whereRaw("{$refundDate} BETWEEN ? AND ?", $between)
            ->selectRaw('sum(CASE WHEN rp.is_cash THEN rp.amount ELSE 0 END) cash, sum(CASE WHEN rp.is_cash THEN 0 ELSE rp.amount END) noncash')->first();
        $supplier = (int) DB::table('supplier_payments')->whereBetween('paid_date', $between)->sum('amount');
        $financeExpenses = (int) DB::table('expenses')->where('paid_from', Expense::PAID_FINANCE)->whereIn('status', [Expense::STATUS_RECORDED, Expense::STATUS_VERIFIED])
            ->whereBetween('business_date', $between)->sum('amount');
        $refills = (int) DB::table('petty_cash_refills')->where('status', PettyCashRefill::STATUS_DONE)->whereRaw(Period::dateOf('refilled_at').' BETWEEN ? AND ?', $between)->sum('amount');

        $in = [
            ['key' => 'sales_cash', 'label' => 'Penjualan tunai', 'amount' => (int) ($salesIn->cash ?? 0)],
            ['key' => 'sales_noncash', 'label' => 'Penjualan non-tunai', 'amount' => (int) ($salesIn->noncash ?? 0)],
            ['key' => 'refund_cash', 'label' => 'Void/refund tunai', 'amount' => -(int) ($refundOut->cash ?? 0)],
            ['key' => 'refund_noncash', 'label' => 'Void/refund non-tunai', 'amount' => -(int) ($refundOut->noncash ?? 0)],
        ];
        $out = [
            ['key' => 'supplier_payments', 'label' => 'Pembayaran supplier', 'amount' => $supplier],
            ['key' => 'finance_expenses', 'label' => 'Biaya dibayar Finance (gudang & pusat)', 'amount' => $financeExpenses],
            ['key' => 'petty_cash_refills', 'label' => 'Isi ulang Petty Cash outlet', 'amount' => $refills],
        ];
        $inTotal = array_sum(array_column($in, 'amount'));
        $outTotal = array_sum(array_column($out, 'amount'));

        return ['period' => $p->toArray(), 'in' => $in, 'out' => $out, 'in_total' => $inTotal, 'out_total' => $outTotal, 'net' => $inTotal - $outTotal,
            'positions' => $this->positions()];
    }

    /** Uang yang belum sampai ke Finance atau belum dicocokkan, saat ini. @return array<string, mixed> */
    public function positions(): array
    {
        $atOutlets = 0;
        $shifts = 0;
        foreach (Location::query()->where('type', Location::TYPE_OUTLET)->get() as $o) {
            $pending = $this->handovers->pendingShifts($o);
            $atOutlets += (int) $pending->sum('sales_cash');
            $shifts += $pending->count();
        }
        $tz = Period::tz();
        $unmatchedAmount = 0;
        $unmatchedDays = 0;
        $done = DB::table('noncash_reconciliations')->get(['business_date', 'payment_method_id'])
            ->map(fn ($r) => Carbon::parse($r->business_date)->toDateString().'|'.$r->payment_method_id)->flip();
        $methods = DB::table('payment_methods')->where('is_cash', false)->pluck('id');
        $dates = DB::table('sales')->where('business_date', '>=', now()->setTimezone($tz)->subDays(30)->toDateString())
            ->whereExists(fn ($q) => $q->from('sale_payments')->whereColumn('sale_payments.sale_id', 'sales.id')->where('is_cash', false))
            ->distinct()->pluck('business_date');
        foreach ($dates as $d) {
            $date = Carbon::parse($d)->toDateString();
            foreach ($methods as $m) {
                if ($done->has("{$date}|{$m}")) {
                    continue;
                }
                $amount = $this->recon->systemAmount($date, (int) $m);
                if ($amount !== 0) {
                    $unmatchedAmount += $amount;
                    $unmatchedDays++;
                }
            }
        }

        return [
            'sales_cash_at_outlets' => $atOutlets,
            'shifts_not_handed_over' => $shifts,
            'in_transit_to_warehouse' => (int) CashHandover::query()->where(['stage' => CashHandover::STAGE_OUTLET, 'status' => CashHandover::STATUS_SENT])->sum('reported_amount'),
            'at_warehouse' => (int) $this->handovers->pendingAtWarehouse()->sum('received_amount'),
            'in_transit_to_finance' => (int) CashHandover::query()->where(['stage' => CashHandover::STAGE_WAREHOUSE, 'status' => CashHandover::STATUS_SENT])->sum('reported_amount'),
            'noncash_unmatched' => $unmatchedAmount,
            'noncash_unmatched_count' => $unmatchedDays,
        ];
    }

    /** Ringkasan tutup shift, serah terima per tahap, pencocokan non-tunai, dan keputusan selisih per kategori. @return array<string, mixed> */
    public function reconciliation(Period $p): array
    {
        $between = [$p->from, $p->to];
        $shift = DB::table('cashier_shifts')->where('status', CashierShift::STATUS_CLOSED)->whereRaw(Period::dateOf('closed_at').' BETWEEN ? AND ?', $between)
            ->selectRaw('count(*) n, sum(expected_cash) expected, sum(counted_cash) counted, sum(cash_difference) diff, sum(CASE WHEN cash_difference <> 0 THEN 1 ELSE 0 END) diff_n')->first();
        $stages = [];
        foreach ([CashHandover::STAGE_OUTLET => 'Outlet → gudang', CashHandover::STAGE_WAREHOUSE => 'Gudang → Finance'] as $stage => $label) {
            $h = DB::table('cash_handovers')->where(['stage' => $stage, 'status' => CashHandover::STATUS_RECEIVED])->whereRaw(Period::dateOf('received_at').' BETWEEN ? AND ?', $between)
                ->selectRaw('count(*) n, sum(expected_amount) expected, sum(reported_amount) reported, sum(received_amount) received,
                    sum(CASE WHEN received_amount <> expected_amount THEN 1 ELSE 0 END) diff_n')->first();
            $stages[] = ['stage' => $stage, 'label' => $label, 'count' => (int) $h->n, 'expected' => (int) $h->expected, 'reported' => (int) $h->reported,
                'received' => (int) $h->received, 'difference' => (int) $h->received - (int) $h->expected, 'with_difference' => (int) $h->diff_n];
        }
        $noncash = DB::table('noncash_reconciliations as n')->join('payment_methods as m', 'm.id', '=', 'n.payment_method_id')->whereBetween('n.business_date', $between)
            ->groupBy('m.id', 'm.name')->selectRaw('m.id, m.name, count(*) days, sum(n.system_amount) system, sum(n.bank_amount) bank, sum(n.difference) diff')
            ->orderBy('m.name')->get()
            ->map(fn ($r) => ['method' => ['id' => (int) $r->id, 'name' => $r->name], 'days' => (int) $r->days, 'system' => (int) $r->system, 'bank' => (int) $r->bank, 'difference' => (int) $r->diff])->all();

        $categories = ['OPEN' => ['count' => 0, 'amount' => 0], 'STAFF' => ['count' => 0, 'amount' => 0], 'BUSINESS' => ['count' => 0, 'amount' => 0],
            'RECORDING_ERROR' => ['count' => 0, 'amount' => 0], 'NONE' => ['count' => 0, 'amount' => 0]];
        $list = [];
        foreach ($this->recon->discrepancies() as $d) {
            $date = Carbon::parse($d['at'])->setTimezone(Period::tz())->toDateString();
            if ($date < $p->from || $date > $p->to) {
                continue;
            }
            $key = $d['resolution'] === null ? 'OPEN' : ($d['resolution']['category'] ?? 'NONE');
            $categories[$key]['count']++;
            $categories[$key]['amount'] += $d['amount'];
            $list[] = $d;
        }

        return [
            'period' => $p->toArray(),
            'shifts' => ['count' => (int) $shift->n, 'expected' => (int) $shift->expected, 'counted' => (int) $shift->counted, 'difference' => (int) $shift->diff, 'with_difference' => (int) $shift->diff_n],
            'handovers' => $stages,
            'noncash' => $noncash,
            'categories' => $categories,
            'discrepancies' => $list,
            'positions' => $this->positions(),
        ];
    }
}
