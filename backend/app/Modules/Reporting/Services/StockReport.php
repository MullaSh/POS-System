<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockTransferLine;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Sales\Models\SaleRefund;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Nilai stok, pergerakan stok, serta produksi dan waste/varians (C16, PV-06).
 * Semua jumlah dalam satuan dasar barang. Nilai memakai harga pokok rata-rata (KP-04) dari kartu stok.
 */
class StockReport
{
    private const ITEM_TYPES = ['RAW', 'SEMI_FINISHED', 'SUPPORTING', 'PACKAGING'];

    /**
     * Nilai stok pada akhir satu tanggal: saldo dan rata-rata dari catatan kartu stok terakhir
     * sampai tanggal itu. Stok minus dinilai apa adanya dan ditandai (keputusan irisan 9).
     *
     * @return array<string, mixed>
     */
    public function valuation(string $asOf, ?int $locationId): array
    {
        $last = DB::table('stock_movements')->selectRaw('DISTINCT ON (item_id, location_id) item_id, location_id, balance_after, avg_cost_after')
            ->where('business_date', '<=', $asOf)->orderBy('item_id')->orderBy('location_id')->orderByDesc('id');
        $rows = DB::query()->fromSub($last, 'last')
            ->join('items as i', 'i.id', '=', 'last.item_id')
            ->join('units as u', 'u.id', '=', 'i.base_unit_id')
            ->join('locations as loc', 'loc.id', '=', 'last.location_id')
            ->when($locationId, fn ($q) => $q->where('last.location_id', $locationId))
            ->where('last.balance_after', '<>', 0)
            ->orderByRaw("CASE WHEN loc.type = 'WAREHOUSE' THEN 0 ELSE 1 END")->orderBy('loc.code')->orderBy('i.item_type')->orderBy('i.code')
            ->get(['last.item_id', 'last.location_id', 'last.balance_after', 'last.avg_cost_after', 'i.code', 'i.name', 'i.item_type', 'u.code as unit', 'loc.code as loc_code', 'loc.name as loc_name']);

        $out = [];
        $byLocation = [];
        $byType = array_fill_keys(self::ITEM_TYPES, 0);
        foreach ($rows as $r) {
            $value = $r->avg_cost_after === null ? null : (int) Decimal::round(bcmul((string) $r->balance_after, (string) $r->avg_cost_after, 8), 0);
            $out[] = [
                'location' => ['id' => (int) $r->location_id, 'code' => $r->loc_code, 'name' => $r->loc_name],
                'item' => ['id' => (int) $r->item_id, 'code' => $r->code, 'name' => $r->name, 'type' => $r->item_type, 'unit' => $r->unit],
                'qty' => (string) $r->balance_after,
                'avg_cost' => $r->avg_cost_after === null ? null : (string) $r->avg_cost_after,
                'value' => $value,
                'negative' => Decimal::cmp((string) $r->balance_after, '0') < 0,
            ];
            $byLocation[$r->location_id] ??= ['location' => ['id' => (int) $r->location_id, 'code' => $r->loc_code, 'name' => $r->loc_name], 'value' => 0, 'by_type' => array_fill_keys(self::ITEM_TYPES, 0), 'no_cost' => 0, 'negative' => 0];
            $byLocation[$r->location_id]['value'] += $value ?? 0;
            $byLocation[$r->location_id]['by_type'][$r->item_type] = ($byLocation[$r->location_id]['by_type'][$r->item_type] ?? 0) + ($value ?? 0);
            $byLocation[$r->location_id]['no_cost'] += $value === null ? 1 : 0;
            $byLocation[$r->location_id]['negative'] += Decimal::cmp((string) $r->balance_after, '0') < 0 ? 1 : 0;
            $byType[$r->item_type] = ($byType[$r->item_type] ?? 0) + ($value ?? 0);
        }

        return [
            'as_of' => $asOf,
            'rows' => $out,
            'locations' => array_values($byLocation),
            'total' => ['value' => array_sum(array_column($byLocation, 'value')), 'by_type' => $byType,
                'no_cost' => array_sum(array_column($byLocation, 'no_cost')), 'negative' => array_sum(array_column($byLocation, 'negative'))],
        ];
    }

