<?php

namespace Tests\Concerns;

use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\RoleAssignment;
use App\Modules\Identity\Models\User;
use App\Modules\Location\Models\Location;
use Laravel\Sanctum\Sanctum;

trait CreatesUsers
{
    protected function warehouse(): Location
    {
        return Location::query()->firstOrCreate(
            ['type' => Location::TYPE_WAREHOUSE],
            ['code' => 'GDG', 'name' => 'Gudang Pusat', 'status' => Location::STATUS_ACTIVE],
        );
    }

    protected function outlet(string $code = 'OTL01', string $status = Location::STATUS_ACTIVE): Location
    {
        return Location::query()->firstOrCreate(
            ['code' => $code],
            ['name' => "Outlet {$code}", 'type' => Location::TYPE_OUTLET, 'status' => $status],
        );
    }

    /** @param  list<int>  $locationIds */
    protected function userWithRole(string $role, array $locationIds = [], array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $assignment = RoleAssignment::query()->create([
            'user_id' => $user->id,
            'role_id' => Role::idFor($role),
            'scope' => $locationIds ? RoleAssignment::SCOPE_SELECTED : RoleAssignment::SCOPE_ALL,
            'status' => RoleAssignment::STATUS_ACTIVE,
            'confirmed_at' => now(),
        ]);
        $assignment->locations()->sync($locationIds);

        return $user;
    }

    protected function owner(array $attributes = []): User
    {
        return $this->userWithRole(Role::OWNER, [], $attributes);
    }

    protected function actingAsOwner(): User
    {
        $owner = $this->owner();
        Sanctum::actingAs($owner);

        return $owner;
    }
}
