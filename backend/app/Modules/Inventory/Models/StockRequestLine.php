<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property int $item_id @property string $qty @property int $unit_id @property string $factor */
class StockRequestLine extends Model
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
