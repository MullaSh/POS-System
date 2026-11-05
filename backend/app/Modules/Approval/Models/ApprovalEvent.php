<?php

namespace App\Modules\Approval\Models;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Riwayat tindakan pada satu pengajuan. Append-only, dikunci trigger database. */
class ApprovalEvent extends Model
{
    public const UPDATED_AT = null;

    public const SUBMITTED = 'SUBMITTED';
    public const RESUBMITTED = 'RESUBMITTED';
    public const APPROVED = 'APPROVED';
    public const REJECTED = 'REJECTED';
    public const RETURNED = 'RETURNED';
    public const CANCELLED = 'CANCELLED';

    protected $fillable = ['approval_request_id', 'action', 'user_id', 'note', 'payload'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Riwayat persetujuan tidak dapat diubah.'));
        static::deleting(fn () => throw new LogicException('Riwayat persetujuan tidak dapat dihapus.'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
