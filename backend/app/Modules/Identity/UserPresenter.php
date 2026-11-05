<?php

namespace App\Modules\Identity;

use App\Modules\Identity\Models\RoleAssignment;
use App\Modules\Identity\Models\User;

/**
 * Bentuk data user yang dikirim ke layar. Kata sandi tidak pernah dikirim.
 */
class UserPresenter
{
    /** @return array<string, mixed> */
    public static function present(User $user): array
    {
        $assignments = $user->roleAssignments()
            ->whereIn('status', [RoleAssignment::STATUS_ACTIVE, RoleAssignment::STATUS_PENDING])
            ->with(['role', 'locations'])
            ->orderBy('id')
            ->get();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'is_active' => $user->is_active,
            'must_change_password' => $user->must_change_password,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'roles' => $assignments->map(fn (RoleAssignment $a) => [
                'assignment_id' => $a->id,
                'code' => $a->role->code,
                'name' => $a->role->name,
                'scope' => $a->scope,
                'status' => $a->status,
                'locations' => $a->locations->map(fn ($l) => [
                    'id' => $l->id,
                    'code' => $l->code,
                    'name' => $l->name,
                    'type' => $l->type,
                ])->values(),
            ])->values(),
        ];
    }
}
