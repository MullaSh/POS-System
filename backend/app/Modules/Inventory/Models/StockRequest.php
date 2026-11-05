<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Location\Models\Location;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Permintaan stok outlet ke gudang (C8, KP-08, KP-09, AB-12). Dibuat kasir; dipenuhi gudang
 * lewat satu pengiriman, atau ditolak dengan alasan.
 *
 * @property int $id
 * @property string $number
 * @property int $location_id
 * @property string $status
 * @property int $requested_by
 */
class StockRequest extends Model
{
    public const SUBMITTED = 'SUBMITTED';
    public const IN_TRANSIT = 'IN_TRANSIT';
    public const COMPLETED = 'COMPLETED';
    public const REJECTED = 'REJECTED';
    public const CANCELLED = 'CANCELLED';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['needed_date' => 'date', 'closed_at' => 'datetime'];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockRequestLine::class)->orderBy('position');
    }

    public function transfer(): HasOne
    {
        return $this->hasOne(StockTransfer::class)->where('status', '<>', StockTransfer::CANCELLED);
    }
}
