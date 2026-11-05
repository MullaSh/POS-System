<?php

namespace Tests\Feature\Identity;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private function spa(): static
    {
        return $this->withHeaders(['Origin' => 'http://localhost', 'Referer' => 'http://localhost/']);
    }

    public function test_user_can_log_in_with_username_and_password(): void
    {
        $user = $this->userWithRole(Role::FINANCE, [], ['username' => 'finance1']);

        $this->spa()->postJson('/api/v1/auth/login', ['username' => 'Finance1', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('user.username', 'finance1')
            ->assertJsonPath('user.roles.0.code', 'FINANCE');

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNotNull($user->refresh()->last_login_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'LOGIN', 'user_id' => $user->id]);
    }

    public function test_wrong_password_is_rejected_and_recorded_without_blaming_the_account(): void
    {
        $user = $this->userWithRole(Role::CASHIER, [$this->outlet()->id], ['username' => 'kasir1']);

        $this->spa()->postJson('/api/v1/auth/login', ['username' => 'kasir1', 'password' => 'salah'])
            ->assertStatus(422)
            ->assertJsonPath('errors.username.0', 'Username atau kata sandi salah.');

        $this->assertGuest('web');
        $log = AuditLog::query()->where('action', 'LOGIN_FAILED')->sole();
        $this->assertNull($log->user_id);
        $this->assertSame($user->id, $log->subject_id);
        $this->assertNotNull($log->ip_address);
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        $this->userWithRole(Role::FINANCE, [], ['username' => 'nonaktif', 'is_active' => false]);

        $this->spa()->postJson('/api/v1/auth/login', ['username' => 'nonaktif', 'password' => 'password'])
            ->assertStatus(422)
            ->assertJsonPath('errors.username.0', 'Akun Anda sedang dinonaktifkan. Hubungi pemilik usaha.');
    }

    public function test_user_who_must_change_password_is_blocked_until_changed(): void
    {
        $owner = $this->owner(['must_change_password' => true]);
        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('user.must_change_password', true);
        $this->getJson('/api/v1/users')
            ->assertStatus(403)
            ->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');

        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'password',
            'password' => 'KataSandiBaru1',
            'password_confirmation' => 'KataSandiBaru1',
        ])->assertOk()->assertJsonPath('user.must_change_password', false);

        $this->getJson('/api/v1/users')->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'PASSWORD_CHANGED', 'user_id' => $owner->id]);
    }

    public function test_change_password_requires_correct_current_password(): void
    {
        Sanctum::actingAs($this->owner());

        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'keliru',
            'password' => 'KataSandiBaru1',
            'password_confirmation' => 'KataSandiBaru1',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');
    }

    public function test_deactivated_user_is_logged_out_on_next_request(): void
    {
        $user = $this->userWithRole(Role::FINANCE);
        Sanctum::actingAs($user);
        $user->update(['is_active' => false]);

        $this->getJson('/api/v1/locations')->assertStatus(401);
    }

    public function test_guest_cannot_reach_protected_endpoints(): void
    {
        $this->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->getJson('/api/v1/items')->assertStatus(401);
    }

    public function test_validation_messages_are_in_indonesian(): void
    {
        $this->spa()->postJson('/api/v1/auth/login', [])
            ->assertStatus(422)
            ->assertJsonPath('errors.username.0', 'Username wajib diisi.')
            ->assertJsonPath('errors.password.0', 'Kata sandi wajib diisi.');
    }
}
