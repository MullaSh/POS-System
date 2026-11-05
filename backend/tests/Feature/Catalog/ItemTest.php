<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Identity\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class ItemTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private Unit $gram;

    private Unit $kg;

    private Unit $porsi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gram = Unit::query()->create(['code' => 'g', 'name' => 'gram']);
        $this->kg = Unit::query()->create(['code' => 'kg', 'name' => 'kilogram']);
        $this->porsi = Unit::query()->create(['code' => 'porsi', 'name' => 'porsi']);
    }

    private function createChicken(): Item
    {
        $this->postJson('/api/v1/items', [
            'code' => 'bb-ayam',
            'name' => 'Daging Ayam',
            'item_type' => 'RAW',
            'base_unit_id' => $this->gram->id,
            'units' => [['unit_id' => $this->kg->id, 'factor_to_base' => 1000, 'is_purchase_default' => true]],
        ])->assertCreated();

        return Item::query()->where('code', 'BB-AYAM')->sole();
    }

    public function test_owner_creates_item_with_conversion_to_base_unit(): void
    {
        $this->actingAsOwner();

        $this->postJson('/api/v1/items', [
            'code' => 'bb-ayam',
            'name' => 'Daging Ayam',
            'item_type' => 'RAW',
            'base_unit_id' => $this->gram->id,
            'units' => [['unit_id' => $this->kg->id, 'factor_to_base' => 1000, 'is_purchase_default' => true]],
        ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'BB-AYAM')
            ->assertJsonPath('data.is_stock_item', true)
            ->assertJsonPath('data.base_unit.code', 'g')
            ->assertJsonCount(2, 'data.units')
            ->assertJsonPath('data.units.0.code', 'g')
            ->assertJsonPath('data.units.0.factor_to_base', '1.0000')
            ->assertJsonPath('data.units.1.code', 'kg')
            ->assertJsonPath('data.units.1.factor_to_base', '1000.0000');

        $this->assertDatabaseHas('audit_logs', ['action' => 'ITEM_CREATED', 'subject_code' => 'BB-AYAM']);
    }

    public function test_invalid_item_type_and_zero_factor_are_rejected(): void
    {
        $this->actingAsOwner();

        $this->postJson('/api/v1/items', [
            'code' => 'x1', 'name' => 'X', 'item_type' => 'FINISHED', 'base_unit_id' => $this->gram->id,
            'units' => [['unit_id' => $this->kg->id, 'factor_to_base' => 0]],
        ])->assertStatus(422)->assertJsonValidationErrors(['item_type', 'units.0.factor_to_base']);
    }

    public function test_type_and_base_unit_cannot_change_after_creation(): void
    {
        $this->actingAsOwner();
        $item = $this->createChicken();

        $this->patchJson("/api/v1/items/{$item->id}", [
            'name' => 'Ayam Fillet', 'item_type' => 'MENU', 'base_unit_id' => $this->kg->id,
        ])->assertOk()
            ->assertJsonPath('data.name', 'Ayam Fillet')
            ->assertJsonPath('data.item_type', 'RAW')
            ->assertJsonPath('data.base_unit.code', 'g');
    }

    public function test_min_stock_per_location_and_not_for_menu(): void
    {
        $this->actingAsOwner();
        $item = $this->createChicken();
        $warehouse = $this->warehouse();

        $this->putJson("/api/v1/items/{$item->id}/min-stocks", ['min_stocks' => [['location_id' => $warehouse->id, 'min_qty' => 5000]]])
            ->assertOk()->assertJsonPath('data.min_stocks.0.location_code', 'GDG');

        $menu = Item::query()->create(['code' => 'MN-1', 'name' => 'Dimsum Mentai', 'item_type' => 'MENU', 'base_unit_id' => $this->porsi->id]);
        $this->putJson("/api/v1/items/{$menu->id}/min-stocks", ['min_stocks' => [['location_id' => $warehouse->id, 'min_qty' => 1]]])
            ->assertStatus(422)->assertJsonValidationErrors('min_stocks');
    }

    public function test_conversion_set_can_be_replaced_and_is_audited(): void
    {
        $this->actingAsOwner();
        $item = $this->createChicken();

        $this->putJson("/api/v1/items/{$item->id}/units", ['units' => []])
            ->assertOk()->assertJsonCount(1, 'data.units');

        $this->assertDatabaseHas('audit_logs', ['action' => 'ITEM_UNITS_CHANGED', 'subject_id' => $item->id]);
    }

    public function test_other_roles_can_read_but_not_change_items(): void
    {
        Sanctum::actingAs($this->userWithRole(Role::WAREHOUSE, [$this->warehouse()->id]));

        $this->getJson('/api/v1/items')->assertOk();
        $this->postJson('/api/v1/items', [
            'code' => 'X', 'name' => 'X', 'item_type' => 'RAW', 'base_unit_id' => $this->gram->id,
        ])->assertStatus(403);
    }
}
