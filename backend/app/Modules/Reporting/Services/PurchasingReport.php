<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Laporan pembelian per supplier dan hutang supplier per umur jatuh tempo (PV-06; KP-11). */
class PurchasingReport
{
    public const DUE_SOON_DAYS = 7;

    /** @return array<string, mixed> */
    public function purchasing(Period $p): array
    {
        $between = [$p->from, $p->to];
        $poDate = Period::dateOf('po.created_at');
        $orders = DB::table('purchase_orders as po')->whereRaw("{$poDate} BETWEEN ? AND ?", $between)
            ->whereNotIn('po.status', [PurchaseOrder::STATUS_REJECTED, PurchaseOrder::STATUS_CANCELLED])
            ->groupBy('po.supplier_id')->selectRaw('po.supplier_id, count(*) n, sum(po.total) total')->get()->keyBy('supplier_id');
        $receipts = DB::table('goods_receipts as g')->join('purchase_orders as po', 'po.id', '=', 'g.purchase_order_id')
            ->whereRaw(Period::dateOf('g.received_at').' BETWEEN ? AND ?', $between)
            ->groupBy('po.supplier_id')->selectRaw('po.supplier_id, count(*) n, sum(g.accepted_value) accepted')->get()->keyBy('supplier_id');
        $rejects = DB::table('goods_receipt_lines as gl')->join('goods_receipts as g', 'g.id', '=', 'gl.goods_receipt_id')->join('purchase_orders as po', 'po.id', '=', 'g.purchase_order_id')
            ->join('purchase_lines as pl', 'pl.id', '=', 'gl.purchase_line_id')
            ->where('gl.qty_rejected', '>', 0)->whereRaw(Period::dateOf('g.received_at').' BETWEEN ? AND ?', $between)
            ->groupBy('po.supplier_id')->selectRaw('po.supplier_id, count(*) n, sum(gl.qty_rejected * coalesce(pl.unit_price, 0)) value')->get()->keyBy('supplier_id');
        $invoices = DB::table('supplier_invoices')->whereBetween('invoice_date', $between)
            ->groupBy('supplier_id')->selectRaw('supplier_id, count(*) n, sum(amount) amount, sum(amount - expected_amount) diff, sum(CASE WHEN amount <> expected_amount THEN 1 ELSE 0 END) diff_n')
            ->get()->keyBy('supplier_id');
        $paid = DB::table('supplier_payments as sp')->join('supplier_invoices as i', 'i.id', '=', 'sp.supplier_invoice_id')
            ->whereBetween('sp.paid_date', $between)->groupBy('i.supplier_id')->selectRaw('i.supplier_id, sum(sp.amount) amount')->get()->keyBy('supplier_id');

        $ids = collect([$orders, $receipts, $invoices, $paid])->flatMap(fn ($c) => $c->keys())->unique()->values();
        $suppliers = DB::table('suppliers')->whereIn('id', $ids)->get(['id', 'code', 'name'])->keyBy('id');
        $rows = $ids->map(fn ($id) => [
            'supplier' => ['id' => (int) $id, 'code' => $suppliers[$id]->code ?? '', 'name' => $suppliers[$id]->name ?? ''],
            'orders' => (int) ($orders[$id]->n ?? 0), 'ordered' => (int) ($orders[$id]->total ?? 0),
            'receipts' => (int) ($receipts[$id]->n ?? 0), 'received' => (int) ($receipts[$id]->accepted ?? 0),
            'rejected_lines' => (int) ($rejects[$id]->n ?? 0), 'rejected_value' => (int) round((float) ($rejects[$id]->value ?? 0)),
            'invoices' => (int) ($invoices[$id]->n ?? 0), 'invoiced' => (int) ($invoices[$id]->amount ?? 0),
            'price_difference' => (int) ($invoices[$id]->diff ?? 0), 'price_difference_count' => (int) ($invoices[$id]->diff_n ?? 0),
            'paid' => (int) ($paid[$id]->amount ?? 0),
        ])->sortByDesc('ordered')->values()->all();

        $differences = DB::table('supplier_invoices as i')->join('suppliers as s', 's.id', '=', 'i.supplier_id')
            ->whereBetween('i.invoice_date', $between)->whereColumn('i.amount', '<>', 'i.expected_amount')->orderBy('i.invoice_date')
            ->get(['i.id', 'i.number', 'i.invoice_no', 'i.invoice_date', 'i.expected_amount', 'i.amount', 'i.difference_note', 's.name as supplier'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'number' => $r->number, 'invoice_no' => $r->invoice_no, 'date' => $r->invoice_date, 'supplier' => $r->supplier,
                'expected' => (int) $r->expected_amount, 'amount' => (int) $r->amount, 'difference' => (int) $r->amount - (int) $r->expected_amount, 'note' => $r->difference_note])->all();

        $total = [];
        foreach (['orders', 'ordered', 'receipts', 'received', 'rejected_lines', 'rejected_value', 'invoices', 'invoiced', 'price_difference', 'paid'] as $k) {
            $total[$k] = array_sum(array_column($rows, $k));
        }
        $total['pending_requests'] = (int) DB::table('purchase_requests')->where('status', 'PENDING')->count();
        $total['open_orders'] = (int) DB::table('purchase_orders')->whereIn('status', [...PurchaseOrder::RECEIVABLE, PurchaseOrder::STATUS_WAITING_OWNER])->count();

        return ['period' => $p->toArray(), 'rows' => $rows, 'differences' => $differences, 'total' => $total];
    }

