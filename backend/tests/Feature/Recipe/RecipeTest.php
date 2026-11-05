<?php

namespace Tests\Feature\Recipe;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Identity\Models\Role;
use App\Modules\Recipe\Contracts\CostSource;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class RecipeTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    /** @var array<string, Unit> */
    private array $u = [];

    /** @var array<string, Item> */
    private array $i = [];

    /** @var array<string, string> Harga pokok rata-rata palsu per kode barang. */
    private array $fakeCosts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::create(2026, 11, 5, 10, 0, 0, 'Asia/Jakarta'));
        foreach (['g' => 'gram', 'kg' => 'kilogram', 'ml' => 'mililiter', 'pcs' => 'pcs', 'pak' => 'pak', 'porsi' => 'porsi'] as $code => $name) {
            $this->u[$code] = Unit::query()->create(['code' => $code, 'name' => $name]);
        }
        $this->item('BB-AYAM', Item::TYPE_RAW, 'g', ['kg' => 1000]);
        $this->item('BB-TEPUNG', Item::TYPE_RAW, 'g', ['kg' => 1000]);
        $this->item('SJ-DIMSUM', Item::TYPE_SEMI_FINISHED, 'pcs', ['pak' => 20]);
        $this->item('SJ-KULIT', Item::TYPE_SEMI_FINISHED, 'pcs');
        $this->item('BP-MENTAI', Item::TYPE_SUPPORTING, 'ml');
        $this->item('PK-BOWL', Item::TYPE_PACKAGING, 'pcs');
        $this->item('MN-DIMSUM4', Item::TYPE_MENU, 'porsi');

        $test = $this;
        $this->app->instance(CostSource::class, new class($test) implements CostSource
        {
            public function __construct(private readonly RecipeTest $test) {}

            public function unitCost(Item $item, ?int $locationId = null): ?string
            {
                return $this->test->fakeCost($item->code);
            }
        });
    }

    public function fakeCost(string $code): ?string
    {
        return $this->fakeCosts[$code] ?? null;
    }

    /** @param  array<string, int>  $conversions */
    private function item(string $code, string $type, string $base, array $conversions = []): Item
    {
        $item = Item::query()->create(['code' => $code, 'name' => $code, 'item_type' => $type, 'base_unit_id' => $this->u[$base]->id]);
        $item->units()->create(['unit_id' => $this->u[$base]->id, 'factor_to_base' => 1]);
        foreach ($conversions as $unit => $factor) {
            $item->units()->create(['unit_id' => $this->u[$unit]->id, 'factor_to_base' => $factor]);
        }

        return $this->i[$code] = $item;
    }

    private function line(string $item, string $unit, $qty): array
    {
        return ['item_id' => $this->i[$item]->id, 'unit_id' => $this->u[$unit]->id, 'qty' => $qty];
    }

    private function dimsumProduction(): int
    {
        return $this->postJson('/api/v1/recipes', [
            'output_item_id' => $this->i['SJ-DIMSUM']->id,
            'output_unit_id' => $this->u['pak']->id,
            'output_qty' => 5,
            'lines' => [$this->line('BB-AYAM', 'kg', 1.5), $this->line('BB-TEPUNG', 'g', 1000)],
        ])->assertCreated()->json('data.id');
    }

    private function dimsumMenu(): int
    {
        return $this->postJson('/api/v1/recipes', [
            'output_item_id' => $this->i['MN-DIMSUM4']->id,
            'lines' => [$this->line('SJ-DIMSUM', 'pcs', 4), $this->line('BP-MENTAI', 'ml', 30), $this->line('PK-BOWL', 'pcs', 1)],
        ])->assertCreated()->json('data.id');
    }

    public function test_owner_creates_production_recipe_in_base_units(): void
    {
        $this->actingAsOwner();
        $id = $this->dimsumProduction();

        $this->getJson("/api/v1/recipes/{$id}")
            ->assertOk()
            ->assertJsonPath('data.kind', 'PRODUCTION')
            ->assertJsonPath('data.versions.0.status', 'CURRENT')
            ->assertJsonPath('data.versions.0.output_qty', '100.0000')
            ->assertJsonPath('data.versions.0.lines.0.qty', '1500.0000')
            ->assertJsonPath('data.versions.0.lines.0.entered_qty', '1.5000')
            ->assertJsonPath('data.versions.0.lines.0.unit.code', 'kg');

        $this->assertSame(1, AuditLog::query()->where('action', 'RECIPE_CREATED')->count());
    }

    public function test_menu_recipe_is_for_one_portion_and_rejects_raw_material(): void
    {
        $this->actingAsOwner();
        $id = $this->dimsumMenu();
        $this->getJson("/api/v1/recipes/{$id}")->assertJsonPath('data.kind', 'MENU')->assertJsonPath('data.versions.0.output_qty', '1.0000');

        $other = $this->item('MN-AYAM', Item::TYPE_MENU, 'porsi');
        $this->postJson('/api/v1/recipes', ['output_item_id' => $other->id, 'lines' => [$this->line('BB-AYAM', 'g', 100)]])
            ->assertUnprocessable()->assertJsonValidationErrors('lines.0.item_id');
    }

    public function test_recipe_rules(): void
    {
        $this->actingAsOwner();

        // Bahan baku tidak dibuat dengan resep.
        $this->postJson('/api/v1/recipes', ['output_item_id' => $this->i['BB-AYAM']->id, 'lines' => [$this->line('BB-TEPUNG', 'g', 1)]])
            ->assertUnprocessable()->assertJsonValidationErrors('output_item_id');

        // Satuan yang belum diatur untuk bahan itu.
        $this->postJson('/api/v1/recipes', ['output_item_id' => $this->i['MN-DIMSUM4']->id, 'lines' => [$this->line('BP-MENTAI', 'kg', 1)]])
            ->assertUnprocessable()->assertJsonValidationErrors('lines.0.unit_id');

        // Resep produksi wajib punya jumlah hasil.
        $this->postJson('/api/v1/recipes', ['output_item_id' => $this->i['SJ-DIMSUM']->id, 'lines' => [$this->line('BB-AYAM', 'g', 1)]])
            ->assertUnprocessable()->assertJsonValidationErrors('output_qty');

        // Satu resep per barang.
        $this->dimsumMenu();
        $this->postJson('/api/v1/recipes', ['output_item_id' => $this->i['MN-DIMSUM4']->id, 'lines' => [$this->line('PK-BOWL', 'pcs', 1)]])
            ->assertUnprocessable()->assertJsonValidationErrors('output_item_id');
    }

    public function test_circular_production_recipes_are_rejected(): void
    {
        $this->actingAsOwner();
        $this->postJson('/api/v1/recipes', [
            'output_item_id' => $this->i['SJ-DIMSUM']->id, 'output_unit_id' => $this->u['pcs']->id, 'output_qty' => 10,
            'lines' => [$this->line('SJ-KULIT', 'pcs', 10), $this->line('BB-AYAM', 'g', 100)],
        ])->assertCreated();

        $this->postJson('/api/v1/recipes', [
            'output_item_id' => $this->i['SJ-KULIT']->id, 'output_unit_id' => $this->u['pcs']->id, 'output_qty' => 10,
            'lines' => [$this->line('SJ-DIMSUM', 'pcs', 1)],
        ])->assertUnprocessable()->assertJsonValidationErrors('lines');
    }

    public function test_new_version_takes_effect_on_its_date_and_old_version_is_kept(): void
    {
        $this->actingAsOwner();
        $id = $this->dimsumMenu();

        $this->postJson("/api/v1/recipes/{$id}/versions", [
            'effective_date' => '2026-12-01',
            'notes' => 'Saus dikurangi',
            'lines' => [$this->line('SJ-DIMSUM', 'pcs', 4), $this->line('BP-MENTAI', 'ml', 25), $this->line('PK-BOWL', 'pcs', 1)],
        ])->assertCreated()
            ->assertJsonPath('data.versions.0.version_no', 2)
            ->assertJsonPath('data.versions.0.status', 'UPCOMING')
            ->assertJsonPath('data.versions.1.status', 'CURRENT');

        $this->travelTo(Carbon::create(2026, 12, 1, 0, 0, 1, 'Asia/Jakarta'));
        $this->getJson("/api/v1/recipes/{$id}")
            ->assertJsonPath('data.versions.0.status', 'CURRENT')
            ->assertJsonPath('data.versions.0.lines.1.entered_qty', '25.0000')
            ->assertJsonPath('data.versions.1.status', 'PAST')
            ->assertJsonPath('data.versions.1.lines.1.entered_qty', '30.0000');

        $this->assertSame(1, AuditLog::query()->where('action', 'RECIPE_VERSION_CREATED')->count());
    }

    public function test_hpp_uses_stock_average_and_falls_back_to_production_recipe(): void
    {
        $this->actingAsOwner();
        $this->dimsumProduction();
        $menuId = $this->dimsumMenu();

        // Belum ada harga pokok sama sekali.
        $this->getJson("/api/v1/recipes/{$menuId}")
            ->assertJsonPath('data.versions.0.cost.total', null)
            ->assertJsonPath('data.versions.0.cost.missing', ['SJ-DIMSUM', 'BP-MENTAI', 'PK-BOWL']);

        // Rp120/kg ayam = 0,12/g; tepung 0,015/g → 1 batch 100 pcs = 180 + 15 = Rp195 → Rp1,95/pcs.
        $this->fakeCosts = ['BB-AYAM' => '0.12', 'BB-TEPUNG' => '0.015', 'BP-MENTAI' => '0.08', 'PK-BOWL' => '500'];
        $this->getJson("/api/v1/recipes/{$menuId}")
            ->assertJsonPath('data.versions.0.lines.0.unit_cost', '1.9500')
            ->assertJsonPath('data.versions.0.lines.0.cost_source', 'FROM_RECIPE')
            ->assertJsonPath('data.versions.0.lines.1.line_cost', '2.40')
            ->assertJsonPath('data.versions.0.cost.total', '510.20');

        // Setelah ada stok, rata-rata stok yang dipakai (KP-04).
        $this->fakeCosts['SJ-DIMSUM'] = '2.5';
        $this->getJson("/api/v1/recipes/{$menuId}")
            ->assertJsonPath('data.versions.0.lines.0.cost_source', 'STOCK_AVERAGE')
            ->assertJsonPath('data.versions.0.cost.total', '512.40');
        $this->getJson('/api/v1/recipes?kind=MENU')->assertJsonPath('data.0.cost.total', '512.40');
    }

    public function test_cashier_sees_recipe_but_not_cost_and_cannot_change_it(): void
    {
        $this->actingAsOwner();
        $id = $this->dimsumMenu();
        $this->fakeCosts = ['SJ-DIMSUM' => '2', 'BP-MENTAI' => '0.1', 'PK-BOWL' => '500'];

        Sanctum::actingAs($this->userWithRole(Role::CASHIER, [$this->outlet()->id]));
        $this->getJson("/api/v1/recipes/{$id}")
            ->assertOk()
            ->assertJsonPath('data.can_see_cost', false)
            ->assertJsonPath('data.versions.0.cost', null)
            ->assertJsonPath('data.versions.0.lines.0.unit_cost', null);
        $this->getJson('/api/v1/recipes')->assertJsonPath('data.0.cost', null);
        $this->postJson("/api/v1/recipes/{$id}/versions", ['lines' => [$this->line('PK-BOWL', 'pcs', 1)]])->assertForbidden();

        Sanctum::actingAs($this->userWithRole(Role::FINANCE));
        $this->getJson("/api/v1/recipes/{$id}")->assertJsonPath('data.versions.0.cost.total', '511.00');
    }

    public function test_usage_lists_recipes_that_use_an_ingredient(): void
    {
        $this->actingAsOwner();
        $this->dimsumMenu();

        $this->getJson("/api/v1/recipes/usage?item_id={$this->i['PK-BOWL']->id}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.output_item.code', 'MN-DIMSUM4');
        $this->getJson("/api/v1/recipes/usage?item_id={$this->i['BB-AYAM']->id}")->assertJsonCount(0, 'data');
    }

    public function test_recipe_versions_cannot_be_changed_in_database(): void
    {
        $this->actingAsOwner();
        $this->dimsumMenu();

        foreach (['UPDATE recipe_versions SET notes = \'x\'', 'DELETE FROM recipe_version_lines', 'UPDATE recipe_version_lines SET qty = 9'] as $sql) {
            try {
                DB::transaction(fn () => DB::statement($sql));
                $this->fail("Seharusnya ditolak: {$sql}");
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }
    }
}