    /** Saldo awal, masuk/keluar per jenis, dan saldo akhir per barang per lokasi. @return array<string, mixed> */
    public function movements(Period $p, ?int $locationId): array
    {
        $before = date('Y-m-d', strtotime($p->from.' -1 day'));
        $opening = collect($this->valuation($before, $locationId)['rows'])->keyBy(fn ($r) => $r['item']['id'].'|'.$r['location']['id']);
        $closing = collect($this->valuation($p->to, $locationId)['rows'])->keyBy(fn ($r) => $r['item']['id'].'|'.$r['location']['id']);

        $moves = DB::table('stock_movements')->whereBetween('business_date', [$p->from, $p->to])
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->groupBy('item_id', 'location_id', 'type')->selectRaw('item_id, location_id, type, sum(qty) qty, sum(coalesce(value, 0)) value')->get();
        $keys = $opening->keys()->merge($closing->keys())->merge($moves->map(fn ($m) => $m->item_id.'|'.$m->location_id))->unique()->values();

        $meta = DB::table('items as i')->join('units as u', 'u.id', '=', 'i.base_unit_id')
            ->whereIn('i.id', $keys->map(fn ($k) => (int) explode('|', $k)[0])->unique()->all())->get(['i.id', 'i.code', 'i.name', 'i.item_type', 'u.code as unit'])->keyBy('id');
        $locs = DB::table('locations')->get(['id', 'code', 'name', 'type'])->keyBy('id');
        $grouped = $moves->groupBy(fn ($m) => $m->item_id.'|'.$m->location_id);

        $rows = [];
        foreach ($keys as $key) {
            [$itemId, $locId] = array_map('intval', explode('|', $key));
            $in = '0';
            $out = '0';
            $inValue = '0';
            $outValue = '0';
            $byType = [];
            foreach ($grouped->get($key, collect()) as $m) {
                $byType[$m->type] = (string) $m->qty;
                if (Decimal::cmp((string) $m->qty, '0') >= 0) {
                    $in = bcadd($in, (string) $m->qty, 4);
                    $inValue = bcadd($inValue, (string) $m->value, 4);
                } else {
                    $out = bcsub($out, (string) $m->qty, 4);
                    $outValue = bcsub($outValue, (string) $m->value, 4);
                }
            }
            $o = $opening->get($key);
            $c = $closing->get($key);
            $item = $meta->get($itemId);
            $loc = $locs->get($locId);
            $rows[] = [
                'location' => ['id' => $locId, 'code' => $loc->code ?? '', 'name' => $loc->name ?? ''],
                'item' => ['id' => $itemId, 'code' => $item->code ?? '', 'name' => $item->name ?? '', 'type' => $item->item_type ?? '', 'unit' => $item->unit ?? ''],
                'opening_qty' => $o['qty'] ?? '0.0000', 'opening_value' => $o['value'] ?? 0,
                'in_qty' => $in, 'in_value' => (int) Decimal::round($inValue, 0),
                'out_qty' => $out, 'out_value' => (int) Decimal::round($outValue, 0),
                'closing_qty' => $c['qty'] ?? '0.0000', 'closing_value' => $c['value'] ?? 0,
                'by_type' => $byType,
            ];
        }
        usort($rows, fn ($a, $b) => [$a['location']['code'], $a['item']['type'], $a['item']['code']] <=> [$b['location']['code'], $b['item']['type'], $b['item']['code']]);

        return [
            'period' => $p->toArray(),
            'rows' => $rows,
            'total' => [
                'opening_value' => array_sum(array_column($rows, 'opening_value')), 'in_value' => array_sum(array_column($rows, 'in_value')),
                'out_value' => array_sum(array_column($rows, 'out_value')), 'closing_value' => array_sum(array_column($rows, 'closing_value')),
            ],
        ];
    }

