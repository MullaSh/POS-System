<?php

namespace App\Modules\Production\Models;

use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\StockRequest;
use App\Modules\Location\Models\Location;
use App\Modules\Recipe\Models\RecipeVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Production Order di gudang (KP-07, KP-08, AB-10): dibuat dan dijalankan gudang tanpa approval.
 * Dua langkah: rencana (PLANNED) → selesai (COMPLETED) dengan hasil, QC Production, dan pemakaian
 * bahan sebenarnya; atau dibatalkan (CANCELLED) dengan alasan. Stok berubah hanya saat selesai.
 *
 * @property int $id
 * @property string $number
 * @property string $status
 * @property int $location_id
 * @property int $output_item_id
 * @property int $created_by
 * @property string $planned_qty
 * @property string $factor
 */
class ProductionOrder extends Model
{
    public const STATUS_PLANNED = 'PLANNED';
    public const STATUS_COMPLETED = 'COMPLETED';
    public const STATUS_CANCELLED = 'CANCELLED';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'planned_date' => 'date', 'expiry_date' => 'date', 'completed_at' => 'datetime', 'cancelled_at' => 'datetime',
            'cost_complete' => 'boolean', 'over_tolerance' => 'boolean',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function outputItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'output_item_id');
    }

    public function recipeVersion(): BelongsTo
    {
        return $this->belongsTo(RecipeVersion::class);
    }

    public function stockRequest(): BelongsTo
    {
        return $this->belongsTo(StockRequest::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ProductionOrderLine::class)->orderBy('position');
    }

    public function failures(): HasMany
    {
        return $this->hasMany(ProductionFailure::class)->orderBy('id');
    }
}
