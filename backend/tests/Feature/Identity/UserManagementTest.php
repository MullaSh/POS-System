<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\RoleAssignment;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_owner_creates_user_who_must_change_password(): void
    {
        $this->actingAsOwner();

        $this->postJson('/api/v1/users', ['name' => 'Budi', 'username' => 'Budi.Kasir', 'password' => 'Sementara1'])
            ->assertCreated()
            ->assertJsonPath('data.username', 'budi.kasir')
            ->assertJsonPath('data.must_change_password', true)
            ->assertJsonMissingPath('data.password');

        $this->assertDatabaseHas('audit_logs', ['action' => 'USER_CREATED', 'subject_code' => 'budi.kasir']);
    }

    public function test_non_owner_cannot_manage_users(): void
    {
        Sanctum::actingAs($this->userWithRole(Role::FINANCE));

        $this->getJson('/api/v1/users')->assertStatus(403);
        $this->postJson('/api/v1/users', ['name' => 'X', 'username' => 'xyz', 'password' => 'Sementara1'])->assertStatus(403);
    }

    public function test_username_must_be_unique(): void
    {
        $this->actingAsOwner();
        User::factory()->create(['username' => 'kasir1']);

        $this->postJson('/api/v1/users', ['name' => 'Kasir', 'username' => 'KASIR1', 'password' => 'Sementara1'])
            ->assertStatus(422)->assertJsonValidationErrors('username');
    }

    public function test_cashier_is_assigned_only_to_outlets(): void
    {
        $this->actingAsOwner();
        $user = User::factory()->create();
        $outlet = $this->outlet();
        $warehouse = $this->warehouse();

        $this->postJson("/api/v1/users/{$user->id}/role-assignments", ['role' => 'CASHIER', 'location_ids' => [$warehouse->id]])
            ->assertStatus(422)->assertJsonPath('errors.location_ids.0', 'Kasir hanya dapat ditugaskan di outlet.');

        $this->postJson("/api/v1/users/{$user->id}/role-assignments", ['role' => 'CASHIER', 'location_ids' => []])
            ->assertStatus(422)->assertJsonPath('errors.location_ids.0', 'Pilih minimal satu outlet untuk kasir.');

        $this->postJson("/api/v1/users/{$user->id}/role-assignments", ['role' => 'CASHIER', 'location_ids' => [$outlet->id]])
            ->assertCreated()
            ->assertJsonPath('data.roles.0.code', 'CASHIER')
            ->assertJsonPath('data.roles.0.status', 'ACTIVE')
            ->assertJsonPath('data.roles.0.locations.0.code', 'OTL01');
    }

    public function test_finance_covers_all_locations_and_warehouse_covers_the_warehouse(): void
    {
        $this->actingAsOwner();
        $user = User::factory()->create();
        $warehouse = $this->warehouse();

        $this->postJson("/api/v1/users/{$user->id}/role-assignments", ['role' => 'FINANCE'])
            ->assertCreated()->assertJsonPath('data.roles.0.scope', 'ALL_LOCATIONS');

        $this->postJson("/api/v1/users/{$user->id}/role-assignments", ['role' => 'WAREHOUSE'])
            ->assertCreated()->assertJsonPath('data.roles.1.locations.0.id', $warehouse->id);
    }

    public function test_owner_role_cannot_be_assigned_from_the_application(): void
    {
        $this->actingAsOwner();
        $user = User::factory()->create();

        $this->postJson("/api/v1/users/{$user->id}/role-assignments", ['role' => 'OWNER'])
            ->assertStatus(422)->assertJsonValidationErrors('role');
    }

    public function test_same_role_cannot_be_assigned_twice_but_can_after_revoke(): void
    {
        $this->actingAsOwner();
        $user = $this->userWithRole(Role::FINANCE);

        $this->postJson("/api/v1/users/{$user->id}/role-assignments", ['role' => 'FINANCE'])->assertStatus(422);

        $assignment = $user->roleAssignments()->sole();
        $this->postJson("/api/v1/role-assignments/{$assignment->id}/revoke")->assertOk()->assertJsonCount(0, 'data.roles');
        $this->assertSame(RoleAssignment::STATUS_REVOKED, $assignment->refresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ROLE_REVOKED', 'subject_id' => $assignment->id]);

        $this->postJson("/api/v1/users/{$user->id}/role-assignments", ['role' => 'FINANCE'])->assertCreated();
    }

    public function test_owner_cannot_deactivate_self_or_another_owner_account(): void
    {
        $owner = $this->actingAsOwner();

        $this->postJson("/api/v1/users/{$owner->id}/deactivate")->assertStatus(422);
    }

    public function test_deactivate_and_reset_password(): void
    {
        $this->actingAsOwner();
        $user = $this->userWithRole(Role::FINANCE);

        $this->postJson("/api/v1/users/{$user->id}/deactivate")->assertOk()->assertJsonPath('data.is_active', false);
        $this->postJson("/api/v1/users/{$user->id}/activate")->assertOk()->assertJsonPath('data.is_active', true);
        $this->postJson("/api/v1/users/{$user->id}/reset-password", ['password' => 'Sementara2'])
            ->assertOk()->assertJsonPath('data.must_change_password', true);

        $this->assertDatabaseHas('audit_logs', ['action' => 'USER_DEACTIVATED', 'subject_id' => $user->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'USER_PASSWORD_RESET', 'subject_id' => $user->id]);
    }
}
