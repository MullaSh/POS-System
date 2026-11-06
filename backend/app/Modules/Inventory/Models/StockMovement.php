<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Catalog\Models\Item;
use App\Modules\Identity\Models\User;
use App\Modules\Location\Models\Location;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris kartu stok (AB-03). Append-only, dikunci trigger database.
 *
 * @property int $id
 * @property int $item_id
 * @property int $location_id
 * @property int|null $batch_id
 * @property string $type
 * @property string $qty
 * @property string|null $unit_cost
 * @property string|null $value
 * @property string $balance_after
 * @property string $source_type
 * @property int $source_id
 * @property string $source_number
 */
class StockMovement extends Model
{
    public const OPENING = 'OPENING';
    public const PURCHASE_RECEIPT = 'PURCHASE_RECEIPT';
    public const TRANSFER_OUT = 'TRANSFER_OUT';
    public const TRANSFER_IN = 'TRANSFER_IN';
    public const TRANSFER_CANCEL = 'TRANSFER_CANCEL';
    public const TRANSFER_SHORT_RETURN = 'TRANSFER_SHORT_RETURN';
    public const TRANSFER_OVER_SENT = 'TRANSFER_OVER_SENT';
    public const WASTE = 'WASTE';
    public const ADJUSTMENT = 'ADJUSTMENT';
    public const SALE = 'SALE';
    public const SALE_REVERSAL = 'SALE_REVERSAL';

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'business_date' => 'date'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'batch_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
