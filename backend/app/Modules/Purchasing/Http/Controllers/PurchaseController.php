<?php

namespace App\Modules\Purchasing\Http\Controllers;

use App\Modules\Approval\Models\ApprovalRequest;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Purchasing\Models\PurchaseLine;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Models\SupplierInvoice;
use App\Modules\Purchasing\Models\SupplierPayment;
use App\Modules\Purchasing\Services\PayableService;
use App\Modules\Purchasing\Services\PurchaseService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Pembelian: PR, PO, penerimaan + QC, tagihan, pembayaran, hutang.
 * Owner dan Finance melihat semua dengan harga; gudang melihat PR, PO, dan penerimaan tanpa harga (2G-Q2).
 */
class PurchaseController
{
    public function __construct(
        private readonly PurchaseService $purchases,
        private readonly PayableService $payables,
    ) {}

    private function seesPrices(User $u): bool
    {
        return $u->hasActiveRole(Role::OWNER) || $u->hasActiveRole(Role::FINANCE);
    }

    private function ensureCanView(User $u): void
    {
        if (! $this->seesPrices($u) && ! $u->hasActiveRole(Role::WAREHOUSE)) {
            throw new AuthorizationException('Pembelian dilihat Owner, Finance, dan gudang.');
        }
    }

    // --- PR ---

    public function requests(Request $request): JsonResponse
    {
        $this->ensureCanView($request->user());
        $data = $request->validate(['status' => ['nullable', Rule::in(['PENDING', 'APPROVED', 'ORDERED', 'REJECTED', 'CANCELLED', 'CLOSED'])]]);
        $page = PurchaseRequest::query()->with(['requester:id,name', 'approval', 'lines.item', 'lines.unit'])
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('id')->paginate(50);
        $page->getCollection()->transform(fn (PurchaseRequest $pr) => $this->presentRequest($pr, $request->user()));

        return response()->json($page);
    }

    public function showRequest(Request $request, PurchaseRequest $pr): JsonResponse
    {
        $this->ensureCanView($request->user());

        return response()->json(['data' => $this->presentRequest($pr, $request->user(), true)]);
    }

    public function storeRequest(Request $request): JsonResponse
    {
        $data = $request->validate(PurchaseService::requestRules(), ['lines.required' => 'Tambahkan minimal satu barang.']);
        $pr = $this->purchases->createRequest($data, $request->user());

        return response()->json(['data' => $this->presentRequest($pr->fresh(), $request->user(), true)], 201);
    }

    public function closeRequest(Request $request, PurchaseRequest $pr): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']], ['reason.required' => 'Tuliskan alasan PR ditutup.']);
        $pr = $this->purchases->closeRequest($pr, $data['reason'], $request->user());

