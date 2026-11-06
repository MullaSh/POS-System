<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Cash\Models\DiscrepancyResolution;
use App\Modules\Cash\Models\Expense;
use App\Modules\Cash\Services\ReconciliationService;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockTransferLine;
use App\Modules\Location\Models\Location;
use App\Modules\Sales\Models\SaleRefund;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Laba kotor dan laba bersih per lokasi (C16; keputusan Owner irisan 9 butir 3–5, 6 Nov 2026).
 *
 *   Penjualan bersih = penjualan − void/refund (refund pada tanggal disetujui)
 *   HPP              = HPP terkunci saat jual − HPP porsi yang di-refund
 *   Laba kotor       = penjualan bersih − HPP
 *   Biaya operasional = biaya outlet/gudang/pusat menurut tanggal biaya (yang ditolak/dibatalkan tidak dihitung)
 *   Kerugian stok    = waste + susut kiriman + selisih opname + porsi refund "sudah dibuat"
 *                      (hasil gagal produksi tidak dihitung lagi: sudah masuk harga pokok hasil baik)
 *   Selisih lain     = beda harga tagihan supplier − selisih kas yang diputuskan "kerugian usaha"
 *   Laba bersih      = laba kotor − biaya operasional − kerugian stok − selisih lain
 *
 * Laba per outlet tidak menanggung biaya gudang/pusat (butir 5): biaya dan kerugian gudang serta
 * yang tidak berlokasi (pencocokan non-tunai) tampil di baris "Gudang & pusat", sehingga jumlah
 * semua baris = laba bersih usaha. Semua angka rupiah dibulatkan per baris; total = jumlah baris.
 */
class ProfitReport
{
    public const KEYS = [
        'sales_gross', 'refunds', 'net_sales', 'hpp_sales', 'hpp_refunded', 'hpp', 'gross_profit',
        'expenses', 'expenses_pending', 'waste', 'transit_loss', 'count_adjustment', 'refund_made', 'stock_loss',
        'price_difference', 'cash_business_loss', 'other_loss', 'net_profit',
    ];

    /** @return array{period: array{from: string, to: string}, rows: list<array<string, mixed>>, total: array<string, mixed>} */
    public function build(Period $p, ?int $locationId = null): array
    {
        $locations = Location::query()->orderByRaw("CASE WHEN type = 'WAREHOUSE' THEN 1 ELSE 0 END")->orderBy('code')->get();
        $warehouseId = $locations->firstWhere('type', Location::TYPE_WAREHOUSE)?->id;
        $raw = [];
        $add = function (?int $loc, string $key, $value, int $count = 0) use (&$raw, $warehouseId) {
            $loc ??= $warehouseId;
            $raw[$loc][$key] = bcadd((string) ($raw[$loc][$key] ?? '0'), (string) ($value ?? '0'), 4);
            if ($count) {
                $raw[$loc]['_'.$key.'_count'] = ($raw[$loc]['_'.$key.'_count'] ?? 0) + $count;
            }
        };
        $between = [$p->from, $p->to];

        foreach (DB::table('sales')->whereBetween('business_date', $between)
            ->groupBy('location_id')->selectRaw('location_id, count(*) n, sum(total) total, sum(coalesce(hpp_total, 0)) hpp, sum(CASE WHEN hpp_complete THEN 0 ELSE 1 END) incomplete')->get() as $r) {
            $add($r->location_id, 'sales_gross', $r->total);
            $add($r->location_id, 'hpp_sales', $r->hpp);
            $raw[$r->location_id]['_transactions'] = (int) $r->n;
            $raw[$r->location_id]['_hpp_incomplete'] = (int) $r->incomplete;
        }

        $refundDate = Period::dateOf('r.applied_at');
        foreach (DB::table('sale_refunds as r')->whereNotNull('r.applied_at')->whereRaw("{$refundDate} BETWEEN ? AND ?", $between)
            ->groupBy('r.location_id')->selectRaw('r.location_id, count(*) n, sum(r.amount) amount')->get() as $r) {
            $add($r->location_id, 'refunds', $r->amount, (int) $r->n);
        }
        foreach (DB::table('sale_refund_lines as l')->join('sale_refunds as r', 'r.id', '=', 'l.sale_refund_id')
            ->whereNotNull('r.applied_at')->whereRaw("{$refundDate} BETWEEN ? AND ?", $between)
            ->groupBy('r.location_id', 'l.condition')->selectRaw('r.location_id, l.condition, sum(coalesce(l.hpp_total, 0)) hpp')->get() as $r) {
            $add($r->location_id, 'hpp_refunded', $r->hpp);
            if ($r->condition === SaleRefund::CONDITION_MADE) {
                $add($r->location_id, 'refund_made', $r->hpp);
            }
        }

        foreach (DB::table('expenses')->whereIn('status', [Expense::STATUS_RECORDED, Expense::STATUS_VERIFIED])->whereBetween('business_date', $between)
            ->groupBy('location_id')->selectRaw("location_id, sum(amount) amount, sum(CASE WHEN status = 'RECORDED' THEN amount ELSE 0 END) pending, sum(CASE WHEN status = 'RECORDED' THEN 1 ELSE 0 END) pending_n")->get() as $r) {
            $add($r->location_id, 'expenses', $r->amount);
            $add($r->location_id, 'expenses_pending', $r->pending, (int) $r->pending_n);
        }

        foreach (DB::table('stock_movements')->whereIn('type', [StockMovement::WASTE, StockMovement::ADJUSTMENT])->whereBetween('business_date', $between)
            ->groupBy('location_id', 'type')->selectRaw('location_id, type, sum(coalesce(value, 0)) value')->get() as $r) {
            // Nilai barang keluar tercatat negatif; kerugian ditulis positif.
            $add($r->location_id, $r->type === StockMovement::WASTE ? 'waste' : 'count_adjustment', bcmul((string) $r->value, '-1', 4));
        }

        foreach (DB::table('stock_transfer_lines as l')->join('stock_transfers as t', 't.id', '=', 'l.stock_transfer_id')
            ->where('l.resolution', StockTransferLine::LOST_IN_TRANSIT)->whereRaw(Period::dateOf('l.resolved_at').' BETWEEN ? AND ?', $between)
            ->groupBy('t.from_location_id')->selectRaw('t.from_location_id loc, sum((l.sent_qty - l.received_qty) * coalesce(l.unit_cost, 0)) value')->get() as $r) {
            $add($r->loc, 'transit_loss', $r->value);
        }

        foreach (DB::table('supplier_invoices as i')->join('goods_receipts as g', 'g.id', '=', 'i.goods_receipt_id')
            ->whereBetween('i.invoice_date', $between)->groupBy('g.location_id')
            ->selectRaw('g.location_id, sum(i.amount - i.expected_amount) diff, sum(CASE WHEN i.amount <> i.expected_amount THEN 1 ELSE 0 END) n')->get() as $r) {
            $add($r->location_id, 'price_difference', $r->diff, (int) $r->n);
        }

        foreach ($this->businessDiscrepancies($p) as [$loc, $amount]) {
            $add($loc, 'cash_business_loss', -$amount, 1);
        }

        $rows = [];
        foreach ($locations as $loc) {
            if ($locationId !== null && $loc->id !== $locationId) {
                continue;
            }
            $r = $raw[$loc->id] ?? [];
            $v = fn (string $k) => (int) Decimal::round((string) ($r[$k] ?? '0'), 0);
            $row = [
                'sales_gross' => $v('sales_gross'), 'refunds' => $v('refunds'),
                'hpp_sales' => $v('hpp_sales'), 'hpp_refunded' => $v('hpp_refunded'),
                'expenses' => $v('expenses'), 'expenses_pending' => $v('expenses_pending'),
                'waste' => $v('waste'), 'transit_loss' => $v('transit_loss'), 'count_adjustment' => $v('count_adjustment'), 'refund_made' => $v('refund_made'),
                'price_difference' => $v('price_difference'), 'cash_business_loss' => $v('cash_business_loss'),
            ];
            $row = $this->derive($row);
            $hasData = array_filter($row) !== [];
            if ($loc->status === Location::STATUS_CLOSED && ! $hasData) {
                continue;
            }
            $rows[] = [
                'location' => ['id' => $loc->id, 'code' => $loc->code, 'name' => $loc->type === Location::TYPE_WAREHOUSE ? 'Gudang & pusat' : $loc->name, 'type' => $loc->type],
                ...$row,
                'transactions' => $r['_transactions'] ?? 0,
                'refund_count' => $r['_refunds_count'] ?? 0,
                'hpp_incomplete' => $r['_hpp_incomplete'] ?? 0,
                'expenses_pending_count' => $r['_expenses_pending_count'] ?? 0,
                'price_difference_count' => $r['_price_difference_count'] ?? 0,
                'cash_business_count' => $r['_cash_business_loss_count'] ?? 0,
            ];
        }

        $total = [];
        foreach ([...self::KEYS, 'transactions', 'refund_count', 'hpp_incomplete', 'expenses_pending_count', 'price_difference_count', 'cash_business_count'] as $k) {
            $total[$k] = array_sum(array_column($rows, $k));
        }

        return ['period' => $p->toArray(), 'rows' => $rows, 'total' => $total];
    }

