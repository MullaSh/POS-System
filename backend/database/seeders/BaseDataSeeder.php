<?php

namespace Database\Seeders;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\RoleAssignment;
use App\Modules\Identity\Models\User;
use App\Modules\Location\Models\Location;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Data dasar: gudang pusat, satuan umum, dan akun Owner pertama.
 * Akun Owner memakai kata sandi sementara yang wajib diganti saat pertama masuk.
 */
class BaseDataSeeder extends Seeder
{
    public function run(AuditLogger $audit): void
    {
        DB::transaction(function () use ($audit) {
            $warehouse = Location::query()->firstOrCreate(
                ['type' => Location::TYPE_WAREHOUSE],
                ['code' => 'GDG', 'name' => 'Gudang Pusat', 'status' => Location::STATUS_ACTIVE, 'opened_at' => now()],
            );
            if ($warehouse->wasRecentlyCreated) {
                $audit->record('LOCATION_CREATED', $warehouse, ['code' => $warehouse->code, 'name' => $warehouse->name], system: true);
            }

            $units = [
                'g' => 'gram', 'kg' => 'kilogram', 'ml' => 'mililiter', 'l' => 'liter',
                'pcs' => 'pcs', 'pak' => 'pak', 'box' => 'box', 'porsi' => 'porsi', 'botol' => 'botol',
            ];
            foreach ($units as $code => $name) {
                Unit::query()->firstOrCreate(['code' => $code], ['name' => $name]);
            }

            $ownerRoleId = Role::idFor(Role::OWNER);
            $hasOwner = RoleAssignment::query()
                ->where('role_id', $ownerRoleId)
                ->where('status', RoleAssignment::STATUS_ACTIVE)
                ->exists();

            if (! $hasOwner) {
                $owner = User::query()->firstOrCreate(['username' => 'owner'], [
                    'name' => 'Pemilik Usaha',
                    'password' => env('OWNER_INITIAL_PASSWORD', 'GantiSegera123'),
                    'must_change_password' => true,
                ]);
                RoleAssignment::query()->create([
                    'user_id' => $owner->id,
                    'role_id' => $ownerRoleId,
                    'scope' => RoleAssignment::SCOPE_ALL,
                    'status' => RoleAssignment::STATUS_ACTIVE,
                    'confirmed_at' => now(),
                ]);
                $audit->record('OWNER_ACCOUNT_CREATED', $owner, ['username' => $owner->username], system: true);
            }
        });
    }
}
