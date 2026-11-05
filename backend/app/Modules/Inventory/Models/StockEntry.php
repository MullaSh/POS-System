<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Location\Models\Location;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Dokumen satu langkah: saldo awal (OPENING) atau barang rusak/dibuang (WASTE).
 * Isinya ada di kartu stok (stock_movements dengan source_type stock_entry). Append-only.
 *
 * @property int $id
 * @property string $number
 * @property string $kind
 * @property int $location_id
 * @property string|null $reason_code
 */
class StockEntry extends Model
{
    public const KIND_OPENING = 'OPENING';
    public const KIND_WASTE = 'WASTE';

    /** Alasan waste (Proposed; dapat diubah Owner). */
    public const WASTE_REASONS = ['DAMAGED', 'EXPIRED', 'SPILLED', 'QUALITY', 'OTHER'];

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['business_date' => 'date'];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'source_id')->where('source_type', 'stock_entry')->orderBy('id');
    }
}
