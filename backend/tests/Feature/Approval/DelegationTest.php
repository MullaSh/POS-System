<?php

namespace Tests\Feature\Approval;

use App\Modules\Approval\Models\ApprovalDelegation;
use App\Modules\Approval\Models\ApprovalRequest;
use App\Modules\Approval\Services\ApprovalService;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/** Delegasi persetujuan sementara (2E-Q2; keputusan Owner 6 Nov 2026). */
class DelegationTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private User $owner;

    private User $finance;

    private User $finance2;

    private User $kasir;

    private Item $menu;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::create(2026, 11, 6, 9, 0, 0, 'Asia/Jakarta'));
        $outlet = $this->outlet('OTL01');
        $this->owner = $this->owner();
        $this->finance = $this->userWithRole(Role::FINANCE);
        $this->finance2 = $this->userWithRole(Role::FINANCE);
        $this->kasir = $this->userWithRole(Role::CASHIER, [$outlet->id]);
        $porsi = Unit::query()->create(['code' => 'porsi', 'name' => 'porsi']);
        $this->menu = Item::query()->create(['code' => 'MN-1', 'name' => 'Menu Satu', 'item_type' => Item::TYPE_MENU, 'base_unit_id' => $porsi->id]);
    }

    private function delegate(User $to, array $types, string $ends = '2026-11-08T18:00:00+07:00', array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/delegations', [
            'delegator_role' => Role::OWNER, 'delegate_id' => $to->id, 'types' => $types, 'ends_at' => $ends, 'reason' => 'Owner ke luar kota', ...$extra,
        ]);
    }

    private function priceRequest(User $by, int $price = 20000): ApprovalRequest
    {
        return app(ApprovalService::class)->submit(ApprovalRequest::TYPE_ITEM_PRICE, $this->menu, ['price' => $price], 'Harga baru', $by, Role::FINANCE);
    }

    public function test_owner_delegates_selected_types_for_a_period_and_delegate_decides_with_flag(): void
    {
        // Validasi: diri sendiri, jenis yang bukan milik Owner, periode, dan batas lama.
        Sanctum::actingAs($this->owner);
        $this->delegate($this->owner, ['ITEM_PRICE'])->assertUnprocessable()->assertJsonValidationErrors('delegate_id');
        $this->delegate($this->finance, ['UNKNOWN'])->assertUnprocessable()->assertJsonValidationErrors('types.0');
        $this->delegate($this->finance, ['ITEM_PRICE'], '2026-11-06T08:00:00+07:00')->assertUnprocessable()->assertJsonValidationErrors('ends_at');
        $this->delegate($this->finance, ['ITEM_PRICE'], '2027-01-30T08:00:00+07:00')->assertUnprocessable()->assertJsonValidationErrors('ends_at');

        $this->getJson('/api/v1/delegations/options')->assertOk()
            ->assertJsonPath('data.roles.0.code', 'OWNER')
            ->assertJsonPath('data.roles.0.types', ['ITEM_PRICE', 'STOCK_COUNT', 'SALE_REFUND']);

        $id = $this->delegate($this->finance, ['ITEM_PRICE'])->assertCreated()->assertJsonPath('data.status', 'ACTIVE')->json('data.id');

        // Pengajuan dari finance2: penerima delegasi (finance) dapat memutuskannya.
        $request = $this->priceRequest($this->finance2);
        Sanctum::actingAs($this->finance);
        $this->getJson('/api/v1/auth/me')->assertJsonPath('user.approval_types', ['ITEM_PRICE']);
        $this->getJson('/api/v1/approvals/summary')->assertJsonPath('data.waiting_my_decision', 1);
        $this->getJson('/api/v1/approvals?status=OPEN')->assertOk()->assertJsonPath('data.0.can.decide', true);
        $this->postJson("/api/v1/approvals/{$request->id}/approve", ['note' => 'Disetujui atas delegasi'])->assertOk()
            ->assertJsonPath('data.status', 'APPROVED')
            ->assertJsonPath('data.delegation.delegator.id', $this->owner->id);

        $log = AuditLog::query()->where('action', 'APPROVAL_APPROVED')->firstOrFail();
        $this->assertContains('DELEGATED', $log->flags);
        $this->assertSame($this->finance->id, $log->user_id);
        $this->assertSame($id, ApprovalRequest::query()->find($request->id)->delegation_id);

        // Penerima tetap tidak boleh memutuskan pengajuannya sendiri.
        $own = $this->priceRequest($this->finance, 21000);
        $this->postJson("/api/v1/approvals/{$own->id}/approve")->assertForbidden();
        // Jenis yang tidak didelegasikan tidak bisa diputuskan.
        $this->assertFalse(in_array('STOCK_COUNT', $this->getJson('/api/v1/auth/me')->json('user.approval_types'), true));
    }

    public function test_revoked_expired_or_scheduled_delegations_do_not_give_authority(): void
    {
        Sanctum::actingAs($this->owner);
        $id = $this->delegate($this->kasir, ['ITEM_PRICE'])->assertCreated()->json('data.id');
        $request = $this->priceRequest($this->finance);

        // Hanya pemberi atau Owner yang dapat mencabut.
        Sanctum::actingAs($this->kasir);
        $this->postJson("/api/v1/delegations/{$id}/revoke")->assertForbidden();
        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/delegations/{$id}/revoke", ['note' => 'Sudah kembali'])->assertOk()->assertJsonPath('data.status', 'REVOKED');
        $this->postJson("/api/v1/delegations/{$id}/revoke")->assertUnprocessable();

        Sanctum::actingAs($this->kasir);
        $this->postJson("/api/v1/approvals/{$request->id}/approve")->assertForbidden();
        $this->getJson('/api/v1/approvals/summary')->assertJsonPath('data.waiting_my_decision', 0);

        // Delegasi terjadwal belum berlaku; setelah lewat waktu selesai, tidak berlaku lagi.
        Sanctum::actingAs($this->owner);
        $this->delegate($this->kasir, ['ITEM_PRICE'], '2026-11-07T18:00:00+07:00', ['starts_at' => '2026-11-07T08:00:00+07:00'])
            ->assertCreated()->assertJsonPath('data.status', 'SCHEDULED');
        Sanctum::actingAs($this->kasir);
        $this->postJson("/api/v1/approvals/{$request->id}/approve")->assertForbidden();
        $this->travelTo(Carbon::create(2026, 11, 7, 12, 0, 0, 'Asia/Jakarta'));
        $this->getJson('/api/v1/approvals/summary')->assertJsonPath('data.waiting_my_decision', 1);
        $this->travelTo(Carbon::create(2026, 11, 7, 19, 0, 0, 'Asia/Jakarta'));
        $this->postJson("/api/v1/approvals/{$request->id}/approve")->assertForbidden();

        // Kasir tidak dapat membuat delegasi; isi delegasi tidak dapat diubah langsung.
        $this->delegate($this->owner, ['ITEM_PRICE'])->assertForbidden();
        $this->expectException(QueryException::class);
        DB::table('approval_delegations')->where('id', $id)->update(['ends_at' => now()->addYear()]);
    }

    public function test_delegation_ends_when_delegator_loses_the_role(): void
    {
        Sanctum::actingAs($this->finance);
        // Finance belum memutuskan jenis persetujuan apa pun di MVP saat ini.
        $this->postJson('/api/v1/delegations', ['delegator_role' => Role::FINANCE, 'delegate_id' => $this->kasir->id, 'types' => ['ITEM_PRICE'], 'ends_at' => '2026-11-08T18:00:00+07:00'])
            ->assertUnprocessable()->assertJsonValidationErrors('types');
        $this->postJson('/api/v1/delegations', ['delegator_role' => Role::OWNER, 'delegate_id' => $this->kasir->id, 'types' => ['ITEM_PRICE'], 'ends_at' => '2026-11-08T18:00:00+07:00'])
            ->assertForbidden();

        $this->assertSame(0, ApprovalDelegation::query()->count());
    }
}
