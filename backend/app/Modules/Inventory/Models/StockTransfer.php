<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Location\Models\Location;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pengiriman barang antar lokasi (AB-13):
 * - DISTRIBUTION: gudang → outlet, dikirim Warehouse, diterima Cashier.
 * - RETURN: outlet → gudang, dikirim Cashier, diterima Warehouse (G-03, keputusan Owner 5 Nov 2026).
 * Jumlah kirim dan terima dicatat terpisah. Selisih dijelaskan pengirim (OD-18).
 *
 * @property int $id
 * @property string $number
 * @property string $kind
 * @property int $from_location_id
 * @property int $to_location_id
 * @property int|null $stock_request_id
 * @property string $status
 * @property int $sent_by
 */
class StockTransfer extends Model
{
    public const KIND_DISTRIBUTION = 'DISTRIBUTION';
    public const KIND_RETURN = 'RETURN';

    public const IN_TRANSIT = 'IN_TRANSIT';
    public const RECEIVED = 'RECEIVED';
    public const CANCELLED = 'CANCELLED';

    /** Alasan retur outlet → gudang (Proposed). */
    public const RETURN_REASONS = ['EXCESS', 'DAMAGED', 'NEAR_EXPIRY', 'OTHER'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'received_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'from_location_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'to_location_id');
    }

    public function stockRequest(): BelongsTo
    {
        return $this->belongsTo(StockRequest::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockTransferLine::class)->orderBy('position');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
