<?php

namespace App\Modules\Audit\Models;

use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Location\Models\Location;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Satu catatan audit (10 v1.0 §3.3). Hanya dibuat lewat AuditLogger.
 * Tidak pernah diubah atau dihapus (3B-Q2); database juga menolaknya.
 */
class AuditLog extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
            'occurred_at' => 'datetime',
            'changes' => 'array',
            'limits' => 'array',
            'flags' => 'array',
            'related' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Catatan audit tidak dapat diubah.'));
        static::deleting(fn () => throw new LogicException('Catatan audit tidak dapat dihapus.'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