    /** Produksi selesai, waste per lokasi dan alasan, susut kiriman, selisih opname, porsi refund "sudah dibuat". @return array<string, mixed> */
    public function productionAndWaste(Period $p, ?int $locationId): array
    {
        $between = [$p->from, $p->to];
        $orders = ProductionOrder::query()->with(['outputItem.baseUnit', 'lines'])
            ->where('status', ProductionOrder::STATUS_COMPLETED)->whereRaw(Period::dateOf('completed_at').' BETWEEN ? AND ?', $between)
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))->orderBy('completed_at')->get();
        $production = $orders->map(fn (ProductionOrder $o) => [
            'id' => $o->id, 'number' => $o->number, 'completed_at' => $o->completed_at,
            'item' => ['id' => $o->output_item_id, 'code' => $o->outputItem?->code, 'name' => $o->outputItem?->name, 'unit' => $o->outputItem?->baseUnit?->code],
            'planned_qty' => (string) $o->planned_qty, 'good_qty' => (string) $o->good_qty, 'failed_qty' => (string) $o->failed_qty,
            'output_variance_pct' => $o->output_variance_pct === null ? null : (float) $o->output_variance_pct,
            'materials_over' => $o->lines->filter(fn ($l) => $l->variance_pct !== null && $o->tolerance_pct !== null && abs((float) $l->variance_pct) > (float) $o->tolerance_pct)->count(),
            'over_tolerance' => (bool) $o->over_tolerance, 'variance_note' => $o->variance_note,
            'total_cost' => $o->total_cost === null ? null : (int) Decimal::round((string) $o->total_cost, 0),
            'unit_cost' => $o->unit_cost === null ? null : (string) $o->unit_cost,
            'failed_value' => $o->failed_value === null ? null : (int) Decimal::round((string) $o->failed_value, 0),
            'cost_complete' => (bool) $o->cost_complete,
        ])->values()->all();

        $waste = DB::table('stock_movements as m')->join('stock_entries as e', function ($j) {
            $j->on('e.id', '=', 'm.source_id')->where('m.source_type', '=', 'stock_entry');
        })->join('items as i', 'i.id', '=', 'm.item_id')->join('units as u', 'u.id', '=', 'i.base_unit_id')->join('locations as loc', 'loc.id', '=', 'm.location_id')
            ->where('m.type', StockMovement::WASTE)->whereBetween('m.business_date', $between)
            ->when($locationId, fn ($q) => $q->where('m.location_id', $locationId))
            ->groupBy('loc.id', 'loc.name', 'e.reason_code', 'i.id', 'i.code', 'i.name', 'u.code')
            ->selectRaw('loc.id loc_id, loc.name loc_name, e.reason_code, i.id item_id, i.code, i.name, u.code unit, -sum(m.qty) qty, -sum(coalesce(m.value, 0)) value')
            ->orderBy('loc.name')->get()
            ->map(fn ($r) => ['location' => ['id' => (int) $r->loc_id, 'name' => $r->loc_name], 'reason' => $r->reason_code,
                'item' => ['id' => (int) $r->item_id, 'code' => $r->code, 'name' => $r->name, 'unit' => $r->unit],
                'qty' => (string) $r->qty, 'value' => (int) Decimal::round((string) $r->value, 0)])->all();

        $transit = DB::table('stock_transfer_lines as l')->join('stock_transfers as t', 't.id', '=', 'l.stock_transfer_id')
            ->join('items as i', 'i.id', '=', 'l.item_id')->join('locations as f', 'f.id', '=', 't.from_location_id')->join('locations as d', 'd.id', '=', 't.to_location_id')
            ->where('l.resolution', StockTransferLine::LOST_IN_TRANSIT)->whereRaw(Period::dateOf('l.resolved_at').' BETWEEN ? AND ?', $between)
            ->when($locationId, fn ($q) => $q->where(fn ($w) => $w->where('t.from_location_id', $locationId)->orWhere('t.to_location_id', $locationId)))
            ->orderBy('l.resolved_at')
            ->get(['t.id', 't.number', 'f.name as from_name', 'd.name as to_name', 'i.name as item', 'l.sent_qty', 'l.received_qty', 'l.unit_cost', 'l.resolution_note'])
            ->map(fn ($r) => ['transfer_id' => (int) $r->id, 'number' => $r->number, 'from' => $r->from_name, 'to' => $r->to_name, 'item' => $r->item,
                'qty' => bcsub((string) $r->sent_qty, (string) $r->received_qty, 4),
                'value' => (int) Decimal::round(bcmul(bcsub((string) $r->sent_qty, (string) $r->received_qty, 4), (string) ($r->unit_cost ?? '0'), 8), 0),
                'note' => $r->resolution_note])->all();

        $adjust = DB::table('stock_movements as m')->join('locations as loc', 'loc.id', '=', 'm.location_id')
            ->where('m.type', StockMovement::ADJUSTMENT)->whereBetween('m.business_date', $between)
            ->when($locationId, fn ($q) => $q->where('m.location_id', $locationId))
            ->groupBy('loc.id', 'loc.name')->selectRaw('loc.id, loc.name, sum(CASE WHEN m.qty < 0 THEN -coalesce(m.value, 0) ELSE 0 END) short_value, sum(CASE WHEN m.qty > 0 THEN coalesce(m.value, 0) ELSE 0 END) over_value')
            ->get()->map(fn ($r) => ['location' => ['id' => (int) $r->id, 'name' => $r->name],
                'short_value' => (int) Decimal::round((string) $r->short_value, 0), 'over_value' => (int) Decimal::round((string) $r->over_value, 0)])->all();

        $made = DB::table('sale_refund_lines as rl')->join('sale_refunds as r', 'r.id', '=', 'rl.sale_refund_id')->join('locations as loc', 'loc.id', '=', 'r.location_id')
            ->where('rl.condition', SaleRefund::CONDITION_MADE)->whereNotNull('r.applied_at')->whereRaw(Period::dateOf('r.applied_at').' BETWEEN ? AND ?', $between)
            ->when($locationId, fn ($q) => $q->where('r.location_id', $locationId))
            ->groupBy('loc.id', 'loc.name')->selectRaw('loc.id, loc.name, sum(rl.qty) qty, sum(coalesce(rl.hpp_total, 0)) value')
            ->get()->map(fn ($r) => ['location' => ['id' => (int) $r->id, 'name' => $r->name], 'portions' => (int) $r->qty, 'value' => (int) Decimal::round((string) $r->value, 0)])->all();

        return [
            'period' => $p->toArray(),
            'production' => $production,
            'production_total' => [
                'orders' => count($production), 'over_tolerance' => count(array_filter($production, fn ($o) => $o['over_tolerance'])),
                'total_cost' => array_sum(array_map(fn ($o) => $o['total_cost'] ?? 0, $production)),
                'failed_value' => array_sum(array_map(fn ($o) => $o['failed_value'] ?? 0, $production)),
            ],
            'waste' => $waste,
            'waste_total' => array_sum(array_column($waste, 'value')),
            'transit_loss' => $transit,
            'transit_total' => array_sum(array_column($transit, 'value')),
            'count_adjustments' => $adjust,
            'refund_made' => $made,
        ];
    }
}
