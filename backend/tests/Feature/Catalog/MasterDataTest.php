<?php

namespace Tests\Feature\Catalog;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Models\PaymentMethod;
use App\Modules\Catalog\Models\Supplier;
use App\Modules\Identity\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class MasterDataTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private function supplierPayload(array $overrides = []): array
    {
        return [
            'code' => 'sup-ayam',
            'name' => 'Toko Daging',
            'contact_name' => 'Pak Budi',
            'phone' => '0812-1234-5678',
            'payment_term_days' => 14,
            'bank_account' => 'BCA 123 a.n. Toko Daging',
            ...$overrides,
        ];
    }

    public function test_finance_manages_suppliers_and_changes_are_audited(): void
    {
        Sanctum::actingAs($this->userWithRole(Role::FINANCE));

        $id = $this->postJson('/api/v1/suppliers', $this->supplierPayload())
            ->assertCreated()
            ->assertJsonPath('data.code', 'SUP-AYAM')
            ->assertJsonPath('data.payment_term_days', 14)
            ->json('data.id');

        $this->patchJson("/api/v1/suppliers/{$id}", $this->supplierPayload(['name' => 'Toko Daging Segar', 'payment_term_days' => 30]))
            ->assertOk()
            ->assertJsonPath('data.name', 'Toko Daging Segar')
            ->assertJsonPath('data.code', 'SUP-AYAM');

        $this->postJson("/api/v1/suppliers/{$id}/deactivate")->assertOk()->assertJsonPath('data.is_active', false);

        $this->assertSame(
            ['SUPPLIER_CREATED', 'SUPPLIER_UPDATED', 'SUPPLIER_DEACTIVATED'],
            AuditLog::query()->where('subject_type', 'supplier')->orderBy('id')->pluck('action')->all(),
        );
        $this->assertSame(['dari' => 14, 'menjadi' => 30], AuditLog::query()->where('action', 'SUPPLIER_UPDATED')->sole()->changes['payment_term_days']);
    }

    public function test_supplier_code_is_unique_regardless_of_case(): void
    {
        Sanctum::actingAs($this->userWithRole(Role::FINANCE));
        $this->postJson('/api/v1/suppliers', $this->supplierPayload())->assertCreated();
        $this->postJson('/api/v1/suppliers', $this->supplierPayload(['code' => 'SUP-Ayam']))->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_owner_sees_suppliers_but_only_finance_changes_them(): void
    {
        Supplier::query()->create(['code' => 'SUP-1', 'name' => 'Supplier 1', 'payment_term_days' => 0]);
        $this->actingAsOwner();

        $this->getJson('/api/v1/suppliers')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/suppliers', $this->supplierPayload())->assertForbidden();
    }

    public function test_cashier_and_warehouse_cannot_open_supplier_data(): void
    {
        Sanctum::actingAs($this->userWithRole(Role::CASHIER, [$this->outlet()->id]));
        $this->getJson('/api/v1/suppliers')->assertForbidden();

        Sanctum::actingAs($this->userWithRole(Role::WAREHOUSE, [$this->warehouse()->id]));
        $this->getJson('/api/v1/suppliers')->assertForbidden();
    }

    public function test_four_payment_methods_exist_and_cash_cannot_be_deactivated(): void
    {
        $this->actingAsOwner();

        $this->getJson('/api/v1/payment-methods')->assertOk()->assertJsonCount(4, 'data');

        $cash = PaymentMethod::query()->where('code', PaymentMethod::CASH)->sole();
        $this->postJson("/api/v1/payment-methods/{$cash->id}/deactivate")->assertUnprocessable();
        $this->assertTrue($cash->fresh()->is_active);
    }

    public function test_owner_adds_non_cash_payment_method(): void
    {
        $this->actingAsOwner();

        $this->postJson('/api/v1/payment-methods', ['code' => 'gopay', 'name' => 'GoPay'])
            ->assertCreated()
            ->assertJsonPath('data.code', 'GOPAY')
            ->assertJsonPath('data.is_cash', false);

        $this->postJson('/api/v1/payment-methods', ['code' => 'GOPAY', 'name' => 'GoPay lagi'])->assertUnprocessable();
    }

    public function test_owner_manages_expense_categories_and_others_only_read(): void
    {
        $this->actingAsOwner();
        $id = $this->postJson('/api/v1/expense-categories', ['code' => 'gas', 'name' => 'Gas'])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/expense-categories/{$id}", ['name' => 'Gas LPG'])->assertOk()->assertJsonPath('data.name', 'Gas LPG');
        $this->postJson("/api/v1/expense-categories/{$id}/deactivate")->assertOk()->assertJsonPath('data.is_active', false);

        $this->assertSame(3, AuditLog::query()->where('action', 'like', 'EXPENSE_CATEGORY_%')->count());

        Sanctum::actingAs($this->userWithRole(Role::CASHIER, [$this->outlet()->id]));
        $this->getJson('/api/v1/expense-categories')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/expense-categories', ['code' => 'X', 'name' => 'X'])->assertForbidden();
    }
}
