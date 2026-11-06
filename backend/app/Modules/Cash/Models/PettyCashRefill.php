<?php

namespace App\Modules\Cash\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Location\Models\Location;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Isi ulang Petty Cash oleh Finance sebesar biaya yang terbukti (KP-14). Bisa diminta kasir lebih dulu. */
class PettyCashRefill extends Model
{
    public const STATUS_REQUESTED = 'REQUESTED';
    public const STATUS_DONE = 'DONE';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime', 'refilled_at' => 'datetime',
            'amount' => 'integer', 'verified_total' => 'integer', 'rejected_total' => 'integer',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function refiller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'refilled_by');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'refill_id')->orderBy('id');
    }
}
