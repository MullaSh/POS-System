<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Location\Models\Location;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stok minimum opsional per barang per lokasi; hanya memicu peringatan (AB-07).
 */
class ItemMinStock extends Model
{
    protected $primaryKey = null;

    public $incrementing = false;

    protected $fillable = ['item_id', 'location_id', 'min_qty'];

    protected function casts(): array
    {
        return ['min_qty' => 'decimal:4'];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
