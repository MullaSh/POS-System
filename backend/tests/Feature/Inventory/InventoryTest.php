<?php

namespace Tests\Feature\Inventory;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\ItemMinStock;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockTransferLine;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Location\Models\Location;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    /** @var array<string, Unit> */
    private array $u = [];

    /** @var array<string, Item> */
    private array $i = [];

    private Location $gdg;

    private Location $otl1;

    private Location $otl2;

    private User $owner;

    private User $finance;

    private User $gudang;

    private User $kasir1;

    private User $kasir2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::create(2026, 11, 5, 10, 0, 0, 'Asia/Jakarta'));
        foreach (['g' => 'gram', 'kg' => 'kilogram', 'ml' => 'mililiter', 'pcs' => 'pcs', 'pak' => 'pak', 'porsi' => 'porsi'] as $code => $name) {
            $this->u[$code] = Unit::query()->create(['code' => $code, 'name' => $name]);
        }
        $this->item('BB-AYAM', Item::TYPE_RAW, 'g', ['kg' => 1000]);
        $this->item('SJ-DIMSUM', Item::TYPE_SEMI_FINISHED, 'pcs', ['pak' => 20]);
        $this->item('PK-BOWL', Item::TYPE_PACKAGING, 'pcs', ['pak' => 50]);
        $this->item('MN-DIMSUM4', Item::TYPE_MENU, 'porsi');

        $this->gdg = $this->warehouse();
        $this->otl1 = $this->outlet('OTL01');
        $this->otl2 = $this->outlet('OTL02');
        $this->owner = $this->owner();
        $this->finance = $this->userWithRole(Role::FINANCE);
        $this->gudang = $this->userWithRole(Role::WAREHOUSE, [$this->gdg->id]);
        $this->kasir1 = $this->userWithRole(Role::CASHIER, [$this->otl1->id]);
        $this->kasir2 = $this->userWithRole(Role::CASHIER, [$this->otl2->id]);
    }

    /** @param  array<string, int>  $conversions */
    private function item(string $code, string $type, string $base, array $conversions = []): Item
    {
        $item = Item::query()->create(['code' => $code, 'name' => $code, 'item_type' => $type, 'base_unit_id' => $this->u[$base]->id]);
        $item->units()->create(['unit_id' => $this->u[$base]->id, 'factor_to_base' => 1]);
        foreach ($conversions as $unit => $factor) {
            $item->units()->create(['unit_id' => $this->u[$unit]->id, 'factor_to_base' => $factor]);
        }

        return $this->i[$code] = $item;
    }

    /** @param  array<string, mixed>  $extra */
    private function line(string $item, string $unit, $qty, array $extra = []): array
    {
        return ['item_id' => $this->i[$item]->id, 'unit_id' => $this->u[$unit]->id, 'qty' => $qty, ...$extra];
    }

    private function as(User $user): void
    {
        Sanctum::actingAs($user);
    }

    private function qty(string $item, Location $location): string
    {
        return (string) (StockBalance::query()->where(['item_id' => $this->i[$item]->id, 'location_id' => $location->id])->value('qty') ?? '0');
    }

    private function avg(string $item, Location $location): ?string
    {
        return StockBalance::query()->where(['item_id' => $this->i[$item]->id, 'location_id' => $location->id])->value('avg_cost');
    }

    private function opening(Location $location, array $lines): void
    {
        $this->as($this->owner);
        $this->postJson('/api/v1/stock/openings', ['location_id' => $location->id, 'lines' => $lines])->assertCreated();
    }

    public function test_owner_or_finance_records_opening_balance_once_with_cost(): void
    {
        $this->opening($this->gdg, [$this->line('BB-AYAM', 'kg', 10, ['unit_cost' => 60000, 'batch_no' => 'AY-01', 'expiry_date' => '2026-12-31'])]);

        $this->assertSame('10000.0000', $this->qty('BB-AYAM', $this->gdg));
        $this->assertSame('60.0000', $this->avg('BB-AYAM', $this->gdg)); // Rp60.000/kg = Rp60/g
        $batch = StockBatch::query()->firstOrFail();
        $this->assertSame('AY-01', $batch->batch_no);
        $this->assertSame('2026-12-31', $batch->expiry_date->format('Y-m-d'));
        $this->assertSame('10000.0000', $batch->qty_remaining);
        $this->assertSame(1, AuditLog::query()->where('action', 'STOCK_OPENING_RECORDED')->count());

        // Satu kali per barang per lokasi; koreksi lewat opname.
        $this->postJson('/api/v1/stock/openings', ['location_id' => $this->gdg->id, 'lines' => [$this->line('BB-AYAM', 'kg', 1, ['unit_cost' => 1])]])
            ->assertUnprocessable()->assertJsonValidationErrors('lines.0.item_id');

        // Finance boleh; outlet tanpa batch.
        $this->as($this->finance);
        $this->postJson('/api/v1/stock/openings', ['location_id' => $this->otl1->id, 'lines' => [$this->line('PK-BOWL', 'pcs', 10, ['unit_cost' => 500, 'batch_no' => 'X'])]])
            ->assertUnprocessable()->assertJsonValidationErrors('lines.0.batch_no');
        $this->postJson('/api/v1/stock/openings', ['location_id' => $this->otl1->id, 'lines' => [$this->line('PK-BOWL', 'pcs', 10, ['unit_cost' => 500])]])
            ->assertCreated();

        // Kasir dan gudang tidak boleh; menu bukan barang stok.
        $this->as($this->kasir1);
        $this->postJson('/api/v1/stock/openings', ['location_id' => $this->otl1->id, 'lines' => [$this->line('SJ-DIMSUM', 'pcs', 1, ['unit_cost' => 1])]])->assertForbidden();
        $this->as($this->owner);
        $this->postJson('/api/v1/stock/openings', ['location_id' => $this->otl1->id, 'lines' => [$this->line('MN-DIMSUM4', 'porsi', 1, ['unit_cost' => 1])]])
            ->assertUnprocessable()->assertJsonValidationErrors('lines.0.item_id');
    }

    public function test_moving_average_cost_and_negative_stock(): void
    {
        $ledger = app(StockLedger::class);
        $src = ['type' => 'test', 'id' => 1, 'number' => 'T-1'];
        $item = $this->i['SJ-DIMSUM'];

        DB::transaction(function () use ($ledger, $src, $item) {
            $ledger->receive($item, $this->otl1, '10', '100', StockMovement::OPENING, $src, $this->owner);
            $ledger->receive($item, $this->otl1, '10', '200', StockMovement::TRANSFER_IN, $src, $this->owner);
        });
        $this->assertSame('150.0000', $this->avg('SJ-DIMSUM', $this->otl1));

        // Keluar memakai rata-rata; stok boleh minus (KP-02).
        $out = DB::transaction(fn () => $ledger->issue($item, $this->otl1, '25', StockMovement::WASTE, $src, $this->owner));
        $this->assertSame('-3750.0000', $out[0]->value);
        $this->assertSame('-5.0000', $this->qty('SJ-DIMSUM', $this->otl1));

        // Masuk saat saldo minus: rata-rata = harga masuk.
        DB::transaction(fn () => $ledger->receive($item, $this->otl1, '20', '300', StockMovement::TRANSFER_IN, $src, $this->owner));
        $this->assertSame('300.0000', $this->avg('SJ-DIMSUM', $this->otl1));
        $this->assertSame('15.0000', $this->qty('SJ-DIMSUM', $this->otl1));
    }

    public function test_waste_is_recorded_directly_by_location_staff_and_takes_earliest_batch(): void
    {
        $this->opening($this->gdg, [$this->line('SJ-DIMSUM', 'pcs', 100, ['unit_cost' => 1500, 'batch_no' => 'LAMA', 'expiry_date' => '2026-12-01'])]);
        $ledger = app(StockLedger::class);
        DB::transaction(fn () => $ledger->receive($this->i['SJ-DIMSUM'], $this->gdg, '100', '1500', StockMovement::OPENING,
            ['type' => 'test', 'id' => 1, 'number' => 'T'], $this->owner, ['batch_no' => 'BARU', 'expiry_date' => '2027-01-01']));

        // Owner tanpa peran gudang tidak mencatat waste (2G-Q3).
        $this->postJson('/api/v1/stock/wastes', ['location_id' => $this->gdg->id, 'reason_code' => 'DAMAGED', 'notes' => 'x', 'lines' => [$this->line('SJ-DIMSUM', 'pcs', 1)]])
            ->assertForbidden();

        $this->as($this->gudang);
        $this->postJson('/api/v1/stock/wastes', ['location_id' => $this->gdg->id, 'reason_code' => 'DAMAGED', 'lines' => [$this->line('SJ-DIMSUM', 'pcs', 1)]])
            ->assertUnprocessable()->assertJsonValidationErrors('notes');
        $this->postJson('/api/v1/stock/wastes', ['location_id' => $this->gdg->id, 'reason_code' => 'DAMAGED', 'notes' => 'Freezer mati', 'lines' => [$this->line('SJ-DIMSUM', 'pak', 6)]])
            ->assertCreated()
            ->assertJsonPath('data.kind', 'WASTE')
            ->assertJsonPath('data.movements.0.batch.batch_no', 'LAMA')
            ->assertJsonPath('data.movements.1.batch.batch_no', 'BARU')
            ->assertJsonPath('data.movements.0.unit_cost', null); // gudang tidak melihat harga pokok

        $this->assertSame('80.0000', $this->qty('SJ-DIMSUM', $this->gdg));
        $this->assertSame('0.0000', StockBatch::query()->where('batch_no', 'LAMA')->value('qty_remaining'));

        // Kasir hanya di outletnya.
        $this->as($this->kasir2);
        $this->postJson('/api/v1/stock/wastes', ['location_id' => $this->otl1->id, 'reason_code' => 'EXPIRED', 'notes' => 'x', 'lines' => [$this->line('PK-BOWL', 'pcs', 1)]])
            ->assertForbidden();
    }

    public function test_stock_count_adjusts_only_after_owner_approval_using_snapshot_difference(): void
    {
        $this->opening($this->otl1, [$this->line('SJ-DIMSUM', 'pcs', 50, ['unit_cost' => 1000]), $this->line('PK-BOWL', 'pcs', 20, ['unit_cost' => 500])]);

        $this->as($this->kasir1);
        $count = $this->postJson('/api/v1/stock/counts', ['location_id' => $this->otl1->id, 'reason' => 'Opname mingguan', 'lines' => [
            ['item_id' => $this->i['SJ-DIMSUM']->id, 'unit_id' => $this->u['pcs']->id, 'counted_qty' => 45],
            ['item_id' => $this->i['PK-BOWL']->id, 'unit_id' => $this->u['pcs']->id, 'counted_qty' => 20],
        ]])->assertCreated()
            ->assertJsonPath('data.status', 'PENDING')
            ->assertJsonPath('data.difference_count', 1)
            ->assertJsonPath('data.lines.0.difference', '-5.0000')
            ->assertJsonPath('data.lines.0.value', null)
            ->json('data');

        // Belum berlaku, dan satu opname terbuka per lokasi.
        $this->assertSame('50.0000', $this->qty('SJ-DIMSUM', $this->otl1));
        $this->postJson('/api/v1/stock/counts', ['location_id' => $this->otl1->id, 'lines' => [['item_id' => $this->i['PK-BOWL']->id, 'unit_id' => $this->u['pcs']->id, 'counted_qty' => 1]]])
            ->assertUnprocessable()->assertJsonValidationErrors('location_id');

        // Kasir tidak dapat memutuskan.
        $this->postJson("/api/v1/approvals/{$count['approval_id']}/approve")->assertForbidden();

        // Sementara menunggu, stok berubah (mis. waste) — tidak ikut terhapus saat penyesuaian.
        $this->postJson('/api/v1/stock/wastes', ['location_id' => $this->otl1->id, 'reason_code' => 'SPILLED', 'notes' => 'Jatuh', 'lines' => [$this->line('SJ-DIMSUM', 'pcs', 2)]])->assertCreated();

        $this->as($this->owner);
        $this->getJson("/api/v1/stock/counts/{$count['id']}")->assertJsonPath('data.lines.0.value', '-5000.00')->assertJsonPath('data.can.decide', true);
        $this->postJson("/api/v1/approvals/{$count['approval_id']}/approve")->assertOk();

        $this->assertSame('43.0000', $this->qty('SJ-DIMSUM', $this->otl1)); // 50 - 2 (waste) - 5 (opname)
        $this->assertSame(1, StockMovement::query()->where('type', 'ADJUSTMENT')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'STOCK_ADJUSTMENT_APPLIED')->count());
        $this->getJson("/api/v1/stock/counts/{$count['id']}")->assertJsonPath('data.status', 'APPROVED')->assertJsonPath('data.value_is_estimate', false);
    }

    public function test_returned_stock_count_can_be_resubmitted_by_counter(): void
    {
        $this->opening($this->otl1, [$this->line('PK-BOWL', 'pcs', 20, ['unit_cost' => 500])]);
        $this->as($this->kasir1);
        $count = $this->postJson('/api/v1/stock/counts', ['location_id' => $this->otl1->id, 'lines' => [
            ['item_id' => $this->i['PK-BOWL']->id, 'unit_id' => $this->u['pcs']->id, 'counted_qty' => 10],
        ]])->assertCreated()->json('data');

        $this->as($this->owner);
        $this->postJson("/api/v1/approvals/{$count['approval_id']}/return", ['note' => 'Hitung ulang'])->assertOk();

        $this->as($this->kasir1);
        $this->postJson("/api/v1/approvals/{$count['approval_id']}/resubmit", ['lines' => [
            ['item_id' => $this->i['PK-BOWL']->id, 'unit_id' => $this->u['pak']->id, 'counted_qty' => 0.5],
        ]])->assertOk();
        $this->getJson("/api/v1/stock/counts/{$count['id']}")->assertJsonPath('data.lines.0.counted_qty', '25.0000')->assertJsonPath('data.lines.0.difference', '5.0000');

        $this->as($this->owner);
        $this->postJson("/api/v1/approvals/{$count['approval_id']}/approve")->assertOk();
        $this->assertSame('25.0000', $this->qty('PK-BOWL', $this->otl1));
        $this->assertSame('500.0000', $this->avg('PK-BOWL', $this->otl1)); // tambahan opname memakai rata-rata yang ada
    }

    public function test_stock_request_fulfilled_by_transfer_and_short_delivery_explained_by_warehouse(): void
    {
        $this->opening($this->gdg, [$this->line('SJ-DIMSUM', 'pcs', 200, ['unit_cost' => 1500, 'batch_no' => 'B1', 'expiry_date' => '2026-12-01'])]);

        // Hanya kasir outlet itu yang membuat permintaan (KP-09).
        $this->as($this->gudang);
        $this->postJson('/api/v1/stock-requests', ['location_id' => $this->otl1->id, 'lines' => [$this->line('SJ-DIMSUM', 'pcs', 80)]])->assertForbidden();
        $this->as($this->kasir1);
        $sr = $this->postJson('/api/v1/stock-requests', ['location_id' => $this->otl1->id, 'lines' => [$this->line('SJ-DIMSUM', 'pak', 4)]])
            ->assertCreated()->assertJsonPath('data.status', 'SUBMITTED')->assertJsonPath('data.lines.0.qty', '80.0000')->json('data');

        // Kasir lain tidak melihat; gudang melihat dan memenuhi.
        $this->as($this->kasir2);
        $this->getJson("/api/v1/stock-requests/{$sr['id']}")->assertForbidden();
        $this->as($this->gudang);
        $this->getJson("/api/v1/stock-requests/{$sr['id']}")->assertJsonPath('data.can.fulfil', true)->assertJsonPath('data.lines.0.warehouse_qty', '200.0000');
        $t = $this->postJson('/api/v1/stock-transfers', ['kind' => 'DISTRIBUTION', 'to_location_id' => $this->otl1->id, 'stock_request_id' => $sr['id'],
            'lines' => [$this->line('SJ-DIMSUM', 'pcs', 80)]])->assertCreated()->assertJsonPath('data.status', 'IN_TRANSIT')->json('data');
        $this->assertSame('120.0000', $this->qty('SJ-DIMSUM', $this->gdg));
        $this->assertSame('0', $this->qty('SJ-DIMSUM', $this->otl1));

        // Pengirim tidak menerima sendiri; penerima mengisi jumlah baik yang diterima.
        $lineId = $t['lines'][0]['id'];
        $this->postJson("/api/v1/stock-transfers/{$t['id']}/receive", ['lines' => [['line_id' => $lineId, 'received_qty' => 80]]])->assertForbidden();
        $this->as($this->kasir1);
        $this->postJson("/api/v1/stock-transfers/{$t['id']}/receive", ['note' => '2 pcs pecah', 'lines' => [['line_id' => $lineId, 'received_qty' => 75]]])
            ->assertOk()->assertJsonPath('data.status', 'RECEIVED')->assertJsonPath('data.lines.0.difference', '5.0000')->assertJsonPath('data.unexplained_count', 1);
        $this->assertSame('75.0000', $this->qty('SJ-DIMSUM', $this->otl1));
        $this->assertSame('1500.0000', $this->avg('SJ-DIMSUM', $this->otl1)); // harga pokok ikut dari gudang
        $this->getJson("/api/v1/stock-requests/{$sr['id']}")->assertJsonPath('data.status', 'COMPLETED');

        // Kasir tidak menjelaskan selisih; gudang yang menjelaskan (OD-18).
        $this->postJson("/api/v1/stock-transfers/{$t['id']}/lines/{$lineId}/resolve", ['resolution' => 'SHORT_SHIPPED', 'note' => 'x'])->assertForbidden();
        $this->as($this->gudang);
        $this->postJson("/api/v1/stock-transfers/{$t['id']}/lines/{$lineId}/resolve", ['resolution' => 'OVER_SENT', 'note' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('resolution');
        $this->postJson("/api/v1/stock-transfers/{$t['id']}/lines/{$lineId}/resolve", ['resolution' => 'SHORT_SHIPPED', 'note' => '5 pcs tertinggal di freezer'])
            ->assertOk()->assertJsonPath('data.unexplained_count', 0)->assertJsonPath('data.lines.0.resolution', 'SHORT_SHIPPED');
        $this->assertSame('125.0000', $this->qty('SJ-DIMSUM', $this->gdg));
        $this->assertSame('125.0000', StockBatch::query()->where('batch_no', 'B1')->value('qty_remaining'));

        // Penjelasan dan jumlah terima tidak diubah lagi.
        $this->postJson("/api/v1/stock-transfers/{$t['id']}/lines/{$lineId}/resolve", ['resolution' => 'LOST_IN_TRANSIT', 'note' => 'y'])->assertUnprocessable();
        $this->expectException(QueryException::class);
        StockTransferLine::query()->whereKey($lineId)->update(['received_qty' => 80]);
    }

    public function test_lost_in_transit_and_over_sent(): void
    {
        $this->opening($this->gdg, [$this->line('PK-BOWL', 'pcs', 500, ['unit_cost' => 500]), $this->line('SJ-DIMSUM', 'pcs', 100, ['unit_cost' => 1500])]);
        $this->as($this->gudang);
        $t = $this->postJson('/api/v1/stock-transfers', ['kind' => 'DISTRIBUTION', 'to_location_id' => $this->otl2->id,
            'lines' => [$this->line('PK-BOWL', 'pak', 2), $this->line('SJ-DIMSUM', 'pcs', 40)]])->assertCreated()->json('data');
        [$bowl, $dimsum] = [$t['lines'][0]['id'], $t['lines'][1]['id']];

        $this->as($this->kasir2);
        $this->postJson("/api/v1/stock-transfers/{$t['id']}/receive", ['lines' => [['line_id' => $bowl, 'received_qty' => 1.9], ['line_id' => $dimsum, 'received_qty' => 42]]])->assertOk();
        $this->assertSame('95.0000', $this->qty('PK-BOWL', $this->otl2));

        $this->as($this->finance);
        $this->getJson("/api/v1/stock-transfers/{$t['id']}")->assertJsonPath('data.lines.0.difference_value', '2500.00')->assertJsonPath('data.can.resolve', false);

        $this->as($this->gudang);
        $this->getJson('/api/v1/stock-transfers?status=NEEDS_EXPLANATION')->assertJsonCount(1, 'data');
        $this->postJson("/api/v1/stock-transfers/{$t['id']}/lines/{$bowl}/resolve", ['resolution' => 'LOST_IN_TRANSIT', 'note' => 'Basah kena hujan'])->assertOk();
        $this->assertSame('400.0000', $this->qty('PK-BOWL', $this->gdg)); // tidak ada pergerakan baru
        $this->postJson("/api/v1/stock-transfers/{$t['id']}/lines/{$dimsum}/resolve", ['resolution' => 'OVER_SENT', 'note' => 'Terhitung kurang saat kirim'])->assertOk();
        $this->assertSame('58.0000', $this->qty('SJ-DIMSUM', $this->gdg));
        $this->getJson('/api/v1/stock-transfers?status=NEEDS_EXPLANATION')->assertJsonCount(0, 'data');
    }

    public function test_outlet_return_to_warehouse_and_cancelled_transfer_restores_stock(): void
    {
        $this->opening($this->gdg, [$this->line('SJ-DIMSUM', 'pcs', 100, ['unit_cost' => 1500, 'batch_no' => 'B1', 'expiry_date' => '2026-12-01'])]);
        $this->opening($this->otl1, [$this->line('PK-BOWL', 'pcs', 30, ['unit_cost' => 600])]);

        // Retur wajib beralasan, dikirim kasir, diterima gudang ke batch baru.
        $this->as($this->kasir1);
        $this->postJson('/api/v1/stock-transfers', ['kind' => 'RETURN', 'from_location_id' => $this->otl1->id, 'lines' => [$this->line('PK-BOWL', 'pcs', 10)]])
            ->assertUnprocessable()->assertJsonValidationErrors('reason_code');
        $r = $this->postJson('/api/v1/stock-transfers', ['kind' => 'RETURN', 'from_location_id' => $this->otl1->id, 'reason_code' => 'EXCESS',
            'lines' => [$this->line('PK-BOWL', 'pcs', 10)]])->assertCreated()->json('data');
        $this->as($this->gudang);
        $this->postJson("/api/v1/stock-transfers/{$r['id']}/receive", ['lines' => [['line_id' => $r['lines'][0]['id'], 'received_qty' => 10]]])->assertOk();
        $this->assertSame('10.0000', $this->qty('PK-BOWL', $this->gdg));
        $this->assertSame('600.0000', $this->avg('PK-BOWL', $this->gdg));
        $this->assertSame($r['number'], StockBatch::query()->where('item_id', $this->i['PK-BOWL']->id)->value('batch_no'));

        // Kiriman yang belum diterima dapat dibatalkan pengirim; stok kembali ke batch asal.
        $t = $this->postJson('/api/v1/stock-transfers', ['kind' => 'DISTRIBUTION', 'to_location_id' => $this->otl1->id, 'lines' => [$this->line('SJ-DIMSUM', 'pcs', 30)]])->json('data');
        $this->assertSame('70.0000', StockBatch::query()->where('batch_no', 'B1')->value('qty_remaining'));
        $this->postJson("/api/v1/stock-transfers/{$t['id']}/cancel", [])->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->postJson("/api/v1/stock-transfers/{$t['id']}/cancel", ['note' => 'Salah outlet'])->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        $this->assertSame('100.0000', StockBatch::query()->where('batch_no', 'B1')->value('qty_remaining'));
        $this->assertSame('100.0000', $this->qty('SJ-DIMSUM', $this->gdg));
    }

    public function test_visibility_and_cost_by_role(): void
    {
        $this->opening($this->otl1, [$this->line('PK-BOWL', 'pcs', 30, ['unit_cost' => 600])]);
        ItemMinStock::query()->create(['item_id' => $this->i['PK-BOWL']->id, 'location_id' => $this->otl1->id, 'min_qty' => 50]);

        $this->as($this->kasir1);
        $this->getJson('/api/v1/stock/locations')->assertJsonCount(1, 'data')->assertJsonPath('data.0.can.operate', true)->assertJsonPath('can_see_cost', false);
        $this->getJson("/api/v1/stock/balances?location_id={$this->otl1->id}&only=low")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.is_low', true)->assertJsonPath('data.0.avg_cost', null)->assertJsonPath('summary.total_value', null);
        $this->getJson("/api/v1/stock/balances?location_id={$this->otl2->id}")->assertForbidden();
        $this->getJson("/api/v1/stock/movements?location_id={$this->otl1->id}&item_id={$this->i['PK-BOWL']->id}")
            ->assertOk()->assertJsonPath('data.0.type', 'OPENING')->assertJsonPath('data.0.value', null);

        $this->as($this->finance);
        $this->getJson('/api/v1/stock/locations')->assertJsonCount(3, 'data')->assertJsonPath('data.0.type', 'WAREHOUSE');
        $this->getJson("/api/v1/stock/balances?location_id={$this->otl1->id}&only=with_stock")
            ->assertJsonPath('data.0.avg_cost', '600.0000')->assertJsonPath('summary.total_value', '18000.00');
    }

    public function test_recipe_cost_now_comes_from_stock_average(): void
    {
        $this->opening($this->gdg, [$this->line('SJ-DIMSUM', 'pcs', 100, ['unit_cost' => 1500]), $this->line('PK-BOWL', 'pcs', 100, ['unit_cost' => 500])]);
        $id = $this->postJson('/api/v1/recipes', [
            'output_item_id' => $this->i['MN-DIMSUM4']->id,
            'lines' => [['item_id' => $this->i['SJ-DIMSUM']->id, 'unit_id' => $this->u['pcs']->id, 'qty' => 4], ['item_id' => $this->i['PK-BOWL']->id, 'unit_id' => $this->u['pcs']->id, 'qty' => 1]],
        ])->assertCreated()->json('data.id');

        $this->getJson("/api/v1/recipes/{$id}")
            ->assertJsonPath('data.versions.0.lines.0.cost_source', 'STOCK_AVERAGE')
            ->assertJsonPath('data.versions.0.cost.total', '6500.00');
    }

    public function test_stock_card_is_append_only(): void
    {
        $this->opening($this->otl1, [$this->line('PK-BOWL', 'pcs', 30, ['unit_cost' => 600])]);
        $this->expectException(QueryException::class);
        StockMovement::query()->update(['qty' => 1]);
    }
}
