<?php

namespace App\Modules\Recipe\Models;

use App\Modules\Catalog\Models\Unit;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Satu versi resep. Append-only (AB-08): perubahan selalu membuat versi baru.
 *
 * @property int $id
 * @property int $recipe_id
 * @property int $version_no
 * @property \Illuminate\Support\Carbon $effective_from
 * @property string $output_qty
 */
class RecipeVersion extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['recipe_id', 'version_no', 'effective_from', 'output_qty', 'output_unit_id', 'output_entered_qty', 'notes', 'created_by'];

    protected function casts(): array
    {
        return [
            'effective_from' => 'datetime',
            'created_at' => 'datetime',
            'output_qty' => 'decimal:4',
            'output_entered_qty' => 'decimal:4',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Versi resep tidak dapat diubah; buat versi baru.'));
        static::deleting(fn () => throw new LogicException('Versi resep tidak dapat dihapus.'));
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(RecipeVersionLine::class)->orderBy('position');
    }

    public function outputUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'output_unit_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
