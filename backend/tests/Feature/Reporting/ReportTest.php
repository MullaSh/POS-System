<?php

namespace Tests\Feature\Reporting;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Models\PaymentMethod;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\RoleAssignment;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Location\Models\Location;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Support\Decimal;
use Database\Seeders\BaseDataSeeder;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Laporan dan beranda Owner (irisan 9). Memakai data contoh, lalu memeriksa bahwa angka antar laporan
 * saling cocok (pengganti jurnal tersembunyi, keputusan Owner butir 1) dan rumus laba (butir 3–5).
 */
class ReportTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BaseDataSeeder::class);
        $this->seed(DemoDataSeeder::class);
        $this->owner = User::query()->where('username', 'owner')->firstOrFail();
        $this->finance = User::query()->where('username', 'finance1')->firstOrFail();
    }

    private function report(string $path, ?User $as = null): array
    {
        Sanctum::actingAs($as ?? $this->owner);

        return $this->getJson("/api/v1/reports/{$path}")->assertOk()->json('data');
    }

    public function test_reports_are_for_owner_and_finance_and_dashboard_for_owner_only(): void
    {
        foreach (['kasir1', 'gudang1'] as $username) {
            Sanctum::actingAs(User::query()->where('username', $username)->firstOrFail());
            $this->getJson('/api/v1/reports/profit')->assertForbidden();
            $this->getJson('/api/v1/reports/dashboard')->assertForbidden();
        }
        Sanctum::actingAs($this->finance);
        $this->getJson('/api/v1/reports/dashboard')->assertForbidden();
        foreach (['sales', 'menu-hpp', 'profit', 'expenses', 'inventory-valuation', 'cash-flow', 'cash-reconciliation', 'stock-movements', 'production-waste', 'purchasing', 'payables'] as $r) {
            $this->getJson("/api/v1/reports/{$r}")->assertOk();
        }
        $this->getJson('/api/v1/reports/profit?from=2026-11-10&to=2026-11-01')->assertUnprocessable()->assertJsonValidationErrors('to');
    }

    public function test_profit_matches_sales_refunds_and_stock_records(): void
    {
        $p = $this->report('profit');
        $t = $p['total'];
        $from = $p['period']['from'];
        $to = $p['period']['to'];

        $gross = (int) DB::table('sales')->whereBetween('business_date', [$from, $to])->sum('total');
        $refunds = (int) DB::table('sale_refunds')->whereNotNull('applied_at')->sum('amount');
        $this->assertGreaterThan(0, $gross);
        $this->assertSame($gross, $t['sales_gross']);
        $this->assertSame($gross - $refunds, $t['net_sales']);
        $this->assertSame($t['net_sales'] - $t['hpp'], $t['gross_profit']);
        $this->assertSame($t['gross_profit'] - $t['expenses'] - $t['stock_loss'] - $t['other_loss'], $t['net_profit']);
        $this->assertSame(array_sum(array_column($p['rows'], 'net_profit')), $t['net_profit']);

        // Waste contoh di gudang (5 pcs lumpia) masuk kerugian stok baris "Gudang & pusat".
        $wh = collect($p['rows'])->firstWhere('location.type', Location::TYPE_WAREHOUSE);
        $this->assertSame('Gudang & pusat', $wh['location']['name']);
        $wasteValue = (int) Decimal::round(bcmul((string) DB::table('stock_movements')->where('type', 'WASTE')->sum('value'), '-1', 4), 0);
        $this->assertGreaterThan(0, $wasteValue);
        $this->assertSame($wasteValue, $wh['waste']);
        // Biaya gudang dibayar Finance tidak dibebankan ke outlet (butir 5).
        $this->assertSame((int) DB::table('expenses')->where('location_id', $wh['location']['id'])->whereIn('status', ['RECORDED', 'VERIFIED'])->sum('amount'), $wh['expenses']);
        // Hasil gagal produksi tidak dihitung lagi sebagai kerugian.
        $this->assertSame($wh['waste'] + $wh['transit_loss'] + $wh['count_adjustment'] + $wh['refund_made'], $wh['stock_loss']);

        $menu = $this->report('menu-hpp');
        $this->assertSame($t['net_sales'], $menu['total']['revenue']);
    }

    public function test_valuation_movements_and_cash_flow_agree_with_ledger(): void
    {
        $v = $this->report('inventory-valuation');
        $expected = 0;
        foreach (StockBalance::query()->where('qty', '<>', 0)->get() as $b) {
            $expected += $b->avg_cost === null ? 0 : (int) Decimal::round(bcmul((string) $b->qty, (string) $b->avg_cost, 8), 0);
        }
        $this->assertSame($expected, $v['total']['value']);

        $m = $this->report('stock-movements');
        foreach ($m['rows'] as $r) {
            $this->assertSame(0, Decimal::cmp(bcsub(bcadd($r['opening_qty'], $r['in_qty'], 4), $r['out_qty'], 4), $r['closing_qty']), $r['item']['code'].' '.$r['location']['code']);
        }

        $cf = $this->report('cash-flow');
        $paid = (int) DB::table('sale_payments')->sum('amount');
        $back = (int) DB::table('sale_refund_payments as rp')->join('sale_refunds as r', 'r.id', '=', 'rp.sale_refund_id')->whereNotNull('r.applied_at')->sum('rp.amount');
        $this->assertSame($paid - $back, $cf['in_total']);
        $this->assertSame($cf['in_total'] - $cf['out_total'], $cf['net']);
    }

    public function test_business_category_discrepancy_reduces_net_profit_and_staff_does_not(): void
    {
        $before = $this->report('profit')['total']['net_profit'];
        $qris = PaymentMethod::query()->findOrFail(DB::table('sale_payments')->where('is_cash', false)->value('payment_method_id'));
        $today = now()->setTimezone('Asia/Jakarta')->toDateString();

        Sanctum::actingAs($this->finance);
        $system = $this->getJson('/api/v1/cash/reconciliations?days=1')->assertOk()->collect('data')->firstWhere('method.id', $qris->id)['system_amount'];
        $id = $this->postJson('/api/v1/cash/reconciliations', ['business_date' => $today, 'payment_method_id' => $qris->id, 'bank_amount' => $system + 1000, 'note' => 'Kelebihan mutasi'])
            ->assertCreated()->json('data.id');
        $this->postJson('/api/v1/cash/discrepancies/resolve', ['subject_type' => 'noncash_reconciliation', 'subject_id' => $id, 'decision' => 'Tidak diketahui'])
            ->assertUnprocessable()->assertJsonValidationErrors('category');
        $this->postJson('/api/v1/cash/discrepancies/resolve', ['subject_type' => 'noncash_reconciliation', 'subject_id' => $id, 'decision' => 'Masuk usaha', 'category' => 'BUSINESS'])->assertCreated();

        $after = $this->report('profit');
        $this->assertSame($before + 1000, $after['total']['net_profit']);
        $this->assertSame(-1000, $after['total']['cash_business_loss']);
        $this->assertSame(1, $this->report('cash-reconciliation')['categories']['BUSINESS']['count']);
    }

    public function test_control_blocked_attempt_is_recorded_and_shown_on_dashboard(): void
    {
        $po = PurchaseOrder::query()->where('status', PurchaseOrder::STATUS_ORDERED)->firstOrFail();
        $buyer = User::query()->findOrFail($po->created_by);
        $warehouse = Location::query()->where('type', Location::TYPE_WAREHOUSE)->firstOrFail();
        $assignment = RoleAssignment::query()->create(['user_id' => $buyer->id, 'role_id' => Role::idFor(Role::WAREHOUSE), 'scope' => RoleAssignment::SCOPE_SELECTED,
            'status' => RoleAssignment::STATUS_ACTIVE, 'confirmed_at' => now()]);
        $assignment->locations()->sync([$warehouse->id]);
        $line = DB::table('purchase_lines')->where(['document_type' => 'PO', 'document_id' => $po->id])->first();

        Sanctum::actingAs($buyer->fresh());
        $this->postJson("/api/v1/purchasing/orders/{$po->id}/receive", ['lines' => [['purchase_line_id' => $line->id, 'qty_accepted' => 1]]])->assertForbidden();
        $this->assertDatabaseHas('audit_logs', ['action' => 'CONTROL_BLOCKED', 'user_id' => $buyer->id]);
        $log = AuditLog::query()->where('action', 'CONTROL_BLOCKED')->latest('id')->firstOrFail();
        $this->assertSame('BUYER_RECEIVES', $log->changes['kontrol']);

        $d = $this->report('dashboard');
        $this->assertSame(1, $d['watch']['counts']['blocked']);
        $this->assertContains('CONTROL_BLOCKED', array_column($d['watch']['latest'], 'action'));
        $this->assertGreaterThanOrEqual(1, $d['watch']['counts']['waste']);
        $this->assertArrayHasKey('net_profit', $d['profit']);
        $this->assertNotEmpty($d['sales']['rows']);
        $this->assertGreaterThanOrEqual(1, $d['attention']['stock']['low']);
    }
}
