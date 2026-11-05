<?php

namespace Database\Seeders;

use App\Modules\Approval\Models\ApprovalRequest;
use App\Modules\Approval\Services\ApprovalService;
use App\Modules\Catalog\Models\ExpenseCategory;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Supplier;
use App\Modules\Catalog\Services\PriceService;
use App\Modules\Recipe\Models\Recipe;
use App\Modules\Recipe\Services\RecipeService;
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
        // Setiap bagian hanya diisi sekali, sehingga database lama mendapat data contoh irisan baru.
        if (! User::query()->where('username', 'kasir1')->exists()) {
            $this->seedFoundation();
        }
        if (! Supplier::query()->exists()) {
            $this->seedMasterData();
        }
        if (! Recipe::query()->exists()) {
            $this->seedRecipes();
        }
    }

    private function seedFoundation(): void
    {
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

    /** Irisan 2: supplier, kategori biaya, harga menu, dan satu pengajuan harga untuk dicoba Owner. */
    private function seedMasterData(): void
    {
        DB::transaction(function () {
            Supplier::query()->create([
                'code' => 'SUP-AYAM', 'name' => 'Toko Daging Contoh', 'contact_name' => 'Pak Budi',
                'phone' => '0812-0000-0001', 'payment_term_days' => 14, 'bank_account' => 'BCA 0000000001 a.n. Toko Daging Contoh',
            ]);
            Supplier::query()->create([
                'code' => 'SUP-KEMASAN', 'name' => 'Grosir Kemasan Contoh', 'contact_name' => 'Bu Sari',
                'phone' => '0812-0000-0002', 'payment_term_days' => 0,
            ]);

            foreach ([['LISTRIK', 'Listrik & air'], ['GAS', 'Gas'], ['KEBERSIHAN', 'Kebersihan'], ['TRANSPORT', 'Transport'], ['PERLENGKAPAN', 'Perlengkapan kecil'], ['LAIN', 'Lain-lain']] as [$code, $name]) {
                ExpenseCategory::query()->create(['code' => $code, 'name' => $name]);
            }

            $porsi = Unit::query()->where('code', 'porsi')->value('id');
            $lumpia = Item::query()->firstOrCreate(['code' => 'MN-LUMPIA4'], [
                'name' => 'Lumpia Keju Original 4 pcs', 'item_type' => Item::TYPE_MENU, 'base_unit_id' => $porsi,
            ]);
            if (! $lumpia->units()->exists()) {
                $lumpia->units()->create(['unit_id' => $porsi, 'factor_to_base' => 1, 'is_usage_default' => true]);
            }

            $owner = User::query()->where('username', 'owner')->firstOrFail();
            $finance = User::query()->where('username', 'finance1')->firstOrFail();
            $dimsum = Item::query()->where('code', 'MN-DIMSUM4')->first();
            if ($dimsum) {
                app(PriceService::class)->setDirect($dimsum, ['price' => 25000, 'reason' => 'Harga contoh'], $owner);
            }
            app(ApprovalService::class)->submit(
                ApprovalRequest::TYPE_ITEM_PRICE, $lumpia, ['price' => 22000], 'Contoh pengajuan harga dari Finance', $finance, Role::FINANCE,
            );
        });
    }

    /** Irisan 3: contoh resep produksi dan resep menu. Takarannya contoh, bukan resep usaha. */
    private function seedRecipes(): void
    {
        $item = fn (string $code) => Item::query()->where('code', $code)->first();
        $unit = fn (string $code) => Unit::query()->where('code', $code)->value('id');
        $line = fn (string $code, string $u, $qty) => ['item_id' => $item($code)?->id, 'unit_id' => $unit($u), 'qty' => $qty];
        $owner = User::query()->where('username', 'owner')->firstOrFail();
        $service = app(RecipeService::class);

        foreach (['SJ-DIMSUM', 'MN-DIMSUM4', 'MN-LUMPIA4', 'BB-AYAM', 'BB-TEPUNG', 'BP-MENTAI', 'PK-BOWL', 'SJ-LUMPIA'] as $code) {
            if (! $item($code)) {
                return; // Data contoh barang sudah diubah; lewati resep contoh.
            }
        }

        DB::transaction(function () use ($service, $owner, $item, $unit, $line) {
            $service->create($item('SJ-DIMSUM'), [
                'output_unit_id' => $unit('pak'), 'output_qty' => 5, 'notes' => 'Resep contoh',
                'lines' => [$line('BB-AYAM', 'kg', 1.5), $line('BB-TEPUNG', 'kg', 1)],
            ], $owner);
            $service->create($item('MN-DIMSUM4'), [
                'notes' => 'Resep contoh',
                'lines' => [$line('SJ-DIMSUM', 'pcs', 4), $line('BP-MENTAI', 'ml', 30), $line('PK-BOWL', 'pcs', 1)],
            ], $owner);
            $service->create($item('MN-LUMPIA4'), [
                'notes' => 'Resep contoh',
                'lines' => [$line('SJ-LUMPIA', 'pcs', 4), $line('PK-BOWL', 'pcs', 1)],
            ], $owner);
        });
    }
}
