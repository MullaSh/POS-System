<?php

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Barang stok (4 kelompok) atau menu non-stok (KP-01, AB-01).
 * Jenis dan satuan dasar tidak diubah setelah dibuat.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $item_type
 * @property int $base_unit_id
 * @property bool $is_active
 */
class Item extends Model
{
    public const TYPE_RAW = 'RAW';
    public const TYPE_SEMI_FINISHED = 'SEMI_FINISHED';
    public const TYPE_SUPPORTING = 'SUPPORTING';
    public const TYPE_PACKAGING = 'PACKAGING';
    public const TYPE_MENU = 'MENU';

    public const TYPES = [
        self::TYPE_RAW, self::TYPE_SEMI_FINISHED, self::TYPE_SUPPORTING, self::TYPE_PACKAGING, self::TYPE_MENU,
    ];

    protected $fillable = ['code', 'name', 'item_type', 'base_unit_id', 'is_active', 'notes'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function isStockItem(): bool
    {
        return $this->item_type !== self::TYPE_MENU;
    }

    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }

    public function units(): HasMany
    {
        return $this->hasMany(ItemUnit::class);
    }

    public function minStocks(): HasMany
    {
        return $this->hasMany(ItemMinStock::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ItemPrice::class);
    }

    public function isMenu(): bool
    {
        return $this->item_type === self::TYPE_MENU;
    }
}
