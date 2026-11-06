<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Cash\Models\Expense;
use App\Modules\Cash\Services\ReconciliationService;
use App\Modules\Production\Models\ProductionOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Isi beranda Owner (dokumen 11 §3.1 nomor 13; Master §4; urutan: keputusan Owner irisan 9 butir 7).
 * Persetujuan menunggu diambil layar dari ringkasan persetujuan yang sudah ada.
 * Daftar pengawasan mengikuti 10 §8.3 (2F-KK01–KK05 dan kejadian pengecualian Area 3).
 */
class DashboardService
{
    public const WATCH_DAYS = 7;

    /** Kelompok daftar pengawasan: jenis tindakan atau penanda di jejak audit. */
    public const WATCH_GROUPS = [
        'refunds' => ['actions' => ['SALE_REFUND_APPLIED']],
        'adjustments' => ['actions' => ['STOCK_ADJUSTMENT_APPLIED']],
        'waste' => ['actions' => ['STOCK_WASTE_RECORDED', 'STOCK_TRANSFER_DIFFERENCE_RESOLVED']],
        'cancellations' => ['actions' => ['PURCHASE_ORDER_CANCELLED', 'PURCHASE_ORDER_CLOSED', 'PURCHASE_REQUEST_CLOSED', 'PRODUCTION_CANCELLED', 'STOCK_TRANSFER_CANCELLED']],
        'blocked' => ['actions' => ['CONTROL_BLOCKED']],
        'access' => ['actions' => ['LOGIN_FAILED', 'LOGIN_REJECTED_INACTIVE', 'USER_PASSWORD_RESET']],
        'authority' => ['actions' => ['USER_CREATED', 'USER_UPDATED', 'DELEGATION_CREATED', 'DELEGATION_REVOKED', 'SETTING_CHANGED', 'PETTY_CASH_TARGET_SET', 'SUPPLIER_UPDATED']],
        'owner_direct' => ['flag' => 'OWNER_DIRECT'],
        'single_person' => ['flag' => 'SINGLE_PERSON'],
    ];

    public function __construct(
        private readonly SalesReport $sales,
        private readonly ProfitReport $profit,
        private readonly CashReport $cash,
        private readonly PurchasingReport $purchasing,
        private readonly ReconciliationService $recon,
    ) {}

    /** @return array<string, mixed> */
    public function build(): array
    {
        $today = Period::today();
        $month = Period::month($today);

        return [
            'today' => $today,
            'month' => $month->toArray(),
            'attention' => $this->attention($month),
            'sales' => $this->salesSummary($today, $month),
            'profit' => $this->profitSummary($month),
            'watch' => $this->watch(),
        ];
    }

