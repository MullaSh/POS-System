<?php

namespace Tests\Feature\Sales;

use App\Modules\Approval\Models\ApprovalRequest;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\PaymentMethod;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Catalog\Services\PriceService;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Location\Models\Location;
use App\Modules\Recipe\Services\RecipeService;
use App\Modules\Sales\Models\CashierShift;
use App\Modules\Sales\Models\Sale;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class PosTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    /** @var array<string, Unit> */
    private array $u = [];

    /** @var array<string, Item> */
    private array $i = [];

    private Location $otl1;

    private Location $otl2;

    private User $owner;

    private User $finance;

    private User $gudang;

    private User $kasir1;

    private User $kasir1b;

    private User $kasir2;

    private int $cash;

    private int $qris;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::create(2026, 11, 6, 10, 0, 0, 'Asia/Jakarta'));
        foreach (['g' => 'gram', 'ml' => 'mililiter', 'pcs' => 'pcs', 'porsi' => 'porsi'] as $code => $name) {
            $this->u[$code] = Unit::query()->create(['code' => $code, 'name' => $name]);
        }
        $this->item('SJ-DIMSUM', Item::TYPE_SEMI_FINISHED, 'pcs');
        $this->item('BP-MENTAI', Item::TYPE_SUPPORTING, 'ml');
        $this->item('PK-BOWL', Item::TYPE_PACKAGING, 'pcs');
        $this->item('MN-DIMSUM4', Item::TYPE_MENU, 'porsi');
        $this->item('MN-EXTRA', Item::TYPE_MENU, 'porsi');
        $this->item('MN-BARU', Item::TYPE_MENU, 'porsi');

        $gdg = $this->warehouse();
        $this->otl1 = $this->outlet('OTL01');
        $this->otl2 = $this->outlet('OTL02');
        $this->owner = $this->owner();
        $this->finance = $this->userWithRole(Role::FINANCE);
        $this->gudang = $this->userWithRole(Role::WAREHOUSE, [$gdg->id]);
        $this->kasir1 = $this->userWithRole(Role::CASHIER, [$this->otl1->id]);
        $this->kasir1b = $this->userWithRole(Role::CASHIER, [$this->otl1->id]);
        $this->kasir2 = $this->userWithRole(Role::CASHIER, [$this->otl2->id]);
        $this->cash = PaymentMethod::query()->where('code', 'CASH')->value('id');
        $this->qris = PaymentMethod::query()->where('code', 'QRIS')->value('id');

        $prices = app(PriceService::class);
        $prices->setDirect($this->i['MN-DIMSUM4'], ['price' => 25000], $this->owner);
        $prices->setDirect($this->i['MN-EXTRA'], ['price' => 5000], $this->owner);
        $prices->setDirect($this->i['MN-BARU'], ['price' => 10000], $this->owner);

        $recipes = app(RecipeService::class);
        $recipes->create($this->i['MN-DIMSUM4'], ['lines' => [$this->rl('SJ-DIMSUM', 'pcs', 4), $this->rl('BP-MENTAI', 'ml', 30), $this->rl('PK-BOWL', 'pcs', 1)]], $this->owner);
        $recipes->create($this->i['MN-EXTRA'], ['lines' => [$this->rl('BP-MENTAI', 'ml', 30)]], $this->owner);
        // MN-BARU sengaja tanpa resep.

        $this->as($this->owner);
        $this->postJson('/api/v1/stock/openings', ['location_id' => $this->otl1->id, 'lines' => [
            ['item_id' => $this->i['SJ-DIMSUM']->id, 'unit_id' => $this->u['pcs']->id, 'qty' => 40, 'unit_cost' => 1500],
            ['item_id' => $this->i['BP-MENTAI']->id, 'unit_id' => $this->u['ml']->id, 'qty' => 1000, 'unit_cost' => 90],
            ['item_id' => $this->i['PK-BOWL']->id, 'unit_id' => $this->u['pcs']->id, 'qty' => 2, 'unit_cost' => 500],
        ]])->assertCreated();
    }

    private function item(string $code, string $type, string $base): Item
    {
        $item = Item::query()->create(['code' => $code, 'name' => $code, 'item_type' => $type, 'base_unit_id' => $this->u[$base]->id]);
        $item->units()->create(['unit_id' => $this->u[$base]->id, 'factor_to_base' => 1]);

        return $this->i[$code] = $item;
    }

    /** @return array<string, mixed> */
    private function rl(string $item, string $unit, $qty): array
    {
        return ['item_id' => $this->i[$item]->id, 'unit_id' => $this->u[$unit]->id, 'qty' => $qty];
    }

    private function as(User $user): void
    {
        Sanctum::actingAs($user);
    }

    private function qty(string $item, Location $location): string
    {
        return (string) (StockBalance::query()->where(['item_id' => $this->i[$item]->id, 'location_id' => $location->id])->value('qty') ?? '0');
    }

    private function openShift(User $kasir, ?Location $location = null, int $float = 50000): int
    {
        $this->as($kasir);

        return $this->postJson('/api/v1/shifts', ['location_id' => ($location ?? $this->otl1)->id, 'float_counted' => $float])
            ->assertCreated()->json('data.id');
    }

    /** @param  list<array{0: string, 1: int}>  $lines */
    private function sell(array $lines, array $payments, ?Location $location = null): TestResponse
    {
        return $this->postJson('/api/v1/sales', [
            'location_id' => ($location ?? $this->otl1)->id,
            'channel' => Sale::CHANNEL_DINE_IN,
            'lines' => array_map(fn ($l) => ['item_id' => $this->i[$l[0]]->id, 'qty' => $l[1]], $lines),
            'payments' => $payments,
        ]);
    }

    private function cashPay(int $amount): array
    {
        return [['payment_method_id' => $this->cash, 'amount' => $amount]];
    }

    public function test_shift_opening_rules(): void
    {
        // Gudang bukan kasir; kasir outlet lain tidak boleh.
        $this->as($this->gudang);
        $this->postJson('/api/v1/shifts', ['location_id' => $this->otl1->id, 'float_counted' => 50000])->assertForbidden();
        $this->as($this->kasir2);
        $this->postJson('/api/v1/shifts', ['location_id' => $this->otl1->id, 'float_counted' => 50000])->assertForbidden();

        // Cash Float berbeda dari Rp50.000 wajib dijelaskan.
        $this->as($this->kasir1);
        $this->postJson('/api/v1/shifts', ['location_id' => $this->otl1->id, 'float_counted' => 45000])
            ->assertUnprocessable()->assertJsonValidationErrors('note');
        $id = $this->postJson('/api/v1/shifts', ['location_id' => $this->otl1->id, 'float_counted' => 45000, 'note' => 'Kurang 5 ribu dari kemarin'])
            ->assertCreated()->assertJsonPath('data.float_expected', 50000)->json('data.id');
        $this->assertSame(['FLOAT_DIFFERENCE'], AuditLog::query()->where('action', 'SHIFT_OPENED')->first()->flags);

        // Satu shift berjalan per outlet: kasir lain menunggu.
        $this->as($this->kasir1b);
        $this->postJson('/api/v1/shifts', ['location_id' => $this->otl1->id, 'float_counted' => 50000])
            ->assertUnprocessable()->assertJsonValidationErrors('location_id');
        // ... dan tidak bisa berjualan di shift orang lain.
        $this->sell([['MN-DIMSUM4', 1]], $this->cashPay(25000))->assertUnprocessable()->assertJsonValidationErrors('shift');

        // Kasir lain tidak dapat melihat shift itu (shift dan kas milik sendiri, 2D-Q2).
        $this->getJson("/api/v1/shifts/{$id}")->assertForbidden();
        $this->as($this->finance);
        $this->getJson("/api/v1/shifts/{$id}")->assertOk()->assertJsonPath('data.float_counted', 45000);
    }

    public function test_sale_deducts_outlet_stock_by_recipe_and_locks_price_and_hpp(): void
    {
        $this->openShift($this->kasir1);
        $res = $this->sell([['MN-DIMSUM4', 2], ['MN-EXTRA', 1]], $this->cashPay(100000))->assertCreated();
        $res->assertJsonPath('data.total', 55000)
            ->assertJsonPath('data.change_total', 45000)
            ->assertJsonPath('data.payments.0.amount', 55000)
            ->assertJsonPath('data.payments.0.received', 100000)
            ->assertJsonPath('data.lines.0.unit_hpp', null) // kasir tidak melihat HPP
            ->assertJsonPath('warnings', []);

        // C7: 2 porsi × (4 dimsum, 30 ml mentai, 1 bowl) + 1 extra (30 ml mentai).
        $this->assertSame('32.0000', $this->qty('SJ-DIMSUM', $this->otl1));
        $this->assertSame('910.0000', $this->qty('BP-MENTAI', $this->otl1));
        $this->assertSame('0.0000', $this->qty('PK-BOWL', $this->otl1));
        $this->assertSame(4, StockMovement::query()->where('type', StockMovement::SALE)->count());

        // HPP terkunci: 4×1500 + 30×90 + 500 = 9.200 per porsi dimsum; 2.700 per extra.
        $sale = Sale::query()->firstOrFail();
        $this->assertSame('21100.0000', $sale->hpp_total);
        $this->as($this->finance);
        $this->getJson("/api/v1/sales/{$sale->id}")->assertOk()
            ->assertJsonPath('data.lines.0.unit_hpp', '9200.0000')
            ->assertJsonPath('data.lines.1.unit_hpp', '2700.0000')
            ->assertJsonPath('data.hpp_complete', true);

        // Harga jual dan harga pokok berubah sesudahnya; transaksi lama tidak berubah (KP-05).
        app(PriceService::class)->setDirect($this->i['MN-DIMSUM4'], ['price' => 27000], $this->owner);
        $this->as($this->owner);
        $this->postJson('/api/v1/stock/openings', ['location_id' => $this->otl2->id, 'lines' => [
            ['item_id' => $this->i['SJ-DIMSUM']->id, 'unit_id' => $this->u['pcs']->id, 'qty' => 10, 'unit_cost' => 9999],
        ]])->assertCreated();
        $this->getJson("/api/v1/sales/{$sale->id}")->assertJsonPath('data.lines.0.unit_price', 25000)->assertJsonPath('data.hpp_total', '21100.0000');

        // Catatan penjualan tidak dapat diubah (3B-Q1).
        $this->expectException(QueryException::class);
        DB::table('sales')->where('id', $sale->id)->update(['total' => 1]);
    }

    public function test_sale_validation_and_negative_stock_warning(): void
    {
        $this->as($this->kasir1);
        $this->sell([['MN-DIMSUM4', 1]], $this->cashPay(25000))->assertUnprocessable()->assertJsonValidationErrors('shift');
        $this->openShift($this->kasir1);

        $this->sell([['MN-BARU', 1]], $this->cashPay(10000))->assertUnprocessable()->assertJsonValidationErrors('lines.0.item_id');
        $this->sell([['SJ-DIMSUM', 1]], $this->cashPay(10000))->assertUnprocessable()->assertJsonValidationErrors('lines.0.item_id');
        $this->sell([['MN-DIMSUM4', 1]], $this->cashPay(20000))->assertUnprocessable()->assertJsonValidationErrors('payments.0.amount');
        $this->sell([['MN-DIMSUM4', 1]], [['payment_method_id' => $this->qris, 'amount' => 30000]])->assertUnprocessable()->assertJsonValidationErrors('payments');
        $this->sell([['MN-DIMSUM4', 1]], [['payment_method_id' => $this->qris, 'amount' => 20000]])->assertUnprocessable()->assertJsonValidationErrors('payments');
        $this->sell([['MN-DIMSUM4', 1]], [['payment_method_id' => $this->qris, 'amount' => 25000], ['payment_method_id' => $this->cash, 'amount' => 5000]])
            ->assertUnprocessable()->assertJsonValidationErrors('payments');

        // Bayar campur: QRIS 10.000 + tunai 20.000 untuk tagihan 25.000 → kembalian 5.000 dari tunai.
        $this->sell([['MN-DIMSUM4', 1]], [['payment_method_id' => $this->qris, 'amount' => 10000, 'reference' => '1234'], ['payment_method_id' => $this->cash, 'amount' => 20000]])
            ->assertCreated()
            ->assertJsonPath('data.payments.0.amount', 10000)
            ->assertJsonPath('data.payments.1.amount', 15000)
            ->assertJsonPath('data.payments.1.change', 5000);

        // Paper bowl tinggal 1; jual 2 porsi → stok minus, transaksi tetap jalan dengan peringatan (KP-02).
        $res = $this->sell([['MN-DIMSUM4', 2]], $this->cashPay(50000))->assertCreated();
        $this->assertCount(1, $res->json('warnings'));
        $this->assertStringContainsString('PK-BOWL', $res->json('warnings.0'));
        $this->assertSame('-1.0000', $this->qty('PK-BOWL', $this->otl1));
    }

    public function test_blind_closing_keeps_cash_float_and_locks_the_count(): void
    {
        $id = $this->openShift($this->kasir1);
        $this->sell([['MN-DIMSUM4', 1]], $this->cashPay(30000))->assertCreated();          // tunai 25.000
        $this->sell([['MN-EXTRA', 1]], [['payment_method_id' => $this->qris, 'amount' => 5000]])->assertCreated();

        // Sebelum hitung dikirim, kasir tidak melihat angka sistem.
        $this->getJson("/api/v1/shifts/{$id}")->assertOk()
            ->assertJsonPath('data.expected_cash', null)
            ->assertJsonPath('data.totals', null)
            ->assertJsonPath('data.sale_count', 2);

        // Seharusnya 50.000 + 25.000 = 75.000; dihitung 70.000.
        $this->postJson("/api/v1/shifts/{$id}/count", ['counted_cash' => 70000])->assertOk()
            ->assertJsonPath('data.status', 'COUNTED')
            ->assertJsonPath('data.expected_cash', 75000)
            ->assertJsonPath('data.cash_difference', -5000);
        // Hasil hitung dikunci; tidak bisa berjualan lagi; tutup wajib alasan.
        $this->postJson("/api/v1/shifts/{$id}/count", ['counted_cash' => 75000])->assertUnprocessable();
        $this->sell([['MN-EXTRA', 1]], $this->cashPay(5000))->assertUnprocessable()->assertJsonValidationErrors('shift');
        $this->postJson("/api/v1/shifts/{$id}/close", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/shifts/{$id}/close", ['reason' => 'Salah beri kembalian'])->assertOk()
            ->assertJsonPath('data.status', 'CLOSED')
            ->assertJsonPath('data.float_kept', 50000)
            ->assertJsonPath('data.sales_cash', 20000);
        $this->assertSame(['CASH_DIFFERENCE'], AuditLog::query()->where('action', 'SHIFT_CLOSED')->first()->flags);
        $this->expectsDatabaseUpdateToFail(fn () => DB::table('cashier_shifts')->where('id', $id)->update(['close_note' => 'x']));

        // Shift berikutnya: Cash Float yang ditinggalkan menjadi angka seharusnya (KOD-01).
        $this->as($this->kasir1b);
        $next = $this->postJson('/api/v1/shifts', ['location_id' => $this->otl1->id, 'float_counted' => 50000])->assertCreated();
        $next->assertJsonPath('data.float_expected', 50000);
        $this->postJson("/api/v1/shifts/{$next->json('data.id')}/count", ['counted_cash' => 50000])->assertOk()
            ->assertJsonPath('data.status', 'CLOSED')->assertJsonPath('data.sales_cash', 0);
    }

    private function expectsDatabaseUpdateToFail(callable $fn): void
    {
        try {
            $fn();
            $this->fail('Perubahan seharusnya ditolak database.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }

    public function test_refund_after_payment_needs_owner_approval_and_reverses_stock_and_cash(): void
    {
        $shiftId = $this->openShift($this->kasir1);
        $saleId = $this->sell([['MN-DIMSUM4', 2]], $this->cashPay(50000))->assertCreated()->json('data.id');
        $sale = Sale::query()->with('lines')->findOrFail($saleId);
        $lineId = $sale->lines[0]->id;
        $this->assertSame('32.0000', $this->qty('SJ-DIMSUM', $this->otl1));

        // Alasan wajib; tidak melebihi jumlah yang dijual.
        $this->postJson("/api/v1/sales/{$saleId}/refunds", ['reason_code' => 'CUSTOMER_CANCEL', 'lines' => [['sale_line_id' => $lineId, 'qty' => 1, 'condition' => 'NOT_MADE']]])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/sales/{$saleId}/refunds", ['reason_code' => 'CUSTOMER_CANCEL', 'reason' => 'x', 'lines' => [['sale_line_id' => $lineId, 'qty' => 3, 'condition' => 'NOT_MADE']]])
            ->assertUnprocessable()->assertJsonValidationErrors('lines.0.qty');

        // Refund 1 porsi yang belum dibuat.
        $this->postJson("/api/v1/sales/{$saleId}/refunds", ['reason_code' => 'CUSTOMER_CANCEL', 'reason' => 'Pelanggan batal satu porsi', 'lines' => [['sale_line_id' => $lineId, 'qty' => 1, 'condition' => 'NOT_MADE']]])
            ->assertCreated()->assertJsonPath('data.has_pending_refund', true)->assertJsonPath('data.can.refund', false);
        $approval = ApprovalRequest::query()->where('type', ApprovalRequest::TYPE_SALE_REFUND)->firstOrFail();
        $this->assertSame('REFUND', $approval->payload['kind']);
        $this->assertSame(25000, $approval->payload['amount']);
        $this->assertTrue($approval->payload['payments'][0]['is_cash']);

        // Pengajuan kedua untuk transaksi yang sama ditolak; shift belum bisa ditutup.
        $this->postJson("/api/v1/sales/{$saleId}/refunds", ['reason_code' => 'OTHER', 'reason' => 'x', 'lines' => [['sale_line_id' => $lineId, 'qty' => 1, 'condition' => 'MADE']]])
            ->assertUnprocessable()->assertJsonValidationErrors('sale');
        $this->postJson("/api/v1/shifts/{$shiftId}/count", ['counted_cash' => 100000])->assertUnprocessable()->assertJsonValidationErrors('counted_cash');

        // Kasir lain dan Finance (tanpa delegasi) tidak dapat memutuskan.
        $this->as($this->kasir1b);
        $this->postJson("/api/v1/approvals/{$approval->id}/approve")->assertForbidden();
        $this->as($this->finance);
        $this->postJson("/api/v1/approvals/{$approval->id}/approve")->assertForbidden();

        $this->as($this->owner);
        $this->getJson('/api/v1/approvals/summary')->assertJsonPath('data.waiting_my_decision', 1);
        $this->postJson("/api/v1/approvals/{$approval->id}/approve", ['note' => 'Oke'])->assertOk();

        // Stok kembali (catatan pembalik), uang tunai keluar dari laci shift.
        $this->assertSame('36.0000', $this->qty('SJ-DIMSUM', $this->otl1));
        $this->assertSame(3, StockMovement::query()->where('type', StockMovement::SALE_REVERSAL)->count());
        $this->getJson("/api/v1/sales/{$saleId}")->assertJsonPath('data.status', 'PARTLY_REFUNDED')
            ->assertJsonPath('data.refunded_amount', 25000)->assertJsonPath('data.lines.0.refunded_qty', 1)
            ->assertJsonPath('data.refunds.0.kind', 'REFUND');

        // Sisa porsi: sudah dibuat dan dibuang → stok tidak kembali.
        $this->as($this->kasir1);
        $this->postJson("/api/v1/sales/{$saleId}/refunds", ['reason_code' => 'PRODUCT_ISSUE', 'reason' => 'Jatuh', 'lines' => [['sale_line_id' => $lineId, 'qty' => 1, 'condition' => 'MADE']]])
            ->assertCreated();
        $second = ApprovalRequest::query()->where('type', ApprovalRequest::TYPE_SALE_REFUND)->where('status', 'PENDING')->firstOrFail();
        $this->as($this->owner);
        $this->postJson("/api/v1/approvals/{$second->id}/approve")->assertOk();
        $this->assertSame('36.0000', $this->qty('SJ-DIMSUM', $this->otl1));
        $this->getJson("/api/v1/sales/{$saleId}")->assertJsonPath('data.status', 'VOIDED')->assertJsonPath('data.can.refund', false);

        // Laci: 50.000 + 50.000 − 50.000 = 50.000.
        $this->as($this->kasir1);
        $this->postJson("/api/v1/shifts/{$shiftId}/count", ['counted_cash' => 50000])->assertOk()->assertJsonPath('data.status', 'CLOSED');
        $this->assertSame(2, AuditLog::query()->where('action', 'SALE_REFUND_APPLIED')->count());
    }

    public function test_full_void_and_refund_is_split_back_to_original_methods(): void
    {
        $this->openShift($this->kasir1);
        $saleId = $this->sell([['MN-DIMSUM4', 1], ['MN-EXTRA', 1]], [['payment_method_id' => $this->qris, 'amount' => 20000], ['payment_method_id' => $this->cash, 'amount' => 10000]])
            ->assertCreated()->json('data.id');
        $sale = Sale::query()->with('lines')->findOrFail($saleId);
        $this->postJson("/api/v1/sales/{$saleId}/refunds", ['reason_code' => 'WRONG_INPUT', 'reason' => 'Salah input menu', 'lines' => $sale->lines->map(fn ($l) => [
            'sale_line_id' => $l->id, 'qty' => $l->qty, 'condition' => 'NOT_MADE',
        ])->all()])->assertCreated();
        $approval = ApprovalRequest::query()->where('type', ApprovalRequest::TYPE_SALE_REFUND)->firstOrFail();
        $this->assertSame('VOID', $approval->payload['kind']);
        // Tunai lebih dulu (10.000), sisanya QRIS (20.000).
        $this->assertSame([[true, 10000], [false, 20000]], array_map(fn ($p) => [$p['is_cash'], $p['amount']], $approval->payload['payments']));
    }

    public function test_cashiers_only_see_sales_of_their_outlets(): void
    {
        $this->openShift($this->kasir1);
        $saleId = $this->sell([['MN-EXTRA', 1]], $this->cashPay(5000))->assertCreated()->json('data.id');

        $this->as($this->kasir1b);
        $this->getJson("/api/v1/sales/{$saleId}")->assertOk()->assertJsonPath('data.can.refund', false);
        $this->as($this->kasir2);
        $this->getJson("/api/v1/sales/{$saleId}")->assertForbidden();
        $this->assertSame(0, $this->getJson('/api/v1/sales')->assertOk()->json('total'));
        $this->getJson('/api/v1/shifts')->assertOk()->assertJsonPath('total', 0);
        $this->as($this->gudang);
        $this->getJson('/api/v1/sales')->assertForbidden();
        $this->as($this->owner);
        $this->assertSame(1, $this->getJson('/api/v1/sales')->json('total'));
        $this->getJson('/api/v1/pos/context')->assertForbidden();

        $this->as($this->kasir1);
        $ctx = $this->getJson('/api/v1/pos/context')->assertOk();
        $ctx->assertJsonPath('data.shift.is_mine', true)->assertJsonPath('data.shift.expected_cash', null);
        $menu = collect($ctx->json('data.menu'))->keyBy('code');
        $this->assertTrue($menu['MN-DIMSUM4']['sellable']);
        $this->assertSame('Belum punya resep', $menu['MN-BARU']['problem']);
    }

    public function test_shift_running_states_are_unique_per_outlet_in_database(): void
    {
        $this->openShift($this->kasir1);
        $this->expectException(QueryException::class);
        CashierShift::query()->create([
            'number' => 'X', 'location_id' => $this->otl1->id, 'cashier_id' => $this->kasir1b->id, 'status' => 'OPEN',
            'opened_at' => now(), 'float_expected' => 0, 'float_counted' => 0,
        ]);
    }
}
