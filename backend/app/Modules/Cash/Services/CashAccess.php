<?php

namespace App\Modules\Cash\Services;

use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Location\Models\Location;

/**
 * Siapa melihat kas di mana (2D-Q2, 2G-Q2; G-07 keputusan Owner 6 Nov 2026):
 * Owner dan Finance melihat semua dana; Finance memantau. Kasir melihat dana outletnya;
 * gudang melihat kas yang ada di gudang.
 */
class CashAccess
{
    public function seesAll(User $user): bool
    {
        return $user->hasActiveRole(Role::OWNER) || $user->hasActiveRole(Role::FINANCE);
    }

    /** @return list<int>|null null = semua lokasi */
    public function locationIds(User $user): ?array
    {
        if ($this->seesAll($user)) {
            return null;
        }
        $ids = [];
        foreach ([Role::CASHIER, Role::WAREHOUSE] as $role) {
            $scoped = $user->locationIdsFor($role);
            if ($scoped === null && $user->hasActiveRole($role)) {
                $type = $role === Role::CASHIER ? Location::TYPE_OUTLET : Location::TYPE_WAREHOUSE;
                $scoped = Location::query()->where('type', $type)->pluck('id')->all();
            }
            $ids = [...$ids, ...($scoped ?? [])];
        }

        return array_values(array_unique($ids));
    }

    public function canSee(User $user, int $locationId): bool
    {
        $ids = $this->locationIds($user);

        return $ids === null || in_array($locationId, $ids, true);
    }
}
