<?php

namespace App\Modules\Recipe\Contracts;

use App\Modules\Catalog\Models\Item;

/**
 * Sumber harga pokok rata-rata per satuan dasar (KP-04, AB-06).
 * Diisi modul Inventory dari saldo stok (InventoryCostSource).
 */
interface CostSource
{
    /** Harga pokok rata-rata per satuan dasar, atau null bila belum ada. */
    public function unitCost(Item $item, ?int $locationId = null): ?string;
}
