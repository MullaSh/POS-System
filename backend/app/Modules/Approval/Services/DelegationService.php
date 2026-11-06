<?php

namespace App\Modules\Approval\Services;

use App\Modules\Approval\Models\ApprovalDelegation;
use App\Modules\Approval\Models\ApprovalRequest;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Support\DocumentNumber;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Delegasi sementara (2E-Q2; keputusan Owner 6 Nov 2026):
 * - Owner atau Finance memilih satu user aktif, periode mulai–selesai, dan jenis persetujuan
 *   yang dapat diputuskannya sendiri. Dapat dicabut kapan saja oleh pemberinya (atau Owner).
 * - Penerima tetap tidak boleh memutuskan pengajuannya sendiri (2F).
 * - Setiap keputusan lewat delegasi tercatat dengan penanda DELEGATED dan nomor delegasinya.
 */
class DelegationService
{
    /** @var array<int, list<string>> */
    private array $decidable = [];

    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'delegator_role' => ['required', 'in:'.Role::OWNER.','.Role::FINANCE],
            'delegate_id' => ['required', 'integer', 'exists:users,id'],
            'types' => ['required', 'array', 'min:1'],
            'types.*' => ['required', 'string', 'distinct'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Jenis persetujuan yang diputuskan oleh peran ini.
     *
     * @return list<string>
     */
    public function typesForRole(string $role): array
    {
        $service = app(ApprovalService::class);

        return array_values(array_filter(ApprovalService::TYPES, fn (string $t) => $service->handler($t)->approverRole() === $role));
    }

    /** @param  array<string, mixed>  $data */
    public function create(array $data, User $by): ApprovalDelegation
    {
        $role = $data['delegator_role'];
        if (! $by->hasActiveRole($role)) {
            throw new AuthorizationException('Anda hanya dapat mendelegasikan persetujuan dari peran yang Anda pegang.');
        }
        $allowed = $this->typesForRole($role);
        if ($allowed === []) {
            throw ValidationException::withMessages(['types' => 'Belum ada jenis persetujuan yang diputuskan peran ini.']);
        }
        foreach ($data['types'] as $i => $type) {
            if (! in_array($type, $allowed, true)) {
                throw ValidationException::withMessages(["types.{$i}" => 'Jenis persetujuan ini tidak diputuskan oleh peran Anda.']);
            }
        }
        /** @var User $delegate */
        $delegate = User::query()->findOrFail($data['delegate_id']);
        if ($delegate->id === $by->id) {
            throw ValidationException::withMessages(['delegate_id' => 'Pilih orang lain sebagai penerima delegasi.']);
        }
        if (! $delegate->is_active) {
            throw ValidationException::withMessages(['delegate_id' => "{$delegate->name} sedang nonaktif."]);
        }
        if ($delegate->activeAssignments()->isEmpty()) {
            throw ValidationException::withMessages(['delegate_id' => "{$delegate->name} belum memegang peran apa pun."]);
        }

        $now = now();
        $starts = isset($data['starts_at']) ? Carbon::parse($data['starts_at']) : $now;
        if ($starts->lessThan($now)) {
            $starts = $now;
        }
        $ends = Carbon::parse($data['ends_at']);
        if ($ends->lessThanOrEqualTo($starts)) {
            throw ValidationException::withMessages(['ends_at' => 'Waktu selesai harus sesudah waktu mulai.']);
        }
        $maxDays = (int) config('pos.max_delegation_days');
        if ($starts->diffInDays($ends) > $maxDays) {
            throw ValidationException::withMessages(['ends_at' => "Delegasi paling lama {$maxDays} hari."]);
        }

        return DB::transaction(function () use ($data, $by, $role, $delegate, $starts, $ends) {
            $delegation = ApprovalDelegation::query()->create([
                'number' => DocumentNumber::next('DLG'),
                'delegator_id' => $by->id,
                'delegator_role_id' => Role::idFor($role),
                'delegate_id' => $delegate->id,
                'types' => array_values($data['types']),
                'starts_at' => $starts,
                'ends_at' => $ends,
                'reason' => $data['reason'] ?? null,
            ]);
            $this->audit->record('DELEGATION_CREATED', $delegation, [
                'penerima' => $delegate->username,
                'jenis' => $delegation->types,
                'mulai' => $starts->toIso8601String(),
                'selesai' => $ends->toIso8601String(),
            ], $role, $data['reason'] ?? null, related: ['delegate_id' => $delegate->id], user: $by, subjectCode: $delegation->number);

            return $delegation;
        });
    }

    public function revoke(ApprovalDelegation $delegation, User $by, ?string $note): ApprovalDelegation
    {
        if ($delegation->delegator_id !== $by->id && ! $by->isOwner()) {
            throw new AuthorizationException('Hanya pemberi delegasi atau Owner yang dapat mencabutnya.');
        }

        return DB::transaction(function () use ($delegation, $by, $note) {
            $delegation = ApprovalDelegation::query()->whereKey($delegation->id)->lockForUpdate()->firstOrFail();
            if ($delegation->revoked_at !== null || $delegation->ends_at <= now()) {
                throw ValidationException::withMessages(['status' => 'Delegasi ini sudah tidak berlaku.']);
            }
            $delegation->update(['revoked_by' => $by->id, 'revoked_at' => now(), 'revoke_note' => $note]);
            $this->audit->record('DELEGATION_REVOKED', $delegation, null,
                $by->id === $delegation->delegator_id ? $delegation->delegatorRole->code : Role::OWNER,
                $note, related: ['delegate_id' => $delegation->delegate_id], user: $by, subjectCode: $delegation->number);

            return $delegation;
        });
    }

    /**
     * Delegasi yang berlaku bagi user ini untuk jenis persetujuan itu. Pemberinya harus masih
     * memegang peran pemutus (delegasi tidak memberi wewenang lebih dari yang dimiliki pemberi).
     */
    public function activeFor(User $user, string $type, string $approverRole): ?ApprovalDelegation
    {
        return ApprovalDelegation::query()->active()
            ->where('delegate_id', $user->id)
            ->where('delegator_role_id', Role::idFor($approverRole))
            ->whereJsonContains('types', $type)
            ->orderBy('id')
            ->get()
            ->first(fn (ApprovalDelegation $d) => $d->delegator?->is_active && $d->delegator->hasActiveRole($approverRole));
    }

    /**
     * Jenis persetujuan yang dapat diputuskan user ini, lewat perannya sendiri atau delegasi.
     *
     * @return list<string>
     */
    public function decidableTypes(User $user): array
    {
        if (isset($this->decidable[$user->id])) {
            return $this->decidable[$user->id];
        }
        $service = app(ApprovalService::class);
        $types = [];
        foreach (ApprovalService::TYPES as $type) {
            $role = $service->handler($type)->approverRole();
            if ($user->hasActiveRole($role) || $this->activeFor($user, $type, $role)) {
                $types[] = $type;
            }
        }

        return $this->decidable[$user->id] = $types;
    }

    /** Bisakah user ini memutuskan pengajuan ini sekarang? */
    public function canDecide(User $user, ApprovalRequest $request): bool
    {
        return $request->status === ApprovalRequest::STATUS_PENDING
            && $request->requested_by !== $user->id
            && in_array($request->type, $this->decidableTypes($user), true);
    }
}
