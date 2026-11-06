<?php

namespace App\Modules\Sales\Models;

use App\Modules\Catalog\Models\Item;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bahan yang dipotong untuk satu baris penjualan (C7), dengan harga pokok saat transaksi.
 *
 * @property int $id
 * @property int $sale_line_id
 * @property int $item_id
 * @property string $qty
 * @property string|null $unit_cost
 */
class SaleLineComponent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
