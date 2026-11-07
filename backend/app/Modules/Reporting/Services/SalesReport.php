<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Location\Models\Location;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Penjualan per outlet dan HPP per menu (C16). Penjualan memakai tanggal usaha transaksi;
 * void/refund memakai tanggal disetujui (keputusan Owner irisan 9 butir 3).
 */
class SalesReport
{
    /** @return array<string, mixed> */
    public function byOutlet(Period $p, ?int $locationId): array
    {
        $between = [$p->from, $p->to];
        $refundDate = Period::dateOf('r.applied_at');
        $outlets = Location::query()->where('type', Location::TYPE_OUTLET)
            ->when($locationId, fn ($q) => $q->whereKey($locationId))->orderBy('code')->get();

        $sales = DB::table('sales')->whereBetween('business_date', $between)->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->groupBy('location_id')->selectRaw("location_id, count(*) n, sum(total) total,
                sum(CASE WHEN channel = 'DINE_IN' THEN total ELSE 0 END) dine_in, sum(CASE WHEN channel = 'TAKEAWAY' THEN total ELSE 0 END) takeaway")
            ->get()->keyBy('location_id');
        $refunds = DB::table('sale_refunds as r')->whereNotNull('r.applied_at')->whereRaw("{$refundDate} BETWEEN ? AND ?", $between)
            ->when($locationId, fn ($q) => $q->where('r.location_id', $locationId))
            ->groupBy('r.location_id')->selectRaw('r.location_id, count(*) n, sum(r.amount) amount')->get()->keyBy('location_id');

        $paid = DB::table('sale_payments as sp')->join('sales as s', 's.id', '=', 'sp.sale_id')->join('payment_methods as m', 'm.id', '=', 'sp.payment_method_id')
            ->whereBetween('s.business_date', $between)->when($locationId, fn ($q) => $q->where('s.location_id', $locationId))
            ->groupBy('s.location_id', 'm.id', 'm.name')->selectRaw('s.location_id, m.id method_id, m.name, sum(sp.amount) amount')->get();
        $returned = DB::table('sale_refund_payments as rp')->join('sale_refunds as r', 'r.id', '=', 'rp.sale_refund_id')->join('payment_methods as m', 'm.id', '=', 'rp.payment_method_id')
            ->whereNotNull('r.applied_at')->whereRaw("{$refundDate} BETWEEN ? AND ?", $between)->when($locationId, fn ($q) => $q->where('r.location_id', $locationId))
            ->groupBy('r.location_id', 'm.id', 'm.name')->selectRaw('r.location_id, m.id method_id, m.name, sum(rp.amount) amount')->get();
        $methods = [];
        foreach ($paid as $r) {
            $methods[$r->location_id][$r->method_id] = ['name' => $r->name, 'amount' => (int) $r->amount];
        }
        foreach ($returned as $r) {
            $methods[$r->location_id][$r->method_id] ??= ['name' => $r->name, 'amount' => 0];
            $methods[$r->location_id][$r->method_id]['amount'] -= (int) $r->amount;
        }

        $rows = [];
        foreach ($outlets as $o) {
            $s = $sales->get($o->id);
            $rf = $refunds->get($o->id);
            $n = (int) ($s->n ?? 0);
            $net = (int) ($s->total ?? 0) - (int) ($rf->amount ?? 0);
            if ($o->status === Location::STATUS_CLOSED && $n === 0 && ! $rf) {
                continue;
            }
            $rows[] = [
                'location' => $o->only(['id', 'code', 'name']),
                'transactions' => $n,
                'sales_gross' => (int) ($s->total ?? 0),
                'refunds' => (int) ($rf->amount ?? 0),
                'refund_count' => (int) ($rf->n ?? 0),
                'net_sales' => $net,
                'average' => $n > 0 ? intdiv((int) ($s->total ?? 0), $n) : 0,
                'dine_in' => (int) ($s->dine_in ?? 0),
                'takeaway' => (int) ($s->takeaway ?? 0),
                'methods' => array_values($methods[$o->id] ?? []),
            ];
        }

        $daily = [];
        foreach (DB::table('sales')->whereBetween('business_date', $between)->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->groupBy('business_date', 'location_id')->selectRaw('business_date d, location_id, sum(total) total')->get() as $r) {
            $daily[$r->d][$r->location_id] = ($daily[$r->d][$r->location_id] ?? 0) + (int) $r->total;
        }
        foreach (DB::table('sale_refunds as r')->whereNotNull('r.applied_at')->whereRaw("{$refundDate} BETWEEN ? AND ?", $between)
            ->when($locationId, fn ($q) => $q->where('r.location_id', $locationId))
            ->groupByRaw("{$refundDate}, r.location_id")->selectRaw("{$refundDate} d, r.location_id, sum(r.amount) amount")->get() as $r) {
            $daily[$r->d][$r->location_id] = ($daily[$r->d][$r->location_id] ?? 0) - (int) $r->amount;
        }
        ksort($daily);
        $days = [];
        foreach ($daily as $d => $byLoc) {
            $days[] = ['date' => (string) $d, 'by_location' => array_map(fn ($id, $v) => ['location_id' => $id, 'net_sales' => $v], array_keys($byLoc), $byLoc), 'net_sales' => array_sum($byLoc)];
        }

        $total = [];
        foreach (['transactions', 'sales_gross', 'refunds', 'refund_count', 'net_sales', 'dine_in', 'takeaway'] as $k) {
            $total[$k] = array_sum(array_column($rows, $k));
        }
        $total['average'] = $total['transactions'] > 0 ? intdiv($total['sales_gross'], $total['transactions']) : 0;

        return ['period' => $p->toArray(), 'rows' => $rows, 'total' => $total, 'daily' => $days];
    }

    /** HPP terkunci per menu (KP-05) dibanding pendapatannya. @return array<string, mixed> */
    public function hppByMenu(Period $p, ?int $locationId): array
    {
        $between = [$p->from, $p->to];
        $refundDate = Period::dateOf('r.applied_at');
        $sold = DB::table('sale_lines as l')->join('sales as s', 's.id', '=', 'l.sale_id')
            ->whereBetween('s.business_date', $between)->when($locationId, fn ($q) => $q->where('s.location_id', $locationId))
            ->groupBy('l.item_id')->selectRaw('l.item_id, sum(l.qty) qty, sum(l.line_total) revenue, sum(coalesce(l.hpp_total, 0)) hpp,
                sum(CASE WHEN l.hpp_total IS NULL OR NOT s.hpp_complete THEN 1 ELSE 0 END) incomplete')->get()->keyBy('item_id');
        $back = DB::table('sale_refund_lines as rl')->join('sale_refunds as r', 'r.id', '=', 'rl.sale_refund_id')->join('sale_lines as l', 'l.id', '=', 'rl.sale_line_id')
            ->whereNotNull('r.applied_at')->whereRaw("{$refundDate} BETWEEN ? AND ?", $between)->when($locationId, fn ($q) => $q->where('r.location_id', $locationId))
            ->groupBy('l.item_id')->selectRaw('l.item_id, sum(rl.qty) qty, sum(rl.amount) revenue, sum(coalesce(rl.hpp_total, 0)) hpp')->get()->keyBy('item_id');
        $ids = $sold->keys()->merge($back->keys())->unique()->all();
        $items = DB::table('items')->whereIn('id', $ids)->get(['id', 'code', 'name'])->keyBy('id');

        $rows = [];
        foreach ($ids as $id) {
            $s = $sold->get($id);
            $b = $back->get($id);
            $qty = (int) ($s->qty ?? 0) - (int) ($b->qty ?? 0);
            $revenue = (int) ($s->revenue ?? 0) - (int) ($b->revenue ?? 0);
            $hpp = (int) Decimal::round(bcsub((string) ($s->hpp ?? '0'), (string) ($b->hpp ?? '0'), 4), 0);
            $rows[] = [
                'item' => ['id' => (int) $id, 'code' => $items[$id]->code ?? '', 'name' => $items[$id]->name ?? ''],
                'qty' => $qty,
                'qty_refunded' => (int) ($b->qty ?? 0),
                'revenue' => $revenue,
                'hpp' => $hpp,
                'hpp_per_portion' => $qty > 0 ? (int) Decimal::round(bcdiv((string) $hpp, (string) $qty, 4), 0) : null,
                'gross_profit' => $revenue - $hpp,
                'margin_pct' => $revenue > 0 ? round(($revenue - $hpp) * 100 / $revenue, 1) : null,
                'incomplete' => (int) ($s->incomplete ?? 0),
            ];
        }
        usort($rows, fn ($a, $b) => $b['revenue'] <=> $a['revenue']);
        $total = ['qty' => array_sum(array_column($rows, 'qty')), 'revenue' => array_sum(array_column($rows, 'revenue')), 'hpp' => array_sum(array_column($rows, 'hpp'))];
        $total['gross_profit'] = $total['revenue'] - $total['hpp'];
        $total['margin_pct'] = $total['revenue'] > 0 ? round($total['gross_profit'] * 100 / $total['revenue'], 1) : null;
        $total['incomplete'] = array_sum(array_column($rows, 'incomplete'));

        return ['period' => $p->toArray(), 'rows' => $rows, 'total' => $total];
    }
}
