<?php

namespace App\Modules\Audit\Services;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Satu-satunya pintu untuk membuat catatan audit (10 v1.0; dokumen 12 §4.4).
 * Dipanggil di dalam transaksi database yang sama dengan perubahan bisnisnya
 * (dokumen 12 P1), sehingga keduanya tersimpan bersama atau tidak sama sekali.
 */
class AuditLogger
{
    public const FLAG_OWNER_DIRECT = 'OWNER_DIRECT';
    public const FLAG_DELEGATED = 'DELEGATED';
    public const FLAG_FINANCE_SKIPPED = 'FINANCE_SKIPPED';
    public const FLAG_SINGLE_PERSON = 'SINGLE_PERSON';
    public const FLAG_SYSTEM = 'SYSTEM';

    /**
     * @param  string  $action  Jenis tindakan (U6), mis. USER_CREATED
     * @param  Model|null  $subject  Catatan yang ditindak (U7)
     * @param  array<string, mixed>|null  $changes  Nilai sebelum/sesudah atau isi (U8)
     * @param  string|null  $actingRole  Peran yang dipakai saat bertindak (U2)
     * @param  list<string>  $flags  Penanda (U11)
     * @param  bool  $withClient  Simpan alamat IP dan browser (hanya kejadian akses akun)
     * @param  bool  $system  Tindakan otomatis sistem (U1 = Sistem, penanda SYSTEM)
     */
    public function record(
        string $action,
        ?Model $subject = null,
        ?array $changes = null,
        ?string $actingRole = null,
        ?string $reason = null,
        array $flags = [],
        ?int $locationId = null,
        ?array $related = null,
        ?User $user = null,
        ?string $subjectCode = null,
        ?DateTimeInterface $occurredAt = null,
        bool $withClient = false,
        bool $system = false,
    ): AuditLog {
        $user ??= Auth::user();

        return AuditLog::query()->create([
            'occurred_at' => $occurredAt,
            'user_id' => $user?->getKey(),
            'role_id' => $actingRole ? Role::idFor($actingRole) : null,
            'location_id' => $locationId,
            'action' => $action,
            'subject_type' => $subject ? self::subjectType($subject) : null,
            'subject_id' => $subject?->getKey(),
            'subject_code' => $subjectCode ?? self::subjectCode($subject),
            'changes' => $changes,
            'reason' => $reason,
            'flags' => array_values(array_unique($system ? [...$flags, self::FLAG_SYSTEM] : $flags)),
            'related' => $related,
            'ip_address' => $withClient ? Request::ip() : null,
            'user_agent' => $withClient ? substr((string) Request::userAgent(), 0, 500) : null,
        ]);
    }

    /** Nama jenis catatan yang stabil, tidak bergantung pada nama kelas PHP. */
    public static function subjectType(Model $subject): string
    {
        return match (true) {
            $subject instanceof User => 'user',
            default => str($subject->getTable())->singular()->toString(),
        };
    }

    private static function subjectCode(?Model $subject): ?string
    {
        if ($subject === null) {
            return null;
        }

        return $subject->getAttribute('code') ?? $subject->getAttribute('username');
    }
}
