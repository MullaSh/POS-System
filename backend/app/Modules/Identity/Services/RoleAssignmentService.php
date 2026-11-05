<?php

namespace App\Modules\Identity\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\RoleAssignment;
use App\Modules\Identity\Models\User;
use App\Modules\Location\Models\Location;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Penugasan peran dan cakupan lokasi.
 *
 * Aturan:
 * - Peran Owner hanya untuk pemilik usaha dan tidak ditetapkan lewat aplikasi (2F-Q1, 2G-P1).
 * - Finance: semua lokasi (05 §6).
 * - Gudang: gudang pusat.
 * - Kasir: satu atau lebih outlet yang ditugaskan (2D-Q1).
 * - Penugasan oleh Owner langsung berlaku. Penugasan oleh selain Owner menunggu
 *   konfirmasi Owner (2F-Q3); di irisan 1 hanya Owner yang bisa menugaskan.
 */
class RoleAssignmentService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param  list<int>  $locationIds */
    public function assign(User $user, string $roleCode, array $locationIds, User $actor): RoleAssignment
    {
        if ($roleCode === Role::OWNER) {
            throw ValidationException::withMessages(['role' => 'Peran Owner hanya untuk pemilik usaha dan tidak dapat ditetapkan dari aplikasi.']);
        }

        if ($user->hasActiveRole($roleCode)) {
            throw ValidationException::withMessages(['role' => 'User ini sudah memegang peran tersebut.']);
        }

        [$scope, $locations] = $this->resolveScope($roleCode, $locationIds);

        return DB::transaction(function () use ($user, $roleCode, $scope, $locations, $actor) {
            $byOwner = $actor->isOwner();

            $assignment = RoleAssignment::query()->create([
                'user_id' => $user->id,
                'role_id' => Role::idFor($roleCode),
                'scope' => $scope,
                'status' => $byOwner ? RoleAssignment::STATUS_ACTIVE : RoleAssignment::STATUS_PENDING,
                'requested_by' => $actor->id,
                'confirmed_by' => $byOwner ? $actor->id : null,
                'confirmed_at' => $byOwner ? now() : null,
            ]);
            $assignment->locations()->sync($locations->pluck('id'));

            $this->audit->record(
                'ROLE_ASSIGNED',
                $assignment,
                [
                    'user' => $user->username,
                    'peran' => $roleCode,
                    'cakupan' => $scope,
                    'lokasi' => $locations->pluck('code')->values()->all(),
                    'status' => $assignment->status,
                ],
                $byOwner ? Role::OWNER : null,
                related: ['user_id' => $user->id],
            );

            return $assignment;
        });
    }

    public function revoke(RoleAssignment $assignment, User $actor): RoleAssignment
    {
        $assignment->loadMissing(['role', 'user']);

        if ($assignment->role->code === Role::OWNER) {
            throw ValidationException::withMessages(['role' => 'Peran Owner tidak dapat dicabut dari aplikasi.']);
        }
        if ($assignment->status === RoleAssignment::STATUS_REVOKED) {
            throw ValidationException::withMessages(['role' => 'Peran ini sudah dicabut.']);
        }

        return DB::transaction(function () use ($assignment, $actor) {
            $assignment->update([
                'status' => RoleAssignment::STATUS_REVOKED,
                'revoked_by' => $actor->id,
                'revoked_at' => now(),
            ]);

            $this->audit->record(
                'ROLE_REVOKED',
                $assignment,
                ['user' => $assignment->user->username, 'peran' => $assignment->role->code],
                Role::OWNER,
                related: ['user_id' => $assignment->user_id],
            );

            return $assignment;
        });
    }

    /**
     * @param  list<int>  $locationIds
     * @return array{0: string, 1: \Illuminate\Support\Collection<int, Location>}
     */
    private function resolveScope(string $roleCode, array $locationIds): array
    {
        if ($roleCode === Role::FINANCE) {
            return [RoleAssignment::SCOPE_ALL, collect()];
        }

        if ($roleCode === Role::WAREHOUSE) {
            $warehouse = Location::query()->where('type', Location::TYPE_WAREHOUSE)->first();
            if (! $warehouse) {
                throw ValidationException::withMessages(['location_ids' => 'Gudang pusat belum dibuat.']);
            }

            return [RoleAssignment::SCOPE_SELECTED, collect([$warehouse])];
        }

        // Kasir
        $locations = Location::query()->whereIn('id', $locationIds)->get();
        if ($locations->isEmpty()) {
            throw ValidationException::withMessages(['location_ids' => 'Pilih minimal satu outlet untuk kasir.']);
        }
        if ($locations->contains(fn (Location $l) => ! $l->isOutlet())) {
            throw ValidationException::withMessages(['location_ids' => 'Kasir hanya dapat ditugaskan di outlet.']);
        }
        if ($locations->contains(fn (Location $l) => $l->status === Location::STATUS_CLOSED)) {
            throw ValidationException::withMessages(['location_ids' => 'Outlet yang sudah ditutup tidak dapat dipilih.']);
        }

        return [RoleAssignment::SCOPE_SELECTED, $locations];
    }
}
