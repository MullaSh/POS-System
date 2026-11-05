<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Location\Models\Location;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Siapa boleh melihat dan mengerjakan stok di lokasi mana (08; 2D-Q1; 2G-Q2, 2G-Q3):
 * - Owner dan Finance melihat stok semua lokasi, termasuk harga pokok dan nilai.
 * - Gudang mengerjakan stok gudang; kasir mengerjakan stok outlet yang ditugaskan. Keduanya
 *   melihat jumlah, bukan harga pokok.
 * - Owner bertindak operasional hanya lewat peran operasional yang juga ia pegang (2G-Q3).
 */
class StockAccess
{
    /** @return list<int>|null null = semua lokasi */
    public function viewableLocationIds(User $user): ?array
    {
        if ($this->canSeeCost($user)) {
            return null;
        }
        $warehouse = $user->locationIdsFor(Role::WAREHOUSE);
        $cashier = $user->locationIdsFor(Role::CASHIER);
        if ($warehouse === null || $cashier === null) {
            return null;
        }

        return array_values(array_unique([...$warehouse, ...$cashier]));
    }

    public function canView(User $user, int $locationId): bool
    {
        $ids = $this->viewableLocationIds($user);

        return $ids === null || in_array($locationId, $ids, true);
    }

    public function ensureCanView(User $user, int $locationId): void
    {
        if (! $this->canView($user, $locationId)) {
            throw new AuthorizationException('Anda tidak memiliki izin untuk melihat stok lokasi ini.');
        }
    }

    public function canSeeCost(User $user): bool
    {
        return $user->hasActiveRole(Role::OWNER) || $user->hasActiveRole(Role::FINANCE);
    }

    /** Peran operasional untuk lokasi: Gudang di gudang, Kasir di outlet. */
    public static function operatorRoleFor(Location $location): string
    {
        return $location->type === Location::TYPE_WAREHOUSE ? Role::WAREHOUSE : Role::CASHIER;
    }

    public function isOperator(User $user, Location $location): bool
    {
        return $user->hasRoleAt(self::operatorRoleFor($location), $location->id);
    }

    /** @return string Kode peran yang dipakai saat bertindak */
    public function ensureOperator(User $user, Location $location): string
    {
        $role = self::operatorRoleFor($location);
        if (! $user->hasRoleAt($role, $location->id)) {
            $who = $role === Role::WAREHOUSE ? 'staf gudang' : 'kasir outlet ini';
            throw new AuthorizationException("Hanya {$who} yang dapat melakukan tindakan ini di {$location->name}.");
        }

        return $role;
    }
}
