<?php

namespace App\Modules\Recipe\Services;

use App\Modules\Catalog\Models\Item;
use App\Modules\Recipe\Contracts\CostSource;

/** Belum ada pembelian maupun saldo stok (sebelum irisan 4): semua harga pokok kosong. */
class NoCostYet implements CostSource
{
    public function unitCost(Item $item, ?int $locationId = null): ?string
    {
        return null;
    }
}
