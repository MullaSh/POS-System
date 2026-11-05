<?php

namespace Tests\Feature\Location;

use App\Modules\Identity\Models\Role;
use App\Modules\Location\Models\Location;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class LocationTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_owner_opens_an_outlet(): void
    {
        $this->actingAsOwner();

        $this->postJson('/api/v1/locations', ['code' => 'otl-bsd', 'name' => 'Outlet BSD'])
            ->assertCreated()
            ->assertJsonPath('data.code', 'OTL-BSD')
            ->assertJsonPath('data.type', 'OUTLET')
            ->assertJsonPath('data.status', 'ACTIVE');

        $this->assertDatabaseHas('audit_logs', ['action' => 'OUTLET_OPENED', 'subject_code' => 'OTL-BSD']);
    }

    public function test_only_one_warehouse_can_exist(): void
    {
        $this->warehouse();

        $this->expectException(QueryException::class);
        DB::table('locations')->insert(['code' => 'GDG2', 'name' => 'Gudang 2', 'type' => 'WAREHOUSE', 'status' => 'ACTIVE']);
    }

    public function test_code_and_type_cannot_change_on_update(): void
    {
        $this->actingAsOwner();
        $outlet = $this->outlet();

        $this->patchJson("/api/v1/locations/{$outlet->id}", ['name' => 'Outlet Utama', 'code' => 'XXX', 'type' => 'WAREHOUSE'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Outlet Utama')
            ->assertJsonPath('data.code', 'OTL01')
            ->assertJsonPath('data.type', 'OUTLET');
    }

    public function test_warehouse_cannot_be_closed_or_deactivated(): void
    {
        $this->actingAsOwner();
        $warehouse = $this->warehouse();

        $this->postJson("/api/v1/locations/{$warehouse->id}/close", ['reason' => 'tes'])->assertStatus(422);
        $this->postJson("/api/v1/locations/{$warehouse->id}/deactivate")->assertStatus(422);
    }

    public function test_closing_an_outlet_needs_a_reason_and_is_permanent(): void
    {
        $this->actingAsOwner();
        $outlet = $this->outlet();

        $this->postJson("/api/v1/locations/{$outlet->id}/close")->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/locations/{$outlet->id}/close", ['reason' => 'Kontrak sewa habis'])
            ->assertOk()->assertJsonPath('data.status', 'CLOSED');
        $this->postJson("/api/v1/locations/{$outlet->id}/activate")->assertStatus(422);

        $this->assertDatabaseHas('audit_logs', ['action' => 'OUTLET_CLOSED', 'reason' => 'Kontrak sewa habis']);
    }

    public function test_other_roles_can_read_but_not_change_locations(): void
    {
        $outlet = $this->outlet();
        Sanctum::actingAs($this->userWithRole(Role::CASHIER, [$outlet->id]));

        $this->getJson('/api/v1/locations')->assertOk();
        $this->postJson('/api/v1/locations', ['code' => 'OTL09', 'name' => 'X'])->assertStatus(403);
        $this->assertSame(1, Location::query()->count());
    }
}
