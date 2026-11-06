<?php

namespace App\Modules\Approval\Models;

use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Delegasi sementara yang tercatat (2E-Q2; keputusan Owner 6 Nov 2026): pemberi memilih satu
 * penerima, periode, dan jenis persetujuan; dapat dicabut kapan saja. Akun tidak dipakai bersama.
 *
 * @property int $id
 * @property string $number
 * @property int $delegator_id
 * @property int $delegator_role_id
 * @property int $delegate_id
 * @property list<string> $types
 */
class ApprovalDelegation extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'types' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'revoked_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function delegator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegator_id');
    }

    public function delegate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegate_id');
    }

    public function delegatorRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'delegator_role_id');
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    /** Delegasi yang sedang berlaku sekarang. */
    public function scopeActive(Builder $query): Builder
    {
        $now = now();

        return $query->whereNull('revoked_at')->where('starts_at', '<=', $now)->where('ends_at', '>', $now);
    }

    public function status(): string
    {
        $now = now();

        return match (true) {
            $this->revoked_at !== null => 'REVOKED',
            $this->ends_at <= $now => 'EXPIRED',
            $this->starts_at > $now => 'SCHEDULED',
            default => 'ACTIVE',
        };
    }
}
