<?php

namespace App\Modules\Sales\Services;

use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Location\Models\Location;
use App\Modules\Sales\Models\CashierShift;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Siapa boleh melihat dan mengerjakan penjualan (2D-Q1, 2D-Q2, 2G-Q2, 2G-Q3):
 * - Kasir bekerja di outlet yang ditugaskan dan melihat semua transaksi di outlet itu;
 *   shift dan kas hanya miliknya sendiri.
 * - Owner dan Finance melihat semua outlet, termasuk HPP. Owner berjualan hanya bila juga
 *   memegang peran Kasir di outlet itu.
 */
class SalesAccess
{
    public function canSeeAll(User $user): bool
    {
        return $user->hasActiveRole(Role::OWNER) || $user->hasActiveRole(Role::FINANCE);
    }

    public function canSeeCost(User $user): bool
    {
        return $this->canSeeAll($user);
    }

    /** @return list<int>|null null = semua outlet */
    public function viewableLocationIds(User $user): ?array
    {
        if ($this->canSeeAll($user)) {
            return null;
        }

        return $this->cashierLocationIds($user);
    }

    /** @return list<int>|null null = semua outlet */
    public function cashierLocationIds(User $user): ?array
    {
        $ids = $user->locationIdsFor(Role::CASHIER);
        if ($ids === null && ! $user->hasActiveRole(Role::CASHIER)) {
            return [];
        }

        return $ids;
    }

    public function canViewLocation(User $user, int $locationId): bool
    {
        $ids = $this->viewableLocationIds($user);

        return $ids === null || in_array($locationId, $ids, true);
    }

    public function ensureCanViewLocation(User $user, int $locationId): void
    {
        if (! $this->canViewLocation($user, $locationId)) {
            throw new AuthorizationException('Anda tidak memiliki izin untuk melihat penjualan outlet ini.');
        }
    }

    public function isCashierAt(User $user, Location $location): bool
    {
        return $location->type === Location::TYPE_OUTLET && $user->hasRoleAt(Role::CASHIER, $location->id);
    }

    public function ensureCashierAt(User $user, Location $location): void
    {
        if ($location->type !== Location::TYPE_OUTLET) {
            throw ValidationException::withMessages(['location_id' => 'Penjualan hanya di outlet.']);
        }
        if (! $user->hasRoleAt(Role::CASHIER, $location->id)) {
            throw new AuthorizationException("Hanya kasir {$location->name} yang dapat melakukan tindakan ini.");
        }
    }

    /** Shift dan kas hanya dilihat pemiliknya, Owner, dan Finance (2D-Q2). */
    public function canViewShift(User $user, CashierShift $shift): bool
    {
        return $this->canSeeAll($user) || $shift->cashier_id === $user->id;
    }
}
