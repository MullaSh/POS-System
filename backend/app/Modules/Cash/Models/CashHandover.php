<?php

namespace App\Modules\Cash\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Location\Models\Location;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Serah terima Sales Cash (C12, PV-04): Outlet → Gudang, lalu Gudang → Finance. */
class CashHandover extends Model
{
    public const STAGE_OUTLET = 'OUTLET_TO_WAREHOUSE';
    public const STAGE_WAREHOUSE = 'WAREHOUSE_TO_FINANCE';
    public const STATUS_SENT = 'SENT';
    public const STATUS_RECEIVED = 'RECEIVED';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime', 'received_at' => 'datetime',
            'expected_amount' => 'integer', 'reported_amount' => 'integer', 'received_amount' => 'integer',
        ];
    }

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'from_location_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'to_location_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CashHandoverItem::class)->orderBy('id');
    }

    /** Selisih aktual: diterima − seharusnya (PV-04). */
    public function difference(): ?int
    {
        return $this->received_amount === null ? null : $this->received_amount - $this->expected_amount;
    }
}
