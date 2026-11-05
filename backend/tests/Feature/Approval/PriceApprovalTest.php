<?php

namespace Tests\Feature\Approval;

use App\Modules\Approval\Models\ApprovalRequest;
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

class PriceApprovalTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private Item $menu;

    private User $owner;

    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::create(2026, 11, 5, 10, 0, 0, 'Asia/Jakarta'));
        $porsi = Unit::query()->create(['code' => 'porsi', 'name' => 'porsi']);
        $this->menu = Item::query()->create([
            'code' => 'MN-DIMSUM4', 'name' => 'Dimsum Mentai 4 pcs', 'item_type' => Item::TYPE_MENU, 'base_unit_id' => $porsi->id,
        ]);
        $this->owner = $this->owner();
        $this->finance = $this->userWithRole(Role::FINANCE);
    }

    private function financeRequests(array $body): int
    {
        Sanctum::actingAs($this->finance);

        return $this->postJson("/api/v1/items/{$this->menu->id}/price-requests", $body)
            ->assertCreated()
            ->assertJsonPath('data.status', 'PENDING')
            ->json('data.id');
    }

    private function currentPrice(): ?int
    {
        return $this->getJson("/api/v1/items/{$this->menu->id}")->json('data.price.current.price');
    }

    public function test_finance_request_waits_until_owner_approves(): void
    {
        $id = $this->financeRequests(['price' => 25000, 'reason' => 'Harga awal']);
        $this->assertNull($this->currentPrice());
        $this->getJson("/api/v1/items/{$this->menu->id}")->assertJsonPath('data.price.open_request.id', $id);

        Sanctum::actingAs($this->owner);
        $this->getJson('/api/v1/approvals/summary')->assertJsonPath('data.waiting_my_decision', 1);
        $this->getJson('/api/v1/approvals?status=OPEN')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.can.decide', true)
            ->assertJsonPath('data.0.summary', 'belum ada → Rp25.000, langsung berlaku');

        $this->postJson("/api/v1/approvals/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'APPROVED');

        $this->assertSame(25000, $this->currentPrice());
        $this->getJson("/api/v1/items/{$this->menu->id}")
            ->assertJsonPath('data.price.open_request', null)
            ->assertJsonPath('data.price.history.0.approval_number', ApprovalRequest::query()->find($id)->number);
        $this->getJson('/api/v1/items?type=MENU')->assertJsonPath('data.0.current_price', 25000);

        $this->assertSame(
            ['APPROVAL_SUBMITTED', 'APPROVAL_APPROVED', 'ITEM_PRICE_SET'],
            AuditLog::query()->whereIn('action', ['APPROVAL_SUBMITTED', 'APPROVAL_APPROVED', 'ITEM_PRICE_SET'])->orderBy('id')->pluck('action')->all(),
        );
    }

    public function test_owner_rejects_with_reason_and_price_stays(): void
    {
        $id = $this->financeRequests(['price' => 25000]);
        Sanctum::actingAs($this->owner);

        $this->postJson("/api/v1/approvals/{$id}/reject")->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->postJson("/api/v1/approvals/{$id}/reject", ['note' => 'Terlalu mahal'])->assertOk()->assertJsonPath('data.status', 'REJECTED');
        $this->postJson("/api/v1/approvals/{$id}/approve")->assertUnprocessable();

        $this->assertNull($this->currentPrice());
    }

    public function test_returned_request_can_be_fixed_and_resubmitted(): void
    {
        $id = $this->financeRequests(['price' => 25000]);
        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/approvals/{$id}/return", ['note' => 'Bulatkan ke 24.000'])->assertOk()->assertJsonPath('data.status', 'RETURNED');

        Sanctum::actingAs($this->finance);
        $this->getJson('/api/v1/approvals/summary')->assertJsonPath('data.returned_to_me', 1);
        $this->postJson("/api/v1/approvals/{$id}/resubmit", ['price' => 24000, 'reason' => 'Sudah dibulatkan'])
            ->assertOk()
            ->assertJsonPath('data.status', 'PENDING')
            ->assertJsonPath('data.payload.price', 24000);

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/approvals/{$id}/approve")->assertOk()
            ->assertJsonPath('data.events.*.action', ['SUBMITTED', 'RETURNED', 'RESUBMITTED', 'APPROVED']);
        $this->assertSame(24000, $this->currentPrice());
    }

    public function test_only_one_open_request_per_menu(): void
    {
        $this->financeRequests(['price' => 25000]);
        $this->postJson("/api/v1/items/{$this->menu->id}/price-requests", ['price' => 26000])
            ->assertUnprocessable()->assertJsonValidationErrors('request');
    }

    public function test_finance_cannot_decide_and_others_cannot_request(): void
    {
        $id = $this->financeRequests(['price' => 25000]);
        $this->postJson("/api/v1/approvals/{$id}/approve")->assertForbidden();

        Sanctum::actingAs($this->userWithRole(Role::CASHIER, [$this->outlet()->id]));
        $this->postJson("/api/v1/items/{$this->menu->id}/price-requests", ['price' => 1000])->assertForbidden();
        $this->getJson('/api/v1/approvals')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/approvals/{$id}")->assertForbidden();
    }

    public function test_requester_cancels_open_request(): void
    {
        $id = $this->financeRequests(['price' => 25000]);
        $this->postJson("/api/v1/approvals/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'CANCELLED');

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/approvals/{$id}/cancel")->assertForbidden();
    }

    public function test_owner_sets_price_directly_and_it_is_flagged(): void
    {
        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/items/{$this->menu->id}/prices", ['price' => 25000])->assertCreated();

        $this->assertSame(25000, $this->currentPrice());
        $this->assertSame(['OWNER_DIRECT'], AuditLog::query()->where('action', 'ITEM_PRICE_SET')->sole()->flags);
        $this->postJson("/api/v1/items/{$this->menu->id}/price-requests", ['price' => 1])->assertForbidden();
    }

    public function test_owner_direct_change_waits_for_open_request_decision(): void
    {
        $this->financeRequests(['price' => 25000]);
        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/items/{$this->menu->id}/prices", ['price' => 26000])
            ->assertUnprocessable()->assertJsonValidationErrors('price');
    }

    public function test_scheduled_price_starts_on_its_date(): void
    {
        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/items/{$this->menu->id}/prices", ['price' => 25000])->assertCreated();
        $this->postJson("/api/v1/items/{$this->menu->id}/prices", ['price' => 27000, 'effective_date' => '2026-12-01'])->assertCreated();

        $this->assertSame(25000, $this->currentPrice());
        $this->getJson("/api/v1/items/{$this->menu->id}")->assertJsonPath('data.price.upcoming.0.price', 27000);

        $this->travelTo(Carbon::create(2026, 11, 30, 23, 59, 0, 'Asia/Jakarta'));
        $this->assertSame(25000, $this->currentPrice());

        $this->travelTo(Carbon::create(2026, 12, 1, 0, 0, 1, 'Asia/Jakarta'));
        $this->assertSame(27000, $this->currentPrice());
    }

    public function test_newer_decision_replaces_older_scheduled_price(): void
    {
        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/items/{$this->menu->id}/prices", ['price' => 27000, 'effective_date' => '2026-12-01'])->assertCreated();
        $this->postJson("/api/v1/items/{$this->menu->id}/prices", ['price' => 24000])->assertCreated();

        $this->getJson("/api/v1/items/{$this->menu->id}")
            ->assertJsonCount(0, 'data.price.upcoming')
            ->assertJsonPath('data.price.history.0.superseded', false)
            ->assertJsonPath('data.price.history.1.superseded', true);
        $this->travelTo(Carbon::create(2026, 12, 2, 9, 0, 0, 'Asia/Jakarta'));
        $this->assertSame(24000, $this->currentPrice());
    }

    public function test_price_rules(): void
    {
        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/items/{$this->menu->id}/prices", ['price' => 25000, 'effective_date' => '2026-11-04'])
            ->assertUnprocessable()->assertJsonValidationErrors('effective_date');
        $this->postJson("/api/v1/items/{$this->menu->id}/prices", ['price' => 0])->assertUnprocessable()->assertJsonValidationErrors('price');

        $raw = Item::query()->create(['code' => 'BB-X', 'name' => 'Bahan', 'item_type' => Item::TYPE_RAW, 'base_unit_id' => $this->menu->base_unit_id]);
        $this->postJson("/api/v1/items/{$raw->id}/prices", ['price' => 1000])->assertUnprocessable()->assertJsonValidationErrors('price');

        $this->postJson("/api/v1/items/{$this->menu->id}/prices", ['price' => 25000])->assertCreated();
        $this->postJson("/api/v1/items/{$this->menu->id}/prices", ['price' => 25000])->assertUnprocessable();
    }

    public function test_price_and_approval_history_cannot_be_changed_in_database(): void
    {
        $id = $this->financeRequests(['price' => 25000]);
        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/approvals/{$id}/approve")->assertOk();

        foreach (['UPDATE item_prices SET price = 1', 'DELETE FROM item_prices', 'UPDATE approval_events SET note = \'x\'', 'DELETE FROM approval_events'] as $sql) {
            try {
                DB::transaction(fn () => DB::statement($sql));
                $this->fail("Seharusnya ditolak: {$sql}");
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }
    }
}