    /** @param  array<string, int>  $r  @return array<string, int> */
    private function derive(array $r): array
    {
        $r['net_sales'] = $r['sales_gross'] - $r['refunds'];
        $r['hpp'] = $r['hpp_sales'] - $r['hpp_refunded'];
        $r['gross_profit'] = $r['net_sales'] - $r['hpp'];
        $r['stock_loss'] = $r['waste'] + $r['transit_loss'] + $r['count_adjustment'] + $r['refund_made'];
        $r['other_loss'] = $r['price_difference'] + $r['cash_business_loss'];
        $r['net_profit'] = $r['gross_profit'] - $r['expenses'] - $r['stock_loss'] - $r['other_loss'];

        return array_merge(array_fill_keys(self::KEYS, 0), $r);
    }

    /**
     * Selisih kas yang diputuskan Finance sebagai "kerugian usaha", pada tanggal diputuskan.
     * Nilai bertanda: kurang = negatif (mengurangi laba), lebih = positif.
     *
     * @return list<array{0: int|null, 1: int}>
     */
    private function businessDiscrepancies(Period $p): array
    {
        $recon = app(ReconciliationService::class);
        $out = [];
        $resolutions = DiscrepancyResolution::query()->where('category', 'BUSINESS')
            ->whereRaw(Period::dateOf('created_at').' BETWEEN ? AND ?', [$p->from, $p->to])->get();
        foreach ($resolutions as $res) {
            $class = ReconciliationService::SUBJECTS[$res->subject_type] ?? null;
            $subject = $class ? $class::query()->find($res->subject_id) : null;
            if (! $subject) {
                continue;
            }
            $loc = match ($res->subject_type) {
                'cashier_shift', 'expense' => (int) $subject->location_id,
                // Selisih serah terima menjadi tanggungan lokasi pengirim (outlet pada tahap 1, gudang pada tahap 2).
                'cash_handover' => (int) $subject->from_location_id,
                default => null,
            };
            $out[] = [$loc, $recon->amount($res->subject_type, $subject)];
        }

        return $out;
    }
}
