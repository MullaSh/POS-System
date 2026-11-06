<?php

namespace App\Modules\Sales\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Location\Models\Location;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Shift kasir (OD-11, OD-12, KOD-01; keputusan Owner 6 Nov 2026).
 * OPEN → (COUNTED bila hitung berselisih) → CLOSED.
 *
 * @property int $id
 * @property string $number
 * @property int $location_id
 * @property int $cashier_id
 * @property string $status
 * @property int $float_expected
 * @property int $float_counted
 * @property int|null $counted_cash
 * @property int|null $expected_cash
 * @property int|null $cash_difference
 * @property int|null $float_kept
 * @property int|null $sales_cash
 */
class CashierShift extends Model
{
    public const STATUS_OPEN = 'OPEN';
    public const STATUS_COUNTED = 'COUNTED';
    public const STATUS_CLOSED = 'CLOSED';

    public const RUNNING = [self::STATUS_OPEN, self::STATUS_COUNTED];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'counted_at' => 'datetime',
            'closed_at' => 'datetime',
            'float_expected' => 'integer',
            'float_counted' => 'integer',
            'counted_cash' => 'integer',
            'expected_cash' => 'integer',
            'cash_difference' => 'integer',
            'float_kept' => 'integer',
            'sales_cash' => 'integer',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class, 'shift_id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(SaleRefund::class, 'shift_id');
    }

    public function isRunning(): bool
    {
        return in_array($this->status, self::RUNNING, true);
    }
}
