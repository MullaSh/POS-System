<?php

namespace App\Modules\Sales\Models;

use App\Modules\Catalog\Models\Item;
use App\Modules\Recipe\Models\RecipeVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $sale_id
 * @property int $item_id
 * @property int $qty
 * @property int $unit_price
 * @property int $line_total
 * @property string|null $unit_hpp
 * @property string|null $hpp_total
 */
class SaleLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['qty' => 'integer', 'unit_price' => 'integer', 'line_total' => 'integer'];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function recipeVersion(): BelongsTo
    {
        return $this->belongsTo(RecipeVersion::class);
    }

    public function components(): HasMany
    {
        return $this->hasMany(SaleLineComponent::class)->orderBy('id');
    }

    public function refundLines(): HasMany
    {
        return $this->hasMany(SaleRefundLine::class);
    }
}
