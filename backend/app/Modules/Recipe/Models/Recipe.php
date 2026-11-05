<?php

namespace App\Modules\Recipe\Models;

use App\Modules\Catalog\Models\Item;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Resep untuk satu barang hasil (C2). Isi resep ada di versinya (KP-05).
 *
 * @property int $id
 * @property string $kind
 * @property int $output_item_id
 */
class Recipe extends Model
{
    public const KIND_MENU = 'MENU';
    public const KIND_PRODUCTION = 'PRODUCTION';

    protected $fillable = ['kind', 'output_item_id', 'created_by'];

    public function outputItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'output_item_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(RecipeVersion::class);
    }

    /**
     * Versi yang berlaku pada waktu t: versi terbaru (id terbesar) yang effective_from <= t.
     * Keputusan terbaru selalu menang, sama seperti harga jual.
     */
    public function versionAt(?Carbon $at = null): ?RecipeVersion
    {
        return $this->versions()->where('effective_from', '<=', $at ?? now())->orderByDesc('id')->first();
    }
}
