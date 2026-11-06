<?php

namespace Tests\Feature\Purchasing;

use App\Modules\Approval\Models\ApprovalRequest;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Supplier;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\RoleAssignment;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Location\Models\Location;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseRequest;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class PurchasingTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private Location $gdg;

    private User $owner;

    private User $finance;

    private User $gudang;

    private User $kasir;

    private Item $ayam;

    private Item $tepung;

    private Unit $kg;

    private Unit $g;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::create(2026, 11, 6, 10, 0, 0, 'Asia/Jakarta'));
        $this->g = Unit::query()->create(['code' => 'g', 'name' => 'gram']);
        $this->kg = Unit::query()->create(['code' => 'kg', 'name' => 'kilogram']);
        $this->ayam = $this->item('BB-AYAM', 'Daging ayam');
        $this->tepung = $this->item('BB-TEPUNG', 'Tepung terigu');
        $this->gdg = $this->warehouse();
        $otl = $this->outlet('OTL01');
        $this->owner = $this->owner();
        $this->finance = $this->userWithRole(Role::FINANCE);
        $this->gudang = $this->userWithRole(Role::WAREHOUSE, [$this->gdg->id]);
        $this->kasir = $this->userWithRole(Role::CASHIER, [$otl->id]);
        $this->supplier = Supplier::query()->create(['code' => 'SUP-AYAM', 'name' => 'CV Ayam Segar', 'payment_term_days' => 14]);
    }

    private function item(string $code, string $name): Item
    {
        $item = Item::query()->create(['code' => $code, 'name' => $name, 'item_type' => Item::TYPE_RAW, 'base_unit_id' => $this->g->id]);
        $item->units()->create(['unit_id' => $this->g->id, 'factor_to_base' => 1]);
        $item->units()->create(['unit_id' => $this->kg->id, 'factor_to_base' => 1000]);

        return $item;
    }

    /** Gudang mengajukan PR 10 kg ayam + 5 kg tepung; Finance menyetujui. */
    private function approvedRequest(): PurchaseRequest
    {
        Sanctum::actingAs($this->gudang);
        $id = $this->postJson('/api/v1/purchasing/requests', [
            'needed_by' => '2026-11-08', 'reason' => 'Stok ayam menipis',
            'lines' => [
                ['item_id' => $this->ayam->id, 'unit_id' => $this->kg->id, 'qty' => 10],
                ['item_id' => $this->tepung->id, 'unit_id' => $this->kg->id, 'qty' => 5],
            ],
        ])->assertCreated()->assertJsonPath('data.status', 'PENDING')->assertJsonPath('data.lines.0.item.name', 'Daging ayam')->json('data.id');
        $pr = PurchaseRequest::query()->findOrFail($id);

        Sanctum::actingAs($this->finance);
        $this->postJson("/api/v1/approvals/{$pr->approval_request_id}/approve", ['note' => 'Ok'])->assertOk()->assertJsonPath('data.status', 'APPROVED');

        return $pr->fresh();
    }

    /** @return array<int, int> purchase_line_id PR per item */
    private function prLines(PurchaseRequest $pr): array
    {
        return $pr->lines()->pluck('id', 'item_id')->all();
    }

    private function order(PurchaseRequest $pr, int $ayamPrice = 40000, int $tepungPrice = 12000): \Illuminate\Testing\TestResponse
    {
        $l = $this->prLines($pr);

        return $this->postJson('/api/v1/purchasing/orders', [
            'purchase_request_id' => $pr->id, 'supplier_id' => $this->supplier->id, 'expected_date' => '2026-11-07',
            'lines' => [
                ['purchase_line_id' => $l[$this->ayam->id], 'qty' => 10, 'unit_price' => $ayamPrice],
                ['purchase_line_id' => $l[$this->tepung->id], 'qty' => 5, 'unit_price' => $tepungPrice],
            ],
        ]);
    }

    /** @return array<int, int> purchase_line_id PO per item */
    private function poLines(int $poId): array
    {
        return PurchaseOrder::query()->findOrFail($poId)->lines()->pluck('id', 'item_id')->all();
    }

    public function test_full_flow_request_order_partial_receipt_with_qc_invoice_and_payment(): void
    {
        // Kasir tidak mengajukan pembelian (outlet tidak membeli stok sendiri).
        Sanctum::actingAs($this->kasir);
        $this->postJson('/api/v1/purchasing/requests', ['lines' => [['item_id' => $this->ayam->id, 'unit_id' => $this->kg->id, 'qty' => 1]]])->assertForbidden();
        $this->getJson('/api/v1/purchasing/requests')->assertForbidden();

        $pr = $this->approvedRequest();
        $this->assertSame('APPROVED', $pr->status);
        $this->assertSame(2, $pr->lines()->count());

        // Finance membuat PO di bawah batas: langsung dipesan.
        Sanctum::actingAs($this->finance);
        $po = $this->order($pr)->assertCreated()
            ->assertJsonPath('data.status', 'ORDERED')
            ->assertJsonPath('data.total', 460000)
            ->json('data');
        $this->assertSame('ORDERED', $pr->fresh()->status);
        // Satu PO berlaku per PR.
        $this->order($pr)->assertUnprocessable()->assertJsonValidationErrors('purchase_request_id');

        // Gudang melihat supplier dan jumlah, tanpa harga.
        Sanctum::actingAs($this->gudang);
        $this->getJson("/api/v1/purchasing/orders/{$po['id']}")->assertOk()
            ->assertJsonPath('data.supplier.name', 'CV Ayam Segar')
            ->assertJsonPath('data.total', null)
            ->assertJsonPath('data.lines.0.unit_price', null)
            ->assertJsonPath('data.can.receive', true);

        // Penerimaan pertama: ayam 6 kg baik + 1 kg ditolak (rusak); tepung belum datang.
        $l = $this->poLines($po['id']);
        $this->postJson("/api/v1/purchasing/orders/{$po['id']}/receive", [
            'supplier_doc' => 'SJ-001',
            'lines' => [['purchase_line_id' => $l[$this->ayam->id], 'qty_accepted' => 6, 'qty_rejected' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors('lines.0.reject_reason');
        $this->postJson("/api/v1/purchasing/orders/{$po['id']}/receive", [
            'lines' => [['purchase_line_id' => $l[$this->ayam->id], 'qty_accepted' => 11]],
        ])->assertUnprocessable()->assertJsonValidationErrors('lines.0.qty_accepted');
        $this->postJson("/api/v1/purchasing/orders/{$po['id']}/receive", [
            'supplier_doc' => 'SJ-001',
            'lines' => [['purchase_line_id' => $l[$this->ayam->id], 'qty_accepted' => 6, 'qty_rejected' => 1, 'reject_reason' => 'DAMAGED', 'reject_note' => 'Kemasan sobek', 'expiry_date' => '2026-11-20']],
        ])->assertCreated()->assertJsonPath('data.status', 'PARTIAL')->assertJsonPath('data.receipts.0.accepted_value', null);

        // Hanya barang baik masuk stok, dengan harga PO per gram.
        $balance = StockBalance::query()->where(['item_id' => $this->ayam->id, 'location_id' => $this->gdg->id])->firstOrFail();
        $this->assertSame(0, bccomp((string) $balance->qty, '6000', 4));
        $this->assertSame(0, bccomp((string) $balance->avg_cost, '40', 4));
        $this->assertSame(1, StockBatch::query()->where(['item_id' => $this->ayam->id, 'expiry_date' => '2026-11-20'])->count());
        $this->assertContains('QC_REJECTED', AuditLog::query()->where('action', 'GOODS_RECEIVED')->firstOrFail()->flags);

        // Sisa pesanan tetap terbuka: ayam 4 kg, tepung 5 kg.
        $detail = $this->getJson("/api/v1/purchasing/orders/{$po['id']}")->json('data.lines');
        $this->assertSame(['4', '5'], array_map(fn ($x) => rtrim(rtrim($x['remaining'], '0'), '.'), $detail));

        // Penerimaan kedua melengkapi PO.
        $this->postJson("/api/v1/purchasing/orders/{$po['id']}/receive", [
            'lines' => [
                ['purchase_line_id' => $l[$this->ayam->id], 'qty_accepted' => 4],
                ['purchase_line_id' => $l[$this->tepung->id], 'qty_accepted' => 5],
            ],
        ])->assertCreated()->assertJsonPath('data.status', 'RECEIVED');
        $this->postJson("/api/v1/purchasing/orders/{$po['id']}/receive", [
            'lines' => [['purchase_line_id' => $l[$this->tepung->id], 'qty_accepted' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors('status');

        // Gudang tidak melihat hutang.
        $this->getJson('/api/v1/purchasing/payables')->assertForbidden();

        // Finance mencatat tagihan per penerimaan, sebesar barang yang diterima baik.
        Sanctum::actingAs($this->finance);
        $payables = $this->getJson('/api/v1/purchasing/payables')->assertOk()->json('data');
        $this->assertCount(2, $payables['uninvoiced']);
        $first = GoodsReceipt::query()->orderBy('id')->firstOrFail();
        $this->assertSame(240000, $first->accepted_value);
        $this->postJson("/api/v1/purchasing/receipts/{$first->id}/invoice", ['invoice_no' => 'INV-77', 'invoice_date' => '2026-11-06', 'amount' => 250000])
            ->assertUnprocessable()->assertJsonValidationErrors('difference_note');
        $invoice = $this->postJson("/api/v1/purchasing/receipts/{$first->id}/invoice", [
            'invoice_no' => 'INV-77', 'invoice_date' => '2026-11-06', 'amount' => 250000, 'difference_note' => 'Harga ayam naik dari supplier',
        ])->assertCreated()->assertJsonPath('data.due_date', '2026-11-20')->assertJsonPath('data.outstanding', 250000)->json('data');
        $this->assertContains('PRICE_DIFFERENCE', AuditLog::query()->where('action', 'SUPPLIER_INVOICE_RECORDED')->firstOrFail()->flags);
        // Nilai stok tidak diubah mundur.
        $this->assertSame(0, bccomp((string) $balance->fresh()->avg_cost, '40', 4));
        $this->postJson("/api/v1/purchasing/receipts/{$first->id}/invoice", ['invoice_no' => 'INV-78', 'invoice_date' => '2026-11-06', 'amount' => 240000])
            ->assertUnprocessable()->assertJsonValidationErrors('goods_receipt_id');

        // Bayar sebagian, lalu lunasi; tidak boleh melebihi sisa.
        $this->postJson("/api/v1/purchasing/invoices/{$invoice['id']}/pay", ['amount' => 100000, 'paid_date' => '2026-11-06', 'method' => 'TRANSFER', 'reference' => 'TRF-1'])
            ->assertCreated()->assertJsonPath('data.outstanding', 150000);
        $this->postJson("/api/v1/purchasing/invoices/{$invoice['id']}/pay", ['amount' => 150001, 'paid_date' => '2026-11-06', 'method' => 'TRANSFER'])
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->postJson("/api/v1/purchasing/invoices/{$invoice['id']}/pay", ['amount' => 150000, 'paid_date' => '2026-11-07', 'method' => 'CASH'])
            ->assertUnprocessable()->assertJsonValidationErrors('paid_date');
        $this->postJson("/api/v1/purchasing/invoices/{$invoice['id']}/pay", ['amount' => 150000, 'paid_date' => '2026-11-06', 'method' => 'CASH'])
            ->assertCreated()->assertJsonPath('data.outstanding', 0);

        $after = $this->getJson('/api/v1/purchasing/payables')->json('data');
        $this->assertCount(0, $after['invoices']);
        $this->assertCount(1, $after['uninvoiced']);
        $this->assertSame(2, AuditLog::query()->where('action', 'SUPPLIER_PAID')->count());
    }

    public function test_order_above_finance_limit_waits_for_owner(): void
    {
        // Owner mengatur batas; Finance tidak bisa mengubahnya.
        Sanctum::actingAs($this->finance);
        $this->getJson('/api/v1/purchasing/settings')->assertOk()->assertJsonPath('data.finance_limit', 5000000)->assertJsonPath('data.can_edit', false);
        $this->putJson('/api/v1/purchasing/settings', ['finance_limit' => 100])->assertForbidden();
        Sanctum::actingAs($this->owner);
        $this->putJson('/api/v1/purchasing/settings', ['finance_limit' => 300000])->assertOk()->assertJsonPath('data.finance_limit', 300000);
        $this->assertSame(1, AuditLog::query()->where('action', 'SETTING_CHANGED')->count());

        $pr = $this->approvedRequest();
        Sanctum::actingAs($this->finance);
        $po = $this->order($pr)->assertCreated()->assertJsonPath('data.status', 'WAITING_OWNER')->json('data');
        $approvalId = PurchaseOrder::query()->findOrFail($po['id'])->approval_request_id;
        $this->assertSame(ApprovalRequest::TYPE_PURCHASE_ORDER, ApprovalRequest::query()->findOrFail($approvalId)->type);

        // Belum bisa diterima atau dibatalkan dari halaman PO.
        Sanctum::actingAs($this->gudang);
        $l = $this->poLines($po['id']);
        $this->postJson("/api/v1/purchasing/orders/{$po['id']}/receive", ['lines' => [['purchase_line_id' => $l[$this->ayam->id], 'qty_accepted' => 1]]])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        // Finance tidak memutuskan PO-nya sendiri.
        Sanctum::actingAs($this->finance);
        $this->postJson("/api/v1/approvals/{$approvalId}/approve")->assertForbidden();

        // Owner menolak: PO ditolak, PR kembali bisa dipesan.
        Sanctum::actingAs($this->owner);
        $this->getJson('/api/v1/approvals/summary')->assertJsonPath('data.waiting_my_decision', 1);
        $this->postJson("/api/v1/approvals/{$approvalId}/reject", ['note' => 'Cari supplier lebih murah'])->assertOk();
        $this->assertSame('REJECTED', PurchaseOrder::query()->findOrFail($po['id'])->status);
        $this->assertSame('APPROVED', $pr->fresh()->status);

        // PO baru dari PR yang sama; Owner menyetujui.
        Sanctum::actingAs($this->finance);
        $po2 = $this->order($pr, 35000, 11000)->assertCreated()->assertJsonPath('data.status', 'WAITING_OWNER')->json('data');
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/v1/approvals/'.PurchaseOrder::query()->findOrFail($po2['id'])->approval_request_id.'/approve')->assertOk();
        $this->getJson("/api/v1/purchasing/orders/{$po2['id']}")->assertJsonPath('data.status', 'ORDERED')->assertJsonPath('data.total', 405000);
    }

    public function test_cancel_close_and_separation_of_duties(): void
    {
        $pr = $this->approvedRequest();

        // Finance menutup PR yang tidak jadi dibeli.
        Sanctum::actingAs($this->finance);
        $this->postJson("/api/v1/purchasing/requests/{$pr->id}/close", [])->assertUnprocessable()->assertJsonValidationErrors('reason');

        // PO dibatalkan sebelum barang datang: PR bisa dipesan ulang.
        $po = $this->order($pr)->assertCreated()->json('data');
        $this->postJson("/api/v1/purchasing/orders/{$po['id']}/cancel", ['reason' => 'Supplier kosong'])->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        $this->assertSame('APPROVED', $pr->fresh()->status);

        // PO kedua dibuat oleh orang yang juga memegang peran gudang: ia tidak boleh menerima barangnya.
        $both = $this->userWithRole(Role::FINANCE);
        $assignment = RoleAssignment::query()->create([
            'user_id' => $both->id, 'role_id' => Role::idFor(Role::WAREHOUSE), 'scope' => RoleAssignment::SCOPE_SELECTED,
            'status' => RoleAssignment::STATUS_ACTIVE, 'confirmed_at' => now(),
        ]);
        $assignment->locations()->sync([$this->gdg->id]);
        Sanctum::actingAs($both);
        $po2 = $this->order($pr)->assertCreated()->json('data');
        $l = $this->poLines($po2['id']);
        $this->getJson("/api/v1/purchasing/orders/{$po2['id']}")->assertJsonPath('data.can.receive', false);
        $this->postJson("/api/v1/purchasing/orders/{$po2['id']}/receive", ['lines' => [['purchase_line_id' => $l[$this->ayam->id], 'qty_accepted' => 10]]])->assertForbidden();

        // Gudang lain menerima sebagian; Finance menutup sisanya dengan alasan.
        Sanctum::actingAs($this->gudang);
        $this->postJson("/api/v1/purchasing/orders/{$po2['id']}/receive", ['lines' => [['purchase_line_id' => $l[$this->ayam->id], 'qty_accepted' => 10]]])
            ->assertCreated()->assertJsonPath('data.status', 'PARTIAL');
        $this->postJson("/api/v1/purchasing/orders/{$po2['id']}/close", ['reason' => 'x'])->assertForbidden();
        Sanctum::actingAs($this->finance);
        $this->postJson("/api/v1/purchasing/orders/{$po2['id']}/cancel", ['reason' => 'x'])->assertUnprocessable();
        $this->postJson("/api/v1/purchasing/orders/{$po2['id']}/close", ['reason' => 'Tepung tidak tersedia, beli di tempat lain'])
            ->assertOk()->assertJsonPath('data.status', 'CLOSED');
        $this->assertSame(1, AuditLog::query()->where('action', 'PURCHASE_ORDER_CLOSED')->count());

        // Catatan tidak dapat diubah atau dihapus.
        try {
            DB::transaction(fn () => DB::table('goods_receipts')->update(['accepted_value' => 1]));
            $this->fail('goods_receipts harus append-only');
        } catch (QueryException) {
        }
        $this->expectException(QueryException::class);
        DB::table('purchase_orders')->where('id', $po2['id'])->update(['status' => 'ORDERED']);
    }

    public function test_requester_can_cancel_pending_request_and_finance_can_reject(): void
    {
        Sanctum::actingAs($this->gudang);
        $make = fn () => $this->postJson('/api/v1/purchasing/requests', ['lines' => [['item_id' => $this->ayam->id, 'unit_id' => $this->kg->id, 'qty' => 2]]])
            ->assertCreated()->json('data');
        $a = $make();
        $b = $make();
        $this->getJson('/api/v1/purchasing/requests?status=OPEN')->assertOk()->assertJsonCount(2, 'data');
        $this->postJson("/api/v1/approvals/{$a['approval']['id']}/cancel")->assertOk();
        $this->assertSame('CANCELLED', PurchaseRequest::query()->findOrFail($a['id'])->status);

        Sanctum::actingAs($this->finance);
        $this->getJson('/api/v1/approvals/summary')->assertJsonPath('data.waiting_my_decision', 1);
        $this->postJson("/api/v1/approvals/{$b['approval']['id']}/reject", ['note' => 'Stok masih cukup'])->assertOk();
        $this->getJson("/api/v1/purchasing/requests/{$b['id']}")->assertJsonPath('data.status', 'REJECTED')
            ->assertJsonPath('data.approval.decision_note', 'Stok masih cukup')
            ->assertJsonPath('data.lines.0.item.name', 'Daging ayam');
    }
}
