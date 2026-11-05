<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Location\Models\Location;
use App\Modules\Recipe\Contracts\CostSource;
use App\Support\Decimal;

/**
 * Harga pokok rata-rata dari saldo stok (KP-04, AB-06), dipakai perhitungan HPP resep.
 * - Dengan lokasi: rata-rata barang di lokasi itu.
 * - Tanpa lokasi (halaman resep): rata-rata gudang pusat, karena semua barang outlet berasal
 *   dari gudang; bila gudang belum punya, rata-rata tertimbang dari lokasi yang punya stok.
 */
class InventoryCostSource implements CostSource
{
    public function unitCost(Item $item, ?int $locationId = null): ?string
    {
        if ($locationId !== null) {
            $cost = StockBalance::query()->where(['item_id' => $item->id, 'location_id' => $locationId])->value('avg_cost');

            return $cost === null ? null : (string) $cost;
        }

        $warehouseId = Location::query()->where('type', Location::TYPE_WAREHOUSE)->value('id');
        $cost = $warehouseId ? StockBalance::query()->where(['item_id' => $item->id, 'location_id' => $warehouseId])->value('avg_cost') : null;
        if ($cost !== null) {
            return (string) $cost;
        }

        $rows = StockBalance::query()->where('item_id', $item->id)->whereNotNull('avg_cost')->get(['qty', 'avg_cost']);
        $qty = '0';
        $value = '0';
        foreach ($rows as $r) {
            if (Decimal::cmp((string) $r->qty, '0') > 0) {
                $qty = bcadd($qty, (string) $r->qty, Decimal::WORK);
                $value = bcadd($value, bcmul((string) $r->qty, (string) $r->avg_cost, Decimal::WORK), Decimal::WORK);
            }
        }
        if (Decimal::cmp($qty, '0') > 0) {
            return Decimal::round(bcdiv($value, $qty, Decimal::WORK));
        }

        // Semua saldo nol atau minus: pakai harga terakhir yang diketahui.
        $last = $rows->first()?->avg_cost;

        return $last === null ? null : (string) $last;
    }
}