    /** @return array<string, mixed> */
    private function attention(Period $month): array
    {
        $open = array_values(array_filter($this->recon->discrepancies(), fn ($d) => $d['resolution'] === null));

        $stock = DB::table('stock_balances as b')->join('items as i', 'i.id', '=', 'b.item_id')->join('units as u', 'u.id', '=', 'i.base_unit_id')
            ->join('locations as loc', 'loc.id', '=', 'b.location_id')
            ->leftJoin('item_min_stocks as ms', fn ($j) => $j->on('ms.item_id', '=', 'b.item_id')->on('ms.location_id', '=', 'b.location_id'))
            ->where('loc.status', '<>', 'CLOSED')
            ->where(fn ($q) => $q->where('b.qty', '<', 0)->orWhere(fn ($w) => $w->whereNotNull('ms.min_qty')->where('ms.min_qty', '>', 0)->whereColumn('b.qty', '<', 'ms.min_qty')))
            ->orderByRaw('CASE WHEN b.qty < 0 THEN 0 ELSE 1 END')->orderBy('loc.code')->orderBy('i.code')
            ->get(['i.id as item_id', 'i.name', 'u.code as unit', 'loc.id as loc_id', 'loc.name as loc_name', 'b.qty', 'ms.min_qty']);

        $over = ProductionOrder::query()->where(['status' => ProductionOrder::STATUS_COMPLETED, 'over_tolerance' => true])
            ->whereRaw(Period::dateOf('completed_at').' BETWEEN ? AND ?', [$month->from, $month->to])->count();
        $payables = $this->purchasing->payables();
        $diffs = DB::table('supplier_invoices')->whereBetween('invoice_date', [$month->from, $month->to])->whereColumn('amount', '<>', 'expected_amount')
            ->selectRaw('count(*) n, coalesce(sum(amount - expected_amount), 0) diff')->first();
        $positions = $this->cash->positions();

        return [
            'discrepancies' => ['count' => count($open), 'amount' => array_sum(array_column($open, 'amount'))],
            'stock' => [
                'negative' => $stock->filter(fn ($r) => (float) $r->qty < 0)->count(),
                'low' => $stock->filter(fn ($r) => (float) $r->qty >= 0)->count(),
                'items' => $stock->take(8)->map(fn ($r) => ['item_id' => (int) $r->item_id, 'name' => $r->name, 'unit' => $r->unit, 'location_id' => (int) $r->loc_id,
                    'location' => $r->loc_name, 'qty' => (string) $r->qty, 'min_qty' => $r->min_qty === null ? null : (string) $r->min_qty])->values()->all(),
            ],
            'production_over_tolerance' => $over,
            'payables' => [
                'overdue' => $payables['buckets']['overdue'] + $payables['buckets']['overdue_30'],
                'overdue_count' => count(array_filter($payables['invoices'], fn ($i) => str_starts_with($i['bucket'], 'overdue'))),
                'due_soon' => $payables['buckets']['due_soon'],
                'due_soon_count' => count(array_filter($payables['invoices'], fn ($i) => $i['bucket'] === 'due_soon')),
                'days' => PurchasingReport::DUE_SOON_DAYS,
            ],
            'price_differences' => ['count' => (int) $diffs->n, 'amount' => (int) $diffs->diff],
            'cash_at_warehouse' => $positions['at_warehouse'] + $positions['in_transit_to_finance'],
            'expenses_to_check' => (int) DB::table('expenses')->where(['paid_from' => Expense::PAID_PETTY, 'status' => Expense::STATUS_RECORDED])->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function salesSummary(string $today, Period $month): array
    {
        $day = $this->sales->byOutlet(new Period($today, $today), null);
        $mon = $this->sales->byOutlet($month, null);
        $dayBy = collect($day['rows'])->keyBy('location.id');
        $rows = array_map(fn ($r) => [
            'location' => $r['location'],
            'today' => $dayBy->get($r['location']['id'])['net_sales'] ?? 0,
            'today_transactions' => $dayBy->get($r['location']['id'])['transactions'] ?? 0,
            'month' => $r['net_sales'],
            'month_transactions' => $r['transactions'],
        ], $mon['rows']);

        return ['rows' => $rows, 'today' => $day['total']['net_sales'], 'month' => $mon['total']['net_sales']];
    }

    /** @return array<string, mixed> */
    private function profitSummary(Period $month): array
    {
        $t = $this->profit->build($month)['total'];

        return array_intersect_key($t, array_flip(['net_sales', 'hpp', 'gross_profit', 'expenses', 'stock_loss', 'other_loss', 'net_profit', 'hpp_incomplete', 'expenses_pending_count']));
    }

    /** @return array<string, mixed> */
    private function watch(): array
    {
        $since = now()->subDays(self::WATCH_DAYS);
        $groupOf = function (object $row): ?string {
            $flags = json_decode((string) $row->flags, true) ?: [];
            foreach (self::WATCH_GROUPS as $key => $g) {
                if (isset($g['actions']) && in_array($row->action, $g['actions'], true)) {
                    return $key;
                }
                if (isset($g['flag']) && in_array($g['flag'], $flags, true)) {
                    return $key;
                }
            }

            return null;
        };
        $actions = array_merge(...array_values(array_filter(array_map(fn ($g) => $g['actions'] ?? null, self::WATCH_GROUPS))));
        $rows = DB::table('audit_logs as a')->leftJoin('users as u', 'u.id', '=', 'a.user_id')->leftJoin('locations as loc', 'loc.id', '=', 'a.location_id')
            ->where('a.recorded_at', '>=', $since)
            ->where(fn ($q) => $q->whereIn('a.action', $actions)->orWhereRaw("a.flags @> '[\"OWNER_DIRECT\"]'::jsonb")->orWhereRaw("a.flags @> '[\"SINGLE_PERSON\"]'::jsonb"))
            ->orderByDesc('a.id')->limit(500)
            ->get(['a.id', 'a.recorded_at', 'a.action', 'a.flags', 'a.subject_code', 'a.reason', 'a.changes', 'u.name as user', 'loc.name as location']);

        $counts = array_fill_keys(array_keys(self::WATCH_GROUPS), 0);
        $latest = [];
        foreach ($rows as $r) {
            $g = $groupOf($r);
            if ($g === null) {
                continue;
            }
            $counts[$g]++;
            if (count($latest) < 12) {
                $changes = json_decode((string) $r->changes, true) ?: [];
                $latest[] = ['id' => (int) $r->id, 'at' => Carbon::parse($r->recorded_at), 'action' => $r->action, 'group' => $g,
                    'user' => $r->user, 'location' => $r->location, 'subject_code' => $r->subject_code,
                    'note' => $r->reason ?? ($changes['pesan'] ?? null),
                    'flags' => json_decode((string) $r->flags, true) ?: []];
            }
        }

        return ['days' => self::WATCH_DAYS, 'counts' => $counts, 'latest' => $latest];
    }
}
