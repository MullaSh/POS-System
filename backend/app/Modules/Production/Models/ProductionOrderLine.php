<?php

namespace App\Modules\Production\Models;

use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu bahan Production Order. standard_qty dari resep × target (KP-06); actual_qty diisi saat
 * selesai dan tidak diubah lagi. Jumlah dalam satuan dasar bahan.
 *
 * @property int $id
 * @property int $item_id
 * @property string $standard_qty
 * @property string|null $actual_qty
 * @property string $factor
 */
class ProductionOrderLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
