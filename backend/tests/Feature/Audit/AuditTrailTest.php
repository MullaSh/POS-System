<?php

namespace Tests\Feature\Audit;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\Role;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class AuditTrailTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private function oneLog(): AuditLog
    {
        return app(AuditLogger::class)->record('TEST_EVENT', changes: ['a' => 1], system: true);
    }

    public function test_database_rejects_update_of_audit_log(): void
    {
        $log = $this->oneLog();

        try {
            DB::transaction(fn () => DB::table('audit_logs')->where('id', $log->id)->update(['action' => 'DIUBAH']));
            $this->fail('Update seharusnya ditolak database.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        $this->assertSame('TEST_EVENT', AuditLog::query()->find($log->id)->action);
    }

    public function test_database_rejects_delete_of_audit_log(): void
    {
        $log = $this->oneLog();

        try {
            DB::transaction(fn () => DB::table('audit_logs')->where('id', $log->id)->delete());
            $this->fail('Delete seharusnya ditolak database.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        $this->assertDatabaseHas('audit_logs', ['id' => $log->id]);
    }

    public function test_model_refuses_to_change_audit_log(): void
    {
        $log = $this->oneLog();

        $this->expectException(LogicException::class);
        $log->update(['action' => 'X']);
    }

    public function test_system_events_are_flagged(): void
    {
        $log = $this->oneLog();

        $this->assertNull($log->user_id);
        $this->assertSame([AuditLogger::FLAG_SYSTEM], $log->refresh()->flags);
    }

    public function test_actions_record_actor_and_acting_role(): void
    {
        $owner = $this->actingAsOwner();

        $this->postJson('/api/v1/locations', ['code' => 'OTL05', 'name' => 'Outlet 5'])->assertCreated();

        $log = AuditLog::query()->where('action', 'OUTLET_OPENED')->sole();
        $this->assertSame($owner->id, $log->user_id);
        $this->assertSame(Role::idFor(Role::OWNER), $log->role_id);
        $this->assertNotNull($log->recorded_at);
        $this->assertNull($log->ip_address, 'IP hanya dicatat untuk kejadian akses akun.');
    }

    public function test_only_owner_can_trace_audit_logs(): void
    {
        $this->oneLog();

        Sanctum::actingAs($this->userWithRole(Role::FINANCE));
        $this->getJson('/api/v1/audit-logs')->assertStatus(403);

        Sanctum::actingAs($this->owner());
        $this->getJson('/api/v1/audit-logs?action=TEST_EVENT')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.action', 'TEST_EVENT');
    }
}
