<?php

namespace App\Modules\Recipe\Models;

use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Satu bahan dalam versi resep. qty dalam satuan dasar bahan. Append-only.
 *
 * @property int $item_id
 * @property string $qty
 */
class RecipeVersionLine extends Model
{
    public $timestamps = false;

    protected $fillable = ['recipe_version_id', 'item_id', 'qty', 'unit_id', 'entered_qty', 'position'];

    protected function casts(): array
    {
        return ['qty' => 'decimal:4', 'entered_qty' => 'decimal:4'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Isi resep tidak dapat diubah; buat versi baru.'));
        static::deleting(fn () => throw new LogicException('Isi resep tidak dapat dihapus.'));
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