    /** Sisa tagihan per supplier, dikelompokkan menurut jatuh tempo terhadap hari ini. @return array<string, mixed> */
    public function payables(): array
    {
        $today = Period::today();
        $soon = Carbon::parse($today)->addDays(self::DUE_SOON_DAYS)->toDateString();
        $open = DB::table('supplier_invoices as i')->join('suppliers as s', 's.id', '=', 'i.supplier_id')
            ->leftJoinSub(DB::table('supplier_payments')->groupBy('supplier_invoice_id')->selectRaw('supplier_invoice_id, sum(amount) paid'), 'p', 'p.supplier_invoice_id', '=', 'i.id')
            ->whereRaw('i.amount - coalesce(p.paid, 0) > 0')->orderBy('i.due_date')
            ->get(['i.id', 'i.number', 'i.invoice_no', 'i.due_date', 'i.amount', DB::raw('coalesce(p.paid, 0) as paid'), 's.id as supplier_id', 's.name as supplier']);

        $buckets = ['overdue_30' => 0, 'overdue' => 0, 'due_soon' => 0, 'later' => 0];
        $bySupplier = [];
        $invoices = [];
        foreach ($open as $r) {
            $due = Carbon::parse($r->due_date)->toDateString();
            $left = (int) $r->amount - (int) $r->paid;
            $bucket = $due < Carbon::parse($today)->subDays(30)->toDateString() ? 'overdue_30' : ($due < $today ? 'overdue' : ($due <= $soon ? 'due_soon' : 'later'));
            $buckets[$bucket] += $left;
            $bySupplier[$r->supplier_id] ??= ['supplier' => ['id' => (int) $r->supplier_id, 'name' => $r->supplier], 'overdue_30' => 0, 'overdue' => 0, 'due_soon' => 0, 'later' => 0, 'total' => 0];
            $bySupplier[$r->supplier_id][$bucket] += $left;
            $bySupplier[$r->supplier_id]['total'] += $left;
            $invoices[] = ['id' => (int) $r->id, 'number' => $r->number, 'invoice_no' => $r->invoice_no, 'supplier' => $r->supplier, 'due_date' => $due,
                'amount' => (int) $r->amount, 'paid' => (int) $r->paid, 'outstanding' => $left, 'bucket' => $bucket,
                'days' => (int) Carbon::parse($today)->diffInDays(Carbon::parse($due), false)];
        }
        usort($bySupplier, fn ($a, $b) => $b['total'] <=> $a['total']);

        return ['as_of' => $today, 'due_soon_days' => self::DUE_SOON_DAYS, 'buckets' => $buckets, 'total' => array_sum($buckets),
            'suppliers' => array_values($bySupplier), 'invoices' => $invoices];
    }
}
