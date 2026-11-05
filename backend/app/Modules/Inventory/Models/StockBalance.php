<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Catalog\Models\Item;
use App\Modules\Location\Models\Location;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Saldo terkini dan harga pokok rata-rata per barang per lokasi (dokumen 12 §5; KP-04).
 * Hanya diubah oleh StockLedger, dalam transaksi yang sama dengan kartu stok.
 *
 * @property int $id
 * @property int $item_id
 * @property int $location_id
 * @property string $qty
 * @property string|null $avg_cost
 */
class StockBalance extends Model
{
    protected $fillable = ['item_id', 'location_id', 'qty', 'avg_cost', 'last_movement_at'];

    protected function casts(): array
    {
        return ['last_movement_at' => 'datetime'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
