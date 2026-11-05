<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $stock_transfer_id
 * @property int $item_id
 * @property string $factor
 * @property string $sent_qty
 * @property string|null $unit_cost
 * @property string|null $received_qty
 * @property string|null $resolution
 */
class StockTransferLine extends Model
{
    public const SHORT_SHIPPED = 'SHORT_SHIPPED';
    public const LOST_IN_TRANSIT = 'LOST_IN_TRANSIT';
    public const OVER_SENT = 'OVER_SENT';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /** Dikirim dikurangi diterima, atau null bila belum diterima. */
    public function difference(): ?string
    {
        return $this->received_qty === null ? null : bcsub((string) $this->sent_qty, (string) $this->received_qty, 4);
    }

    public function needsResolution(): bool
    {
        $d = $this->difference();

        return $d !== null && bccomp($d, '0', 4) !== 0 && $this->resolution === null;
    }
}
