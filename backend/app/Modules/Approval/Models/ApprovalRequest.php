<?php

namespace App\Modules\Approval\Models;

use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu pengajuan yang menunggu keputusan (06 Approval Framework).
 * Status berubah lewat tindakan; setiap tindakan tercatat di approval_events (append-only).
 *
 * @property int $id
 * @property string $number
 * @property string $type
 * @property string $status
 * @property string $subject_type
 * @property int $subject_id
 * @property array<string, mixed> $payload
 * @property string|null $reason
 * @property int $requested_by
 * @property int $requester_role_id
 * @property int|null $decided_by
 */
class ApprovalRequest extends Model
{
    public const STATUS_PENDING = 'PENDING';
    public const STATUS_RETURNED = 'RETURNED';
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_REJECTED = 'REJECTED';
    public const STATUS_CANCELLED = 'CANCELLED';

    public const OPEN_STATUSES = [self::STATUS_PENDING, self::STATUS_RETURNED];

    public const TYPE_ITEM_PRICE = 'ITEM_PRICE';

    protected $fillable = [
        'number', 'type', 'status', 'subject_type', 'subject_id', 'payload', 'reason',
        'requested_by', 'requester_role_id', 'submitted_at', 'decided_by', 'decided_at', 'decision_note',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function requesterRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'requester_role_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ApprovalEvent::class)->orderBy('id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }
}
