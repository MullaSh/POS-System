<?php

namespace Database\Seeders;

use App\Modules\Approval\Models\ApprovalRequest;
use App\Modules\Approval\Services\ApprovalService;
use App\Modules\Catalog\Models\ExpenseCategory;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\ItemMinStock;
use App\Modules\Catalog\Models\Supplier;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Services\StockEntryService;
use App\Modules\Inventory\Services\StockRequestService;
use App\Modules\Inventory\Services\StockTransferService;
use App\Modules\Catalog\Services\PriceService;
use App\Modules\Recipe\Models\Recipe;
use App\Modules\Recipe\Services\RecipeService;
use App\Modules\Catalog\Models\PaymentMethod;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Approval\Services\DelegationService;
use App\Modules\Sales\Models\Sale;
use App\Modules\Cash\Models\Expense;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Services\PurchaseService;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Services\ProductionService;
use App\Modules\Cash\Services\PettyCashService;
use App\Modules\Catalog\Models\ExpenseCategory as DemoExpenseCategory;
use App\Modules\Sales\Services\RefundService;
use App\Modules\Sales\Services\SaleService;
use App\Modules\Sales\Services\ShiftService;
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
        if (! StockMovement::query()->exists()) {
            $this->seedStock();
        }
        if (! Sale::query()->exists()) {
            $this->seedSales();
        }
        if (! Expense::query()->exists()) {
            $this->seedCash();
        }
        if (! PurchaseRequest::query()->exists()) {
            $this->seedPurchasing();
        }
        if (! ProductionOrder::query()->exists()) {
            $this->seedProduction();
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

    /**
     * Irisan 4: saldo awal contoh (jumlah dan harga pokok contoh), satu permintaan stok dari
     * Outlet 1 untuk diproses gudang, dan satu kiriman ke Outlet 2 untuk diterima kasir2.
     */
    private function seedStock(): void
    {
        $item = fn (string $code) => Item::query()->where('code', $code)->first();
        $unit = fn (string $code) => Unit::query()->where('code', $code)->value('id');
        foreach (['BB-AYAM', 'BB-TEPUNG', 'SJ-DIMSUM', 'SJ-LUMPIA', 'BP-MENTAI', 'PK-BOWL'] as $code) {
            if (! $item($code)) {
                return;
            }
        }
        $owner = User::query()->where('username', 'owner')->firstOrFail();
        $gudang = User::query()->where('username', 'gudang1')->first();
        $kasir1 = User::query()->where('username', 'kasir1')->first();
        $warehouse = Location::query()->where('type', Location::TYPE_WAREHOUSE)->firstOrFail();
        $outlet1 = Location::query()->where('code', 'OTL01')->first();
        $outlet2 = Location::query()->where('code', 'OTL02')->first();
        if (! $gudang || ! $kasir1 || ! $outlet1 || ! $outlet2) {
            return;
        }
        $line = fn (string $code, string $u, $qty, $cost = null, array $extra = []) => [
            'item_id' => $item($code)->id, 'unit_id' => $unit($u), 'qty' => $qty, ...($cost === null ? [] : ['unit_cost' => $cost]), ...$extra,
        ];
        $expiry = now()->addMonths(3)->toDateString();
        $entries = app(StockEntryService::class);

        DB::transaction(function () use ($entries, $owner, $gudang, $kasir1, $warehouse, $outlet1, $outlet2, $line, $expiry, $item) {
            $entries->opening(['location_id' => $warehouse->id, 'notes' => 'Saldo awal contoh', 'lines' => [
                $line('BB-AYAM', 'kg', 10, 60000, ['expiry_date' => $expiry]),
                $line('BB-TEPUNG', 'kg', 20, 12000),
                $line('SJ-DIMSUM', 'pcs', 200, 1500, ['expiry_date' => $expiry]),
                $line('SJ-LUMPIA', 'pcs', 100, 2000, ['expiry_date' => $expiry]),
                $line('BP-MENTAI', 'botol', 6, 45000),
                $line('PK-BOWL', 'pak', 10, 25000),
            ]], $owner);
            $entries->opening(['location_id' => $outlet1->id, 'notes' => 'Saldo awal contoh', 'lines' => [
                $line('SJ-DIMSUM', 'pcs', 40, 1500),
                $line('BP-MENTAI', 'ml', 1000, 90),
                $line('PK-BOWL', 'pcs', 30, 500),
            ]], $owner);

            if (! ItemMinStock::query()->where(['item_id' => $item('PK-BOWL')->id, 'location_id' => $outlet1->id])->exists()) {
                ItemMinStock::query()->create(['item_id' => $item('PK-BOWL')->id, 'location_id' => $outlet1->id, 'min_qty' => 50]);
            }

            app(StockRequestService::class)->create(['location_id' => $outlet1->id, 'notes' => 'Contoh permintaan untuk besok', 'lines' => [
                $line('SJ-DIMSUM', 'pcs', 80),
                $line('PK-BOWL', 'pak', 1),
            ]], $kasir1);

            app(StockTransferService::class)->send(['kind' => StockTransfer::KIND_DISTRIBUTION, 'to_location_id' => $outlet2->id, 'notes' => 'Contoh kiriman', 'lines' => [
                $line('SJ-LUMPIA', 'pcs', 40),
                $line('PK-BOWL', 'pcs', 50),
            ]], $gudang);
        });
    }

    /**
     * Irisan 5: menu tambahan "Extra Saus Mentai" (add-on dijual sebagai menu), shift kasir1 yang
     * sedang berjalan di Outlet 1 dengan dua transaksi, satu pengajuan refund untuk dicoba Owner,
     * dan contoh delegasi Owner → Finance untuk pembatalan penjualan selama 7 hari.
     */
    private function seedSales(): void
    {
        $item = fn (string $code) => Item::query()->where('code', $code)->first();
        $owner = User::query()->where('username', 'owner')->firstOrFail();
        $finance = User::query()->where('username', 'finance1')->first();
        $kasir1 = User::query()->where('username', 'kasir1')->first();
        $outlet1 = Location::query()->where('code', 'OTL01')->first();
        $dimsum = $item('MN-DIMSUM4');
        $mentai = $item('BP-MENTAI');
        if (! $kasir1 || ! $finance || ! $outlet1 || ! $dimsum || ! $mentai || $outlet1->status !== Location::STATUS_ACTIVE) {
            return;
        }
        $porsi = Unit::query()->where('code', 'porsi')->value('id');
        $ml = Unit::query()->where('code', 'ml')->value('id');
        $cash = PaymentMethod::query()->where('code', 'CASH')->value('id');
        $qris = PaymentMethod::query()->where('code', 'QRIS')->where('is_active', true)->value('id');

        DB::transaction(function () use ($item, $owner, $finance, $kasir1, $outlet1, $dimsum, $mentai, $porsi, $ml, $cash, $qris) {
            $extra = $item('MN-EXTRA-MENTAI');
            if (! $extra) {
                $extra = Item::query()->create(['code' => 'MN-EXTRA-MENTAI', 'name' => 'Extra Saus Mentai', 'item_type' => Item::TYPE_MENU, 'base_unit_id' => $porsi]);
                $extra->units()->create(['unit_id' => $porsi, 'factor_to_base' => 1, 'is_usage_default' => true]);
                app(PriceService::class)->setDirect($extra, ['price' => 5000, 'reason' => 'Harga contoh add-on'], $owner);
                app(RecipeService::class)->create($extra, ['notes' => 'Resep contoh', 'lines' => [['item_id' => $mentai->id, 'unit_id' => $ml, 'qty' => 30]]], $owner);
            }

            app(ShiftService::class)->open(['location_id' => $outlet1->id, 'float_counted' => ShiftService::floatAmount()], $kasir1);
            $sales = app(SaleService::class);
            $first = $sales->create([
                'location_id' => $outlet1->id, 'channel' => Sale::CHANNEL_DINE_IN,
                'lines' => [['item_id' => $dimsum->id, 'qty' => 2], ['item_id' => $extra->id, 'qty' => 1]],
                'payments' => [['payment_method_id' => $cash, 'amount' => 60000]],
            ], $kasir1)['sale'];
            $sales->create([
                'location_id' => $outlet1->id, 'channel' => Sale::CHANNEL_TAKEAWAY, 'customer_note' => 'Bu Rina',
                'lines' => [['item_id' => $dimsum->id, 'qty' => 1]],
                'payments' => $qris
                    ? [['payment_method_id' => $qris, 'amount' => 15000, 'reference' => 'contoh'], ['payment_method_id' => $cash, 'amount' => 10000]]
                    : [['payment_method_id' => $cash, 'amount' => 25000]],
            ], $kasir1);

            $extraLine = $first->lines()->where('item_id', $extra->id)->first();
            app(RefundService::class)->request($first, [
                'reason_code' => 'CUSTOMER_CANCEL', 'reason' => 'Contoh: pelanggan tidak jadi pesan extra saus',
                'lines' => [['sale_line_id' => $extraLine->id, 'qty' => 1, 'condition' => 'NOT_MADE']],
            ], $kasir1);

            app(DelegationService::class)->create([
                'delegator_role' => Role::OWNER, 'delegate_id' => $finance->id, 'types' => ['SALE_REFUND'],
                'ends_at' => now()->addDays(7)->toIso8601String(), 'reason' => 'Contoh delegasi',
            ], $owner);
        });
    }

    /**
     * Irisan 6: saldo tetap Petty Cash Rp200.000 per outlet (angka contoh; diisi Owner sebelum go-live),
     * isi awal Petty Cash Outlet 1 oleh Finance, satu biaya outlet yang menunggu diperiksa Finance,
     * dan satu biaya gudang yang dibayar Finance.
     */
    private function seedCash(): void
    {
        $owner = User::query()->where('username', 'owner')->firstOrFail();
        $finance = User::query()->where('username', 'finance1')->first();
        $kasir1 = User::query()->where('username', 'kasir1')->first();
        $outlet1 = Location::query()->where('code', 'OTL01')->first();
        $warehouse = Location::query()->where('type', Location::TYPE_WAREHOUSE)->first();
        $gas = DemoExpenseCategory::query()->where('code', 'GAS')->first() ?? DemoExpenseCategory::query()->first();
        if (! $finance || ! $kasir1 || ! $outlet1 || ! $warehouse || ! $gas) {
            return;
        }
        $petty = app(PettyCashService::class);

        DB::transaction(function () use ($petty, $owner, $finance, $kasir1, $outlet1, $warehouse, $gas) {
            foreach (Location::query()->where('type', Location::TYPE_OUTLET)->where('status', Location::STATUS_ACTIVE)->get() as $outlet) {
                if ($outlet->petty_cash_amount === null) {
                    $petty->setTarget($outlet, 200000, $owner);
                }
            }
            $petty->refill($outlet1->fresh(), [], 'Isi awal contoh', $finance);
            $petty->recordExpense(['location_id' => $outlet1->id, 'expense_category_id' => $gas->id, 'amount' => 25000,
                'description' => 'Contoh: isi ulang gas kompor', 'evidence_note' => 'Nota warung no. 17'], $kasir1);
            $petty->recordExpense(['location_id' => $warehouse->id, 'expense_category_id' => $gas->id, 'amount' => 150000,
                'description' => 'Contoh: gas untuk produksi gudang'], $finance);
        });
    }

    /**
     * Irisan 7: satu PR menunggu Finance, dan satu PR yang sudah dipesan (PO di bawah batas)
     * menunggu barang diterima gudang1. Harga contoh, bukan harga supplier sebenarnya.
     */
    private function seedPurchasing(): void
    {
        $owner = User::query()->where('username', 'owner')->firstOrFail();
        $finance = User::query()->where('username', 'finance1')->first();
        $gudang = User::query()->where('username', 'gudang1')->first();
        $supplier = Supplier::query()->where('code', 'SUP-AYAM')->first();
        $ayam = Item::query()->where('code', 'BB-AYAM')->first();
        $tepung = Item::query()->where('code', 'BB-TEPUNG')->first();
        $kg = Unit::query()->where('code', 'kg')->value('id');
        if (! $finance || ! $gudang || ! $supplier || ! $ayam || ! $tepung || ! $kg) {
            return;
        }
        $purchases = app(PurchaseService::class);

        DB::transaction(function () use ($purchases, $owner, $finance, $gudang, $supplier, $ayam, $tepung, $kg) {
            if (! \App\Modules\System\Models\AppSetting::query()->whereKey(\App\Modules\System\Models\AppSetting::PURCHASE_FINANCE_LIMIT)->exists()) {
                $purchases->setFinanceLimit(PurchaseService::DEFAULT_FINANCE_LIMIT, $owner);
            }
            $ordered = $purchases->createRequest(['reason' => 'Contoh: stok ayam untuk produksi minggu depan', 'needed_by' => now()->addDays(2)->toDateString(),
                'lines' => [['item_id' => $ayam->id, 'unit_id' => $kg, 'qty' => 5]]], $gudang);
            app(ApprovalService::class)->approve(ApprovalRequest::query()->findOrFail($ordered->approval_request_id), $finance, 'Contoh persetujuan');
            $line = $ordered->fresh()->lines()->firstOrFail();
            $purchases->createOrder(['purchase_request_id' => $ordered->id, 'supplier_id' => $supplier->id, 'expected_date' => now()->addDay()->toDateString(),
                'note' => 'Contoh PO', 'lines' => [['purchase_line_id' => $line->id, 'qty' => 5, 'unit_price' => 58000]]], $finance);

            $purchases->createRequest(['reason' => 'Contoh: tepung untuk kulit dimsum',
                'lines' => [['item_id' => $tepung->id, 'unit_id' => $kg, 'qty' => 10]]], $gudang);
        });
    }

    /**
     * Irisan 8: toleransi varians 5%, umur simpan Frozen Dimsum 90 hari, satu produksi yang sudah
     * selesai (hasil baik 96, gagal 4), dan satu permintaan Outlet 2 yang stok gudangnya kurang
     * dengan rencana produksi untuknya. Takaran dan hasil adalah contoh.
     */
    private function seedProduction(): void
    {
        $owner = User::query()->where('username', 'owner')->firstOrFail();
        $gudang = User::query()->where('username', 'gudang1')->first();
        $kasir2 = User::query()->where('username', 'kasir2')->first();
        $outlet2 = Location::query()->where('code', 'OTL02')->first();
        $dimsum = Item::query()->where('code', 'SJ-DIMSUM')->first();
        $unit = fn (string $code) => Unit::query()->where('code', $code)->value('id');
        if (! $gudang || ! $kasir2 || ! $outlet2 || ! $dimsum || ! Recipe::query()->where(['output_item_id' => $dimsum->id, 'kind' => Recipe::KIND_PRODUCTION])->exists()) {
            return;
        }
        $production = app(ProductionService::class);

        DB::transaction(function () use ($production, $owner, $gudang, $kasir2, $outlet2, $dimsum, $unit) {
            if (! \App\Modules\System\Models\AppSetting::query()->whereKey(\App\Modules\System\Models\AppSetting::PRODUCTION_VARIANCE_TOLERANCE)->exists()) {
                $production->setTolerance(ProductionService::DEFAULT_TOLERANCE, $owner);
            }
            if ($dimsum->shelf_life_days === null) {
                $dimsum->update(['shelf_life_days' => 90]);
            }

            $done = $production->plan(['output_item_id' => $dimsum->id, 'unit_id' => $unit('pak'), 'qty' => 5, 'notes' => 'Contoh produksi'], $gudang);
            $lines = $done->lines()->with('item')->get()->keyBy(fn ($l) => $l->item->code);
            $production->complete($done, [
                'unit_id' => $unit('pcs'), 'good_qty' => 96,
                'failures' => [['reason' => 'MISSHAPEN', 'qty' => 4, 'note' => 'Kulit sobek saat dilipat']],
                'lines' => $lines->map(fn ($l, $code) => ['line_id' => $l->id, 'actual_qty' => $code === 'BB-AYAM' ? '1.55' : bcdiv((string) $l->standard_qty, (string) $l->factor, 4)])->values()->all(),
                'note' => 'Contoh hasil produksi',
            ], $gudang);

            $request = app(StockRequestService::class)->create(['location_id' => $outlet2->id, 'notes' => 'Contoh permintaan besar untuk akhir pekan', 'lines' => [
                ['item_id' => $dimsum->id, 'unit_id' => $unit('pak'), 'qty' => 20],
            ]], $kasir2);
            $production->plan(['output_item_id' => $dimsum->id, 'unit_id' => $unit('pak'), 'qty' => 6, 'stock_request_id' => $request->id,
                'planned_date' => now()->addDay()->toDateString(), 'notes' => 'Contoh rencana untuk permintaan Outlet 2'], $gudang);
        });
    }
}
