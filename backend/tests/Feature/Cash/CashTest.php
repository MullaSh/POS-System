<?php

namespace Tests\Feature\Cash;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Cash\Models\CashHandover;
use App\Modules\Catalog\Models\ExpenseCategory;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\PaymentMethod;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Catalog\Services\PriceService;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Location\Models\Location;
use App\Modules\Recipe\Services\RecipeService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/** Kas (irisan 6): serah terima, Petty Cash dan biaya, pencocokan non-tunai, selisih. */
class CashTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private Location $gdg;

    private Location $otl1;

    private Location $otl2;

    private User $owner;

    private User $finance;

    private User $gudang;

    private User $gudang2;

    private User $kasir1;

    private User $kasir2;

    private int $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::create(2026, 11, 6, 10, 0, 0, 'Asia/Jakarta'));
        $this->gdg = $this->warehouse();
        $this->otl1 = $this->outlet('OTL01');
        $this->otl2 = $this->outlet('OTL02');
        $this->owner = $this->owner();
        $this->finance = $this->userWithRole(Role::FINANCE);
        $this->gudang = $this->userWithRole(Role::WAREHOUSE, [$this->gdg->id]);
        $this->gudang2 = $this->userWithRole(Role::WAREHOUSE, [$this->gdg->id]);
        $this->kasir1 = $this->userWithRole(Role::CASHIER, [$this->otl1->id]);
        $this->kasir2 = $this->userWithRole(Role::CASHIER, [$this->otl2->id]);
        $this->category = ExpenseCategory::query()->create(['code' => 'GAS', 'name' => 'Gas'])->id;
    }

    private function as(User $user): void
    {
        Sanctum::actingAs($user);
    }

    /** Shift kasir yang ditutup dengan uang laci tertentu (tanpa penjualan): Sales Cash = laci − Rp50.000. */
    private function closedShift(User $kasir, Location $outlet, int $counted): int
    {
        $this->as($kasir);
        $id = $this->postJson('/api/v1/shifts', ['location_id' => $outlet->id, 'float_counted' => 50000])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/shifts/{$id}/count", ['counted_cash' => $counted])->assertOk();
        if ($counted !== 50000) {
            $this->postJson("/api/v1/shifts/{$id}/close", ['reason' => 'Uang setoran contoh'])->assertOk();
        }

        return $id;
    }

    public function test_sales_cash_goes_outlet_to_warehouse_to_finance_with_confirmation_at_each_step(): void
    {
        $s1 = $this->closedShift($this->kasir1, $this->otl1, 350000); // Sales Cash 300.000
        $s2 = $this->closedShift($this->kasir1, $this->otl1, 150000); // Sales Cash 100.000

        $this->as($this->kasir1);
        $this->getJson("/api/v1/cash/handovers/pending?location_id={$this->otl1->id}")->assertOk()
            ->assertJsonPath('data.total', 400000)->assertJsonCount(2, 'data.shifts');
        // Kasir outlet lain dan gudang tidak bisa mengirim dari Outlet 1.
        $this->as($this->kasir2);
        $this->postJson('/api/v1/cash/handovers', ['location_id' => $this->otl1->id, 'shift_ids' => [$s1], 'reported_amount' => 300000])->assertForbidden();

        $this->as($this->kasir1);
        // Jumlah berbeda wajib dijelaskan.
        $this->postJson('/api/v1/cash/handovers', ['location_id' => $this->otl1->id, 'shift_ids' => [$s1, $s2], 'reported_amount' => 390000])
            ->assertUnprocessable()->assertJsonValidationErrors('note');
        $h1 = $this->postJson('/api/v1/cash/handovers', ['location_id' => $this->otl1->id, 'shift_ids' => [$s1, $s2], 'reported_amount' => 400000])
            ->assertCreated()->assertJsonPath('data.expected_amount', 400000)->assertJsonPath('data.status', 'SENT')->json('data.id');
        // Shift yang sudah diserahkan tidak bisa diserahkan lagi.
        $this->postJson('/api/v1/cash/handovers', ['location_id' => $this->otl1->id, 'shift_ids' => [$s1], 'reported_amount' => 300000])
            ->assertUnprocessable()->assertJsonValidationErrors('shift_ids.0');
        // Kasir tidak bisa mengonfirmasi sendiri.
        $this->postJson("/api/v1/cash/handovers/{$h1}/receive", ['received_amount' => 400000])->assertForbidden();

        // Gudang menghitung 395.000: selisih wajib dengan bukti.
        $this->as($this->gudang);
        $this->postJson("/api/v1/cash/handovers/{$h1}/receive", ['received_amount' => 395000])->assertUnprocessable()->assertJsonValidationErrors('evidence_note');
        $this->postJson("/api/v1/cash/handovers/{$h1}/receive", ['received_amount' => 395000, 'evidence_note' => 'Dihitung bersama kasir, kurang satu lembar 5 ribu'])
            ->assertOk()->assertJsonPath('data.difference', -5000)->assertJsonPath('data.status', 'RECEIVED');
        $this->postJson("/api/v1/cash/handovers/{$h1}/receive", ['received_amount' => 400000])->assertUnprocessable();

        // Gudang meneruskan ke Finance; penerima harus orang lain dan berperan Finance.
        $this->getJson("/api/v1/cash/handovers/pending?location_id={$this->gdg->id}")->assertJsonPath('data.total', 395000);
        $h2 = $this->postJson('/api/v1/cash/handovers', ['location_id' => $this->gdg->id, 'handover_ids' => [$h1], 'reported_amount' => 395000])
            ->assertCreated()->assertJsonPath('data.stage', 'WAREHOUSE_TO_FINANCE')->json('data.id');
        $this->as($this->gudang2);
        $this->postJson("/api/v1/cash/handovers/{$h2}/receive", ['received_amount' => 395000])->assertForbidden();
        $this->as($this->finance);
        $this->postJson("/api/v1/cash/handovers/{$h2}/receive", ['received_amount' => 395000])->assertOk()->assertJsonPath('data.difference', 0);

        $this->assertSame(2, AuditLog::query()->where('action', 'CASH_HANDOVER_RECEIVED')->count());
        // Jumlah yang sudah dikonfirmasi kedua pihak tidak diubah (3C-Q1).
        $this->expectException(QueryException::class);
        DB::table('cash_handovers')->where('id', $h1)->update(['received_amount' => 400000]);
    }

    public function test_petty_cash_expenses_and_refill_by_proven_amount(): void
    {
        // Saldo tetap diatur Owner; Finance belum bisa mengisi sebelum diatur.
        $this->as($this->finance);
        $this->postJson("/api/v1/cash/petty-cash/{$this->otl1->id}/refill", ['decisions' => []])->assertUnprocessable();
        $this->putJson("/api/v1/cash/petty-cash/{$this->otl1->id}/target", ['amount' => 200000])->assertForbidden();
        $this->as($this->owner);
        $this->putJson("/api/v1/cash/petty-cash/{$this->otl1->id}/target", ['amount' => 200000])->assertOk()->assertJsonPath('data.target', 200000);

        // Isi awal: sebesar saldo tetap.
        $this->as($this->finance);
        $this->postJson("/api/v1/cash/petty-cash/{$this->otl1->id}/refill", ['decisions' => []])->assertOk()->assertJsonPath('data.balance', 200000);

        // Kasir mencatat biaya dan langsung dibayar dari Petty Cash; tidak boleh melebihi saldo.
        $this->as($this->kasir1);
        $this->postJson('/api/v1/cash/expenses', ['location_id' => $this->otl1->id, 'expense_category_id' => $this->category, 'amount' => 250000, 'description' => 'Gas'])
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
        $e1 = $this->postJson('/api/v1/cash/expenses', ['location_id' => $this->otl1->id, 'expense_category_id' => $this->category, 'amount' => 30000, 'description' => 'Isi gas', 'evidence_note' => 'Nota no. 12'])
            ->assertCreated()->assertJsonPath('data.status', 'RECORDED')->json('data.id');
        $e2 = $this->postJson('/api/v1/cash/expenses', ['location_id' => $this->otl1->id, 'expense_category_id' => $this->category, 'amount' => 20000, 'description' => 'Sabun'])
            ->assertCreated()->json('data.id');
        $e3 = $this->postJson('/api/v1/cash/expenses', ['location_id' => $this->otl1->id, 'expense_category_id' => $this->category, 'amount' => 5000, 'description' => 'Salah input'])
            ->assertCreated()->json('data.id');
        $this->postJson("/api/v1/cash/expenses/{$e3}/cancel", ['reason' => 'Salah input'])->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        $this->getJson("/api/v1/cash/petty-cash/{$this->otl1->id}")->assertJsonPath('data.balance', 150000)->assertJsonCount(2, 'data.pending');
        $this->postJson("/api/v1/cash/petty-cash/{$this->otl1->id}/request", ['note' => 'Sisa sedikit'])->assertOk()->assertJsonPath('data.request.by', $this->kasir1->name);
        $this->postJson("/api/v1/cash/petty-cash/{$this->otl1->id}/request")->assertUnprocessable();

        // Finance wajib memeriksa setiap biaya; yang ditolak wajib beralasan dan tidak diganti.
        $this->as($this->finance);
        $this->postJson("/api/v1/cash/petty-cash/{$this->otl1->id}/refill", ['decisions' => [$e1 => ['decision' => 'VERIFIED']]])
            ->assertUnprocessable()->assertJsonValidationErrors("decisions.{$e2}");
        $this->postJson("/api/v1/cash/petty-cash/{$this->otl1->id}/refill", ['decisions' => [$e1 => ['decision' => 'VERIFIED'], $e2 => ['decision' => 'REJECTED']]])
            ->assertUnprocessable()->assertJsonValidationErrors("decisions.{$e2}");
        $this->postJson("/api/v1/cash/petty-cash/{$this->otl1->id}/refill", ['decisions' => [
            $e1 => ['decision' => 'VERIFIED'], $e2 => ['decision' => 'REJECTED', 'note' => 'Tanpa nota'],
        ]])->assertOk()->assertJsonPath('data.balance', 180000)->assertJsonPath('data.request', null)->assertJsonCount(0, 'data.pending');

        // Biaya yang ditolak menjadi selisih yang diputuskan Finance; Owner melihat tetapi tidak memutuskan.
        $this->as($this->owner);
        $open = $this->getJson('/api/v1/cash/discrepancies')->assertOk()->assertJsonPath('can_resolve', false)->json('data');
        $this->assertSame([['expense', -20000]], array_map(fn ($r) => [$r['subject_type'], $r['amount']], $open));
        $this->postJson('/api/v1/cash/discrepancies/resolve', ['subject_type' => 'expense', 'subject_id' => $e2, 'decision' => 'x'])->assertForbidden();
        $this->as($this->finance);
        $this->postJson('/api/v1/cash/discrepancies/resolve', ['subject_type' => 'expense', 'subject_id' => $e2, 'decision' => 'Ditanggung kasir', 'category' => 'STAFF'])->assertCreated();
        $this->postJson('/api/v1/cash/discrepancies/resolve', ['subject_type' => 'expense', 'subject_id' => $e2, 'decision' => 'lagi'])->assertUnprocessable();
        $this->postJson('/api/v1/cash/discrepancies/resolve', ['subject_type' => 'expense', 'subject_id' => $e1, 'decision' => 'x'])->assertUnprocessable();
        $this->assertSame([], $this->getJson('/api/v1/cash/discrepancies')->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/cash/discrepancies?status=ALL')->json('data'));

        // Biaya gudang dicatat dan dibayar Finance (G-02); staf gudang tidak mencatat biaya.
        $this->postJson('/api/v1/cash/expenses', ['location_id' => $this->gdg->id, 'expense_category_id' => $this->category, 'amount' => 100000, 'description' => 'Gas gudang'])
            ->assertCreated()->assertJsonPath('data.paid_from', 'FINANCE')->assertJsonPath('data.status', 'VERIFIED');
        $this->as($this->gudang);
        $this->postJson('/api/v1/cash/expenses', ['location_id' => $this->gdg->id, 'expense_category_id' => $this->category, 'amount' => 1000, 'description' => 'x'])->assertForbidden();
    }

    public function test_daily_noncash_reconciliation_per_method(): void
    {
        $qris = PaymentMethod::query()->where('code', 'QRIS')->value('id');
        $cash = PaymentMethod::query()->where('code', 'CASH')->value('id');
        $this->sellWithQris(25000);

        $this->as($this->finance);
        $row = collect($this->getJson('/api/v1/cash/reconciliations?days=2')->assertOk()->json('data'))
            ->first(fn ($r) => $r['business_date'] === '2026-11-06' && $r['method']['code'] === 'QRIS');
        $this->assertSame(25000, $row['system_amount']);
        $this->assertNull($row['reconciliation']);

        $this->postJson('/api/v1/cash/reconciliations', ['business_date' => '2026-11-06', 'payment_method_id' => $cash, 'bank_amount' => 0])->assertUnprocessable();
        $this->postJson('/api/v1/cash/reconciliations', ['business_date' => '2026-11-07', 'payment_method_id' => $qris, 'bank_amount' => 0])->assertUnprocessable();
        $this->postJson('/api/v1/cash/reconciliations', ['business_date' => '2026-11-06', 'payment_method_id' => $qris, 'bank_amount' => 24800])
            ->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->postJson('/api/v1/cash/reconciliations', ['business_date' => '2026-11-06', 'payment_method_id' => $qris, 'bank_amount' => 24800, 'note' => 'Potongan MDR'])
            ->assertCreated()->assertJsonPath('data.difference', -200);
        $this->postJson('/api/v1/cash/reconciliations', ['business_date' => '2026-11-06', 'payment_method_id' => $qris, 'bank_amount' => 25000])
            ->assertUnprocessable()->assertJsonValidationErrors('business_date');
        $this->assertSame(-200, $this->getJson('/api/v1/cash/discrepancies')->json('data.0.amount'));

        // Kasir tidak membuka pencocokan; ringkasan dana hanya outletnya.
        $this->as($this->kasir2);
        $this->getJson('/api/v1/cash/reconciliations')->assertForbidden();
        $overview = $this->getJson('/api/v1/cash/overview')->assertOk();
        $this->assertSame(['OTL02'], array_column(array_column($overview->json('data.outlets'), 'location'), 'code'));
        $this->assertNull($overview->json('data.warehouse'));
    }

    private function sellWithQris(int $price): void
    {
        $pcs = Unit::query()->create(['code' => 'pcs', 'name' => 'pcs']);
        $porsi = Unit::query()->create(['code' => 'porsi', 'name' => 'porsi']);
        $bowl = Item::query()->create(['code' => 'PK-BOWL', 'name' => 'Bowl', 'item_type' => Item::TYPE_PACKAGING, 'base_unit_id' => $pcs->id]);
        $bowl->units()->create(['unit_id' => $pcs->id, 'factor_to_base' => 1]);
        $menu = Item::query()->create(['code' => 'MN-1', 'name' => 'Menu', 'item_type' => Item::TYPE_MENU, 'base_unit_id' => $porsi->id]);
        $menu->units()->create(['unit_id' => $porsi->id, 'factor_to_base' => 1]);
        app(PriceService::class)->setDirect($menu, ['price' => $price], $this->owner);
        app(RecipeService::class)->create($menu, ['lines' => [['item_id' => $bowl->id, 'unit_id' => $pcs->id, 'qty' => 1]]], $this->owner);

        $this->as($this->kasir1);
        $this->postJson('/api/v1/shifts', ['location_id' => $this->otl1->id, 'float_counted' => 50000])->assertCreated();
        $this->postJson('/api/v1/sales', [
            'location_id' => $this->otl1->id, 'channel' => 'TAKEAWAY',
            'lines' => [['item_id' => $menu->id, 'qty' => 1]],
            'payments' => [['payment_method_id' => PaymentMethod::query()->where('code', 'QRIS')->value('id'), 'amount' => $price]],
        ])->assertCreated();
    }

    public function test_overview_shows_three_funds_per_outlet(): void
    {
        $this->closedShift($this->kasir1, $this->otl1, 80000);
        $this->as($this->owner);
        $this->putJson("/api/v1/cash/petty-cash/{$this->otl1->id}/target", ['amount' => 200000])->assertOk();
        $data = $this->getJson('/api/v1/cash/overview')->assertOk()->json('data');
        $o1 = collect($data['outlets'])->firstWhere('location.code', 'OTL01');
        $this->assertSame(50000, $o1['cash_float']);
        $this->assertSame(30000, $o1['sales_cash_at_outlet']);
        $this->assertSame(1, $o1['shifts_to_hand_over']);
        $this->assertSame(0, $o1['petty_cash']);
        $this->assertSame(200000, $o1['petty_cash_target']);
        $this->assertSame('GDG', $data['warehouse']['location']['code']);
        $this->assertSame(1, $data['open_discrepancies']); // selisih tutup shift +30.000
        $this->assertSame(0, CashHandover::query()->count());
    }
}