        return response()->json(['data' => $this->presentRequest($pr->fresh(), $request->user(), true)]);
    }

    // --- PO ---

    public function orders(Request $request): JsonResponse
    {
        $this->ensureCanView($request->user());
        $data = $request->validate(['status' => ['nullable', Rule::in(['OPEN', 'WAITING_OWNER', 'ORDERED', 'PARTIAL', 'RECEIVED', 'CLOSED', 'REJECTED', 'CANCELLED'])]]);
        $page = PurchaseOrder::query()->with(['supplier', 'request:id,number', 'creator:id,name'])
            ->when(($data['status'] ?? null) === 'OPEN', fn ($q) => $q->whereIn('status', [...PurchaseOrder::RECEIVABLE, PurchaseOrder::STATUS_WAITING_OWNER]))
            ->when(($data['status'] ?? null) && $data['status'] !== 'OPEN', fn ($q) => $q->where('status', $data['status']))
            ->orderByDesc('id')->paginate(50);
        $page->getCollection()->transform(fn (PurchaseOrder $po) => $this->presentOrder($po, $request->user()));

        return response()->json($page);
    }

    public function showOrder(Request $request, PurchaseOrder $po): JsonResponse
    {
        $this->ensureCanView($request->user());

        return response()->json(['data' => $this->presentOrder($po, $request->user(), true)]);
    }

    public function storeOrder(Request $request): JsonResponse
    {
        $data = $request->validate(PurchaseService::orderRules(), ['supplier_id.required' => 'Pilih supplier.']);
        $po = $this->purchases->createOrder($data, $request->user());

        return response()->json(['data' => $this->presentOrder($po->fresh(), $request->user(), true)], 201);
    }

    public function cancelOrder(Request $request, PurchaseOrder $po): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']], ['reason.required' => 'Tuliskan alasannya.']);
        $po = $this->purchases->cancelOrder($po, $data['reason'], $request->user());

        return response()->json(['data' => $this->presentOrder($po->fresh(), $request->user(), true)]);
    }

    public function closeOrder(Request $request, PurchaseOrder $po): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']], ['reason.required' => 'Tuliskan alasannya.']);
        $po = $this->purchases->closeOrder($po, $data['reason'], $request->user());

        return response()->json(['data' => $this->presentOrder($po->fresh(), $request->user(), true)]);
    }

    public function receive(Request $request, PurchaseOrder $po): JsonResponse
    {
        $data = $request->validate(PurchaseService::receiptRules());
        $this->purchases->receive($po, $data, $request->user());

        return response()->json(['data' => $this->presentOrder($po->fresh(), $request->user(), true)], 201);
    }

    // --- Tagihan dan hutang ---

    public function storeInvoice(Request $request, GoodsReceipt $receipt): JsonResponse
    {
        $data = $request->validate(PayableService::invoiceRules(), ['invoice_no.required' => 'Isi nomor tagihan dari supplier.']);
        $invoice = $this->payables->recordInvoice($receipt, $data, $request->user());

        return response()->json(['data' => $this->presentInvoice($invoice->fresh())], 201);
    }

    public function payables(Request $request): JsonResponse
    {
        if (! $this->seesPrices($request->user())) {
            throw new AuthorizationException('Hutang supplier dilihat Owner dan Finance.');
        }
        $data = $request->validate(['status' => ['nullable', Rule::in(['OPEN', 'ALL'])]]);
        $invoices = SupplierInvoice::query()->with(['supplier', 'receipt.order:id,number', 'payments.payer:id,name', 'recorder:id,name'])
            ->withSum('payments', 'amount')->orderBy('due_date')->orderBy('id')->limit(500)->get()
            ->map(fn (SupplierInvoice $i) => $this->presentInvoice($i));
        if (($data['status'] ?? 'OPEN') === 'OPEN') {
            $invoices = $invoices->filter(fn ($i) => $i['outstanding'] > 0)->values();
        }
        $uninvoiced = GoodsReceipt::query()->with(['order.supplier', 'receiver:id,name'])->whereDoesntHave('invoice')->where('accepted_value', '>', 0)
            ->orderBy('received_at')->get()->map(fn (GoodsReceipt $r) => [
                'id' => $r->id, 'number' => $r->number, 'po' => $r->order?->number, 'supplier' => $r->order?->supplier?->name,
                'received_at' => $r->received_at, 'accepted_value' => $r->accepted_value, 'supplier_doc' => $r->supplier_doc,
                'term_days' => $r->order?->supplier?->payment_term_days,
            ]);
        $bySupplier = $invoices->groupBy('supplier.id')->map(fn ($g) => [
            'supplier' => $g->first()['supplier'], 'outstanding' => $g->sum('outstanding'), 'overdue' => $g->where('overdue', true)->sum('outstanding'),
        ])->values();

        return response()->json(['data' => [
            'invoices' => $invoices, 'uninvoiced' => $uninvoiced, 'by_supplier' => $bySupplier,
            'total_outstanding' => $invoices->sum('outstanding'),
            'can_act' => $request->user()->hasActiveRole(Role::FINANCE),
        ]]);
    }

    public function pay(Request $request, SupplierInvoice $invoice): JsonResponse
    {
        $data = $request->validate(PayableService::paymentRules());
        $this->payables->pay($invoice, $data, $request->user());

        return response()->json(['data' => $this->presentInvoice($invoice->fresh())], 201);
    }

    // --- Pengaturan batas persetujuan Finance (OD-10) ---

    public function settings(Request $request): JsonResponse
    {
        if (! $this->seesPrices($request->user())) {
            throw new AuthorizationException('Pengaturan pembelian dilihat Owner dan Finance.');
        }

        return response()->json(['data' => ['finance_limit' => PurchaseService::financeLimit(), 'can_edit' => $request->user()->hasActiveRole(Role::OWNER)]]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate(['finance_limit' => ['required', 'integer', 'min:0', 'max:100000000000']]);
        $this->purchases->setFinanceLimit((int) $data['finance_limit'], $request->user());

        return $this->settings($request);
    }

    // --- Bentuk data ---

    /** @return array<string, mixed> */
    private function lineRow(PurchaseLine $l, bool $prices): array
    {
        return [
            'id' => $l->id,
            'item' => $l->item?->only(['id', 'code', 'name']),
            'unit' => $l->unit?->only(['id', 'code']),
            'qty' => (string) $l->qty,
            'unit_price' => $prices ? $l->unit_price : null,
            'line_total' => $prices ? $l->line_total : null,
        ];
    }

    /** @return array<string, mixed> */
    private function presentRequest(PurchaseRequest $pr, User $viewer, bool $detail = false): array
    {
        $pr->loadMissing(['requester:id,name', 'approval', 'lines.item', 'lines.unit']);
        $lines = $pr->lines->isNotEmpty()
            ? $pr->lines->map(fn ($l) => $this->lineRow($l, false))->values()->all()
            : array_map(fn ($l) => ['id' => null, 'item' => ['id' => $l['item_id'], 'code' => $l['item_code'], 'name' => $l['item_name']], 'unit' => ['id' => $l['unit_id'], 'code' => $l['unit_code']], 'qty' => (string) $l['qty'], 'unit_price' => null, 'line_total' => null], $pr->approval?->payload['lines'] ?? []);

        return [
            'id' => $pr->id,
            'number' => $pr->number,
            'status' => $pr->status,
            'requested_by' => $pr->requester?->only(['id', 'name']),
            'created_at' => $pr->created_at,
            'needed_by' => $pr->needed_by?->format('Y-m-d'),
            'reason' => $pr->approval?->reason,
            'approval' => $pr->approval ? ['id' => $pr->approval->id, 'number' => $pr->approval->number, 'status' => $pr->approval->status, 'decision_note' => $pr->approval->decision_note] : null,
            'close_note' => $pr->close_note,
            'lines' => $lines,
            'orders' => $detail ? $pr->orders()->get(['id', 'number', 'status'])->toArray() : null,
            'can' => [
                'order' => $pr->status === PurchaseRequest::STATUS_APPROVED && $viewer->hasActiveRole(Role::FINANCE),
                'close' => $pr->status === PurchaseRequest::STATUS_APPROVED && $viewer->hasActiveRole(Role::FINANCE),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function presentOrder(PurchaseOrder $po, User $viewer, bool $detail = false): array
    {
        $prices = $this->seesPrices($viewer);
        $po->loadMissing(['supplier', 'request:id,number', 'creator:id,name', 'closer:id,name', 'approval']);
        $remaining = $detail ? $this->purchases->remaining($po) : [];
        $receivable = in_array($po->status, PurchaseOrder::RECEIVABLE, true);

        return [
            'id' => $po->id,
            'number' => $po->number,
            'status' => $po->status,
            'request' => $po->request?->only(['id', 'number']),
            'supplier' => $po->supplier?->only(['id', 'code', 'name', 'phone', 'payment_term_days']),
            'total' => $prices ? $po->total : null,
            'expected_date' => $po->expected_date?->format('Y-m-d'),
            'note' => $po->note,
            'created_by' => $po->creator?->only(['id', 'name']),
            'created_at' => $po->created_at,
            'ordered_at' => $po->ordered_at,
            'closed_by' => $po->closer?->only(['id', 'name']),
            'closed_at' => $po->closed_at,
            'close_note' => $po->close_note,
            'approval' => $po->approval ? ['id' => $po->approval->id, 'number' => $po->approval->number, 'status' => $po->approval->status] : null,
            'lines' => $detail ? $po->lines()->with(['item', 'unit'])->get()->map(fn ($l) => [...$this->lineRow($l, $prices), 'remaining' => $remaining[$l->id] ?? null])->values() : null,
            'receipts' => $detail ? $po->receipts()->with(['receiver:id,name', 'lines.item', 'invoice'])->get()->map(fn (GoodsReceipt $r) => [
                'id' => $r->id, 'number' => $r->number, 'received_by' => $r->receiver?->only(['id', 'name']), 'received_at' => $r->received_at,
                'supplier_doc' => $r->supplier_doc, 'note' => $r->note, 'accepted_value' => $prices ? $r->accepted_value : null,
                'invoice' => $r->invoice ? ['id' => $r->invoice->id, 'number' => $r->invoice->number] : null,
                'lines' => $r->lines->map(fn (GoodsReceiptLine $gl) => [
                    'item' => $gl->item?->only(['id', 'name']), 'qty_accepted' => (string) $gl->qty_accepted, 'qty_rejected' => (string) $gl->qty_rejected,
                    'reject_reason' => $gl->reject_reason, 'reject_note' => $gl->reject_note, 'batch_no' => $gl->batch_no, 'expiry_date' => $gl->expiry_date?->format('Y-m-d'),
                ])->values(),
            ])->values() : null,
            'can' => [
                'receive' => $receivable && $viewer->hasRoleAt(Role::WAREHOUSE, $po->location_id) && $po->created_by !== $viewer->id,
                'cancel' => $po->status === PurchaseOrder::STATUS_ORDERED && $viewer->hasActiveRole(Role::FINANCE) && ! $po->receipts()->exists(),
                'close' => $po->status === PurchaseOrder::STATUS_PARTIAL && $viewer->hasActiveRole(Role::FINANCE),
                'invoice' => $viewer->hasActiveRole(Role::FINANCE),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function presentInvoice(SupplierInvoice $i): array
    {
        $i->loadMissing(['supplier', 'receipt.order:id,number', 'payments.payer:id,name', 'recorder:id,name']);
        $paid = (int) $i->payments->sum('amount');
        $outstanding = $i->amount - $paid;
        $today = now()->setTimezone(config('app.timezone'))->toDateString();

        return [
            'id' => $i->id,
            'number' => $i->number,
            'invoice_no' => $i->invoice_no,
            'supplier' => $i->supplier?->only(['id', 'code', 'name']),
            'receipt' => $i->receipt ? ['id' => $i->receipt->id, 'number' => $i->receipt->number, 'po' => $i->receipt->order?->only(['id', 'number'])] : null,
            'invoice_date' => $i->invoice_date?->format('Y-m-d'),
            'due_date' => $i->due_date?->format('Y-m-d'),
            'expected_amount' => $i->expected_amount,
            'amount' => $i->amount,
            'difference_note' => $i->difference_note,
            'paid' => $paid,
            'outstanding' => $outstanding,
            'overdue' => $outstanding > 0 && $i->due_date?->format('Y-m-d') < $today,
            'recorded_by' => $i->recorder?->only(['id', 'name']),
            'payments' => $i->payments->map(fn (SupplierPayment $p) => [
                'id' => $p->id, 'number' => $p->number, 'amount' => $p->amount, 'paid_date' => $p->paid_date?->format('Y-m-d'),
                'method' => $p->method, 'reference' => $p->reference, 'by' => $p->payer?->name,
            ])->values(),
        ];
    }
}
