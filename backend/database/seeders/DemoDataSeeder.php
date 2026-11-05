<?php

namespace Database\Seeders;

use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\RoleAssignment;
use App\Modules\Identity\Models\User;
use App\Modules\Location\Models\Location;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Data contoh untuk mencoba aplikasi (hanya bila SEED_DEMO=true).
 * Nama outlet dan barang adalah contoh, bukan data usaha sebenarnya.
 * Semua akun contoh memakai kata sandi: demo12345
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        if (User::query()->where('username', 'kasir1')->exists()) {
            return; // Data contoh sudah ada.
        }

        DB::transaction(function () {
            $outlet1 = Location::query()->firstOrCreate(['code' => 'OTL01'], [
                'name' => 'Outlet 1', 'type' => Location::TYPE_OUTLET, 'status' => Location::STATUS_ACTIVE, 'opened_at' => now(),
            ]);
            $outlet2 = Location::query()->firstOrCreate(['code' => 'OTL02'], [
                'name' => 'Outlet 2', 'type' => Location::TYPE_OUTLET, 'status' => Location::STATUS_ACTIVE, 'opened_at' => now(),
            ]);
            $warehouse = Location::query()->where('type', Location::TYPE_WAREHOUSE)->firstOrFail();

            $users = [
                ['finance1', 'Finance Contoh', Role::FINANCE, RoleAssignment::SCOPE_ALL, []],
                ['gudang1', 'Staf Gudang Contoh', Role::WAREHOUSE, RoleAssignment::SCOPE_SELECTED, [$warehouse->id]],
                ['kasir1', 'Kasir Outlet 1', Role::CASHIER, RoleAssignment::SCOPE_SELECTED, [$outlet1->id]],
                ['kasir2', 'Kasir Outlet 2', Role::CASHIER, RoleAssignment::SCOPE_SELECTED, [$outlet2->id]],
            ];

            foreach ($users as [$username, $name, $role, $scope, $locations]) {
                $user = User::query()->create(['username' => $username, 'name' => $name, 'password' => 'demo12345']);
                $assignment = RoleAssignment::query()->create([
                    'user_id' => $user->id,
                    'role_id' => Role::idFor($role),
                    'scope' => $scope,
                    'status' => RoleAssignment::STATUS_ACTIVE,
                    'confirmed_at' => now(),
                ]);
                $assignment->locations()->sync($locations);
            }

            // Akun Owner contoh memakai kata sandi yang sama agar mudah dicoba.
            User::query()->where('username', 'owner')->update([
                'password' => bcrypt('demo12345'),
                'must_change_password' => false,
            ]);

            $unit = fn (string $code) => Unit::query()->where('code', $code)->value('id');

            $items = [
                ['BB-AYAM', 'Daging Ayam Fillet', Item::TYPE_RAW, 'g', [['kg', 1000, true, false]]],
                ['BB-TEPUNG', 'Tepung Terigu', Item::TYPE_RAW, 'g', [['kg', 1000, true, false]]],
                ['SJ-DIMSUM', 'Frozen Dimsum', Item::TYPE_SEMI_FINISHED, 'pcs', [['pak', 20, false, false]]],
                ['SJ-LUMPIA', 'Frozen Lumpia Keju', Item::TYPE_SEMI_FINISHED, 'pcs', [['pak', 10, false, false]]],
                ['BP-MENTAI', 'Saus Mentai', Item::TYPE_SUPPORTING, 'ml', [['botol', 500, true, false], ['l', 1000, false, false]]],
                ['PK-BOWL', 'Paper Bowl', Item::TYPE_PACKAGING, 'pcs', [['pak', 50, true, false]]],
                ['MN-DIMSUM4', 'Dimsum Mentai 4 pcs', Item::TYPE_MENU, 'porsi', []],
            ];

            foreach ($items as [$code, $name, $type, $base, $conversions]) {
                $item = Item::query()->create([
                    'code' => $code, 'name' => $name, 'item_type' => $type, 'base_unit_id' => $unit($base),
                ]);
                $item->units()->create(['unit_id' => $unit($base), 'factor_to_base' => 1, 'is_usage_default' => true]);
                foreach ($conversions as [$u, $factor, $purchase, $usage]) {
                    $item->units()->create([
                        'unit_id' => $unit($u), 'factor_to_base' => $factor,
                        'is_purchase_default' => $purchase, 'is_usage_default' => $usage,
                    ]);
                }
            }
        });
    }
}
