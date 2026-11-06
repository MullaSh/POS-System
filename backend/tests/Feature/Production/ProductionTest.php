<?php

namespace Tests\Feature\Production;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockRequest;
use App\Modules\Inventory\Services\StockEntryService;
use App\Modules\Location\Models\Location;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Recipe\Services\RecipeService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class ProductionTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private Location $gdg;

    private Location $otl;

    private User $owner;

    private User $finance;

    private User $gudang;

    private User $kasir;

    private Item $ayam;

    private Item $tepung;

    private Item $dimsum;

    private Unit $g;

    private Unit $kg;

    private Unit $pcs;

    private Unit $pak;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::create(2026, 11, 6, 10, 0, 0, 'Asia/Jakarta'));
        $this->g = Unit::query()->create(['code' => 'g', 'name' => 'gram']);
        $this->kg = Unit::query()->create(['code' => 'kg', 'name' => 'kilogram']);
        $this->pcs = Unit::query()->create(['code' => 'pcs', 'name' => 'potong']);
        $this->pak = Unit::query()->create(['code' => 'pak', 'name' => 'pak']);
        $this->ayam = $this->raw('BB-AYAM', 'Daging ayam');
        $this->tepung = $this->raw('BB-TEPUNG', 'Tepung terigu');
        $this->dimsum = Item::query()->create(['code' => 'SJ-DIMSUM', 'name' => 'Frozen Dimsum', 'item_type' => Item::TYPE_SEMI_FINISHED, 'base_unit_id' => $this->pcs->id, 'shelf_life_days' => 90]);
        $this->dimsum->units()->create(['unit_id' => $this->pcs->id, 'factor_to_base' => 1]);
        $this->dimsum->units()->create(['unit_id' => $this->pak->id, 'factor_to_base' => 20]);
        $this->gdg = $this->warehouse();
        $this->otl = $this->outlet('OTL01');
        $this->owner = $this->owner();
        $this->finance = $this->userWithRole(Role::FINANCE);
        $this->gudang = $this->userWithRole(Role::WAREHOUSE, [$this->gdg->id]);
        $this->kasir = $this->userWithRole(Role::CASHIER, [$this->otl->id]);

        // Resep produksi: 5 pak (100 pcs) dari 1,5 kg ayam + 1 kg tepung.
        app(RecipeService::class)->create($this->dimsum, [
            'output_unit_id' => $this->pak->id, 'output_qty' => 5,
            'lines' => [
                ['item_id' => $this->ayam->id, 'unit_id' => $this->kg->id, 'qty' => 1.5],
                ['item_id' => $this->tepung->id, 'unit_id' => $this->kg->id, 'qty' => 1],
            ],
        ], $this->owner);
        app(StockEntryService::class)->opening(['location_id' => $this->gdg->id, 'lines' => [
            ['item_id' => $this->ayam->id, 'unit_id' => $this->kg->id, 'qty' => 10, 'unit_cost' => 60000],
            ['item_id' => $this->tepung->id, 'unit_id' => $this->kg->id, 'qty' => 20, 'unit_cost' => 12000],
        ]], $this->owner);
    }

    private function raw(string $code, string $name): Item
    {
        $item = Item::query()->create(['code' => $code, 'name' => $name, 'item_type' => Item::TYPE_RAW, 'base_unit_id' => $this->g->id]);
        $item->units()->create(['unit_id' => $this->g->id, 'factor_to_base' => 1]);
        $item->units()->create(['unit_id' => $this->kg->id, 'factor_to_base' => 1000]);

        return $item;
    }

    private function qty(Item $item): string
    {
        return (string) (StockBalance::query()->where(['item_id' => $item->id, 'location_id' => $this->gdg->id])->value('qty') ?? '0');
    }

    /** @return array<string, mixed> */
    private function plan(array $extra = []): array
    {
        Sanctum::actingAs($this->gudang);

        return $this->postJson('/api/v1/production/orders', ['output_item_id' => $this->dimsum->id, 'unit_id' => $this->pak->id, 'qty' => 5, ...$extra])
            ->assertCreated()->json('data');
    }

    /** @param array<string, mixed> $order @return array<string, mixed> */
    private function completion(array $order, string $ayamKg = '1.55', string $tepungKg = '1', int $good = 96, array $extra = []): array
    {
        $lines = collect($order['lines'])->keyBy('item.code');

        return [
            'unit_id' => $this->pcs->id,
            'good_qty' => $good,
            'failures' => [['reason' => 'MISSHAPEN', 'qty' => 4]],
            'lines' => [
                ['line_id' => $lines['BB-AYAM']['id'], 'actual_qty' => $ayamKg],
                ['line_id' => $lines['BB-TEPUNG']['id'], 'actual_qty' => $tepungKg],
            ],
            ...$extra,
        ];
    }

    public function test_plan_scales_recipe_and_does_not_touch_stock(): void
    {
        $order = $this->plan(['qty' => 10]);

        $this->assertSame('PLANNED', $order['status']);
        $this->assertSame('200.0000', $order['planned_qty']);
        $lines = collect($order['lines'])->keyBy('item.code');
        $this->assertSame('3000.0000', $lines['BB-AYAM']['standard_qty']);
        $this->assertSame('3.0000', $lines['BB-AYAM']['standard_entered']);
        $this->assertSame('kg', $lines['BB-AYAM']['unit']['code']);
        $this->assertSame('0.0000', $lines['BB-AYAM']['shortage_qty']);
        $this->assertNull($order['total_cost']);
        $this->assertSame('10000.0000', $this->qty($this->ayam));
        $this->assertSame(1, AuditLog::query()->where('action', 'PRODUCTION_PLANNED')->count());
        $this->assertTrue($order['can']['complete']);
    }

    public function test_only_warehouse_plans_and_recipe_is_required(): void
    {
        Sanctum::actingAs($this->finance);
        $this->postJson('/api/v1/production/orders', ['output_item_id' => $this->dimsum->id, 'unit_id' => $this->pak->id, 'qty' => 5])->assertForbidden();
        Sanctum::actingAs($this->kasir);
        $this->getJson('/api/v1/production/orders')->assertForbidden();

        Sanctum::actingAs($this->gudang);
        $this->postJson('/api/v1/production/orders', ['output_item_id' => $this->ayam->id, 'unit_id' => $this->kg->id, 'qty' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('output_item_id');
    }

    public function test_preview_shows_shortage_for_purchase_request(): void
    {
        Sanctum::actingAs($this->gudang);
        // 40 pak = 800 pcs → 12 kg ayam, stok 10 kg → kurang 2 kg.
        $res = $this->getJson("/api/v1/production/preview?output_item_id={$this->dimsum->id}&unit_id={$this->pak->id}&qty=40")->assertOk();
        $lines = collect($res->json('data.lines'))->keyBy('item.code');
        $this->assertSame('2000.0000', $lines['BB-AYAM']['shortage_qty']);
        $this->assertSame('2.0000', $lines['BB-AYAM']['shortage_entered']);
        $this->assertSame('0.0000', $lines['BB-TEPUNG']['shortage_qty']);
    }

    public function test_complete_consumes_materials_and_adds_good_output_with_cost_batch_and_expiry(): void
    {
        $order = $this->plan();
        $done = $this->postJson("/api/v1/production/orders/{$order['id']}/complete", $this->completion($order))->assertOk()->json('data');

        $this->assertSame('COMPLETED', $done['status']);
        $this->assertSame('96.0000', $done['good_qty']);
        $this->assertSame('4.0000', $done['failed_qty']);
        $this->assertSame('-4.00', $done['output_variance_pct']);
        $this->assertFalse($done['over_tolerance']);
        $this->assertSame($order['number'], $done['batch_no']);
        $this->assertSame('2027-02-04', $done['expiry_date']);
        $this->assertNull($done['total_cost'], 'gudang tidak melihat biaya');

        $this->assertSame('8450.0000', $this->qty($this->ayam));
        $this->assertSame('19000.0000', $this->qty($this->tepung));
        $this->assertSame('96.0000', $this->qty($this->dimsum));
        $batch = StockBatch::query()->where(['item_id' => $this->dimsum->id, 'location_id' => $this->gdg->id])->firstOrFail();
        $this->assertSame($order['number'], $batch->batch_no);
        $this->assertSame('2027-02-04', $batch->expiry_date->format('Y-m-d'));
        $this->assertSame(2, StockMovement::query()->where('type', StockMovement::PRODUCTION_CONSUME)->count());
        // Biaya bahan 1.550 g × 60 + 1.000 g × 12 = 105.000; harga pokok = 105.000 ÷ 96 hasil baik.
        $this->assertSame('1093.7500', (string) StockBalance::query()->where(['item_id' => $this->dimsum->id, 'location_id' => $this->gdg->id])->value('avg_cost'));

        Sanctum::actingAs($this->finance);
        $seen = $this->getJson("/api/v1/production/orders/{$order['id']}")->assertOk()->json('data');
        $this->assertSame('105000.0000', $seen['total_cost']);
        $this->assertSame('1093.7500', $seen['unit_cost']);
        $this->assertSame('4200.0000', $seen['failed_value']);
        $this->assertTrue($seen['cost_complete']);
        $this->assertSame('MISSHAPEN', $seen['failures'][0]['reason']);
        $this->assertSame('3.33', collect($seen['lines'])->firstWhere('item.code', 'BB-AYAM')['variance_pct']);
        $this->assertFalse($seen['can']['complete']);
        $this->assertContains('QC_REJECTED', AuditLog::query()->where('action', 'PRODUCTION_COMPLETED')->firstOrFail()->flags);
    }

    public function test_variance_beyond_tolerance_requires_reason_and_is_listed_for_owner(): void
    {
        $order = $this->plan();
        $this->postJson("/api/v1/production/orders/{$order['id']}/complete", $this->completion($order, '1.8'))
            ->assertUnprocessable()->assertJsonValidationErrors('variance_note');
        $this->assertSame('10000.0000', $this->qty($this->ayam), 'stok tidak berubah bila ditolak');

        $this->postJson("/api/v1/production/orders/{$order['id']}/complete", $this->completion($order, '1.8', extra: ['variance_note' => 'Daging banyak lemak, dibuang']))
            ->assertOk()->assertJsonPath('data.over_tolerance', true);
        $this->assertContains('VARIANCE_OVER_TOLERANCE', AuditLog::query()->where('action', 'PRODUCTION_COMPLETED')->firstOrFail()->flags);

        Sanctum::actingAs($this->owner);
        $list = $this->getJson('/api/v1/production/orders?status=OVER_TOLERANCE')->assertOk();
        $this->assertSame([$order['number']], collect($list->json('data'))->pluck('number')->all());
        $this->assertSame(1, $list->json('counts.over_tolerance'));
    }

    public function test_owner_sets_tolerance(): void
    {
        Sanctum::actingAs($this->finance);
        $this->putJson('/api/v1/production/settings', ['variance_tolerance_pct' => 10])->assertForbidden();
        Sanctum::actingAs($this->owner);
        $this->getJson('/api/v1/production/settings')->assertOk()->assertJsonPath('data.variance_tolerance_pct', 5);
        $this->putJson('/api/v1/production/settings', ['variance_tolerance_pct' => 25])->assertOk()->assertJsonPath('data.variance_tolerance_pct', 25);

        $order = $this->plan();
        // +20% ayam kini masih di dalam batas 25%.
        $this->postJson("/api/v1/production/orders/{$order['id']}/complete", $this->completion($order, '1.8'))->assertOk()->assertJsonPath('data.over_tolerance', false);
        $this->assertSame('25.00', ProductionOrder::query()->findOrFail($order['id'])->tolerance_pct);
    }

    public function test_all_failed_output_adds_no_stock(): void
    {
        $order = $this->plan();
        $payload = $this->completion($order, good: 0, extra: ['variance_note' => 'Mesin rusak', 'failures' => [['reason' => 'OTHER', 'qty' => 100]]]);
        $this->postJson("/api/v1/production/orders/{$order['id']}/complete", $payload)->assertUnprocessable()->assertJsonValidationErrors('failures.0.note');
        $payload['failures'][0]['note'] = 'Gosong semua';
        $this->postJson("/api/v1/production/orders/{$order['id']}/complete", $payload)->assertOk()->assertJsonPath('data.batch_no', null);
        $this->assertSame('0', $this->qty($this->dimsum));
        $this->assertSame('8450.0000', $this->qty($this->ayam));
    }

    public function test_cancel_plan_with_reason_and_records_are_locked(): void
    {
        $order = $this->plan();
        $this->postJson("/api/v1/production/orders/{$order['id']}/cancel", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/production/orders/{$order['id']}/cancel", ['reason' => 'Freezer penuh'])->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        $this->postJson("/api/v1/production/orders/{$order['id']}/complete", $this->completion($order))->assertUnprocessable();

        $done = $this->plan();
        $this->postJson("/api/v1/production/orders/{$done['id']}/complete", $this->completion($done))->assertOk();
        try {
            DB::transaction(fn () => DB::table('production_order_lines')->where('production_order_id', $done['id'])->update(['actual_qty' => 1]));
            $this->fail('pemakaian bahan harus terkunci');
        } catch (QueryException) {
        }
        try {
            DB::transaction(fn () => DB::table('production_orders')->where('id', $order['id'])->delete());
            $this->fail('Production Order tidak boleh dihapus');
        } catch (QueryException) {
        }
        $this->expectException(QueryException::class);
        DB::table('production_orders')->where('id', $done['id'])->update(['notes' => 'ubah']);
    }

    public function test_plan_from_short_stock_request(): void
    {
        Sanctum::actingAs($this->kasir);
        $sr = $this->postJson('/api/v1/stock-requests', ['location_id' => $this->otl->id, 'lines' => [
            ['item_id' => $this->dimsum->id, 'unit_id' => $this->pcs->id, 'qty' => 60],
        ]])->assertCreated()->json('data');

        Sanctum::actingAs($this->gudang);
        $detail = $this->getJson("/api/v1/stock-requests/{$sr['id']}")->assertOk()->json('data');
        $this->assertTrue($detail['lines'][0]['producible']);
        $this->assertSame('0', $detail['lines'][0]['warehouse_qty']);

        $order = $this->plan(['unit_id' => $this->pcs->id, 'qty' => 60, 'stock_request_id' => $sr['id']]);
        $this->assertSame($sr['number'], $order['stock_request']['number']);
        $this->assertSame($order['number'], $this->getJson("/api/v1/stock-requests/{$sr['id']}")->json('data.productions.0.number'));

        // Produksi tanpa permintaan tetap boleh; permintaan yang sudah ditolak tidak dapat dipakai.
        $this->postJson("/api/v1/stock-requests/{$sr['id']}/reject", ['note' => 'Tidak jadi'])->assertOk();
        $this->postJson('/api/v1/production/orders', ['output_item_id' => $this->dimsum->id, 'unit_id' => $this->pcs->id, 'qty' => 10, 'stock_request_id' => $sr['id']])
            ->assertUnprocessable()->assertJsonValidationErrors('stock_request_id');
        $this->assertSame(StockRequest::REJECTED, StockRequest::query()->findOrFail($sr['id'])->status);
    }

    public function test_shelf_life_only_for_semi_finished(): void
    {
        Sanctum::actingAs($this->owner);
        $this->patchJson("/api/v1/items/{$this->dimsum->id}", ['name' => 'Frozen Dimsum', 'shelf_life_days' => 60])->assertOk()->assertJsonPath('data.shelf_life_days', 60);
        $this->patchJson("/api/v1/items/{$this->ayam->id}", ['name' => 'Daging ayam', 'shelf_life_days' => 5])->assertUnprocessable()->assertJsonValidationErrors('shelf_life_days');
        $this->patchJson("/api/v1/items/{$this->dimsum->id}", ['name' => 'Frozen Dimsum'])->assertOk()->assertJsonPath('data.shelf_life_days', 60);
    }

    public function test_products_list_only_items_with_production_recipe(): void
    {
        Sanctum::actingAs($this->gudang);
        $res = $this->getJson('/api/v1/production/products')->assertOk();
        $this->assertSame(['SJ-DIMSUM'], collect($res->json('data'))->pluck('code')->all());
        $this->assertSame(90, $res->json('data.0.shelf_life_days'));
    }
}
