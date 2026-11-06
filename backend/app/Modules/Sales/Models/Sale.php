<?php

namespace App\Modules\Sales\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Location\Models\Location;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Transaksi penjualan yang sudah dibayar (C5, C7, C13). Append-only (3B-Q1):
 * void dan refund dicatat sebagai pembatalan terpisah (AB-24), bukan mengubah transaksi ini.
 *
 * @property int $id
 * @property string $number
 * @property int $location_id
 * @property int $shift_id
 * @property int $cashier_id
 * @property string $channel
 * @property int $total
 * @property int $paid_total
 * @property int $change_total
 * @property string|null $hpp_total
 * @property bool $hpp_complete
 */
class Sale extends Model
{
    public const CHANNEL_DINE_IN = 'DINE_IN';
    public const CHANNEL_TAKEAWAY = 'TAKEAWAY';
    public const CHANNELS = [self::CHANNEL_DINE_IN, self::CHANNEL_TAKEAWAY];

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'paid_at' => 'datetime',
            'subtotal' => 'integer',
            'discount_total' => 'integer',
            'tax_total' => 'integer',
            'total' => 'integer',
            'paid_total' => 'integer',
            'change_total' => 'integer',
            'hpp_complete' => 'boolean',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(CashierShift::class, 'shift_id');
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SaleLine::class)->orderBy('position');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class)->orderBy('position');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(SaleRefund::class)->orderBy('id');
    }
}
