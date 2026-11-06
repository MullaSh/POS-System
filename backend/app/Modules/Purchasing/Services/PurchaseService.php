<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\Approval\Models\ApprovalRequest;
use App\Modules\Approval\Services\ApprovalService;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Supplier;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\Quantities;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Location\Models\Location;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Purchasing\Models\PurchaseLine;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\System\Models\AppSetting;
use App\Support\Decimal;
use App\Support\DocumentNumber;
use App\Support\ControlBlocked;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pembelian (AB-14 s.d. AB-16; KP-08, KP-10, KP-11; keputusan Owner 6 Nov 2026):
 * - Gudang mengajukan PR berisi barang dan jumlah saja; Finance menyetujui (C10).
 * - Finance memilih supplier dan harga di PO. PO di atas batas Finance (pengaturan Owner, OD-10)
 *   disetujui Owner sebelum dipesan (2E-Q1a, diterapkan pada PO karena nilai baru diketahui di PO; P7-01 disetujui Owner 6 Nov 2026).
 * - Gudang menerima barang dengan QC Incoming: diterima baik masuk stok dengan harga PO, ditolak
 *   dikembalikan ke supplier dan tidak ditagih. Satu PO boleh diterima beberapa kali; PO yang belum
 *   lengkap dapat ditutup Finance dengan alasan (KOD-04).
 * - Pemesan (Finance) dan penerima barang (gudang) orang berbeda (AB-15). Harga beli hanya dilihat
 *   Owner dan Finance (2G-Q2).
 */
class PurchaseService
{
    public const DEFAULT_FINANCE_LIMIT = 5000000;

    public function __construct(
        private readonly Quantities $quantities,
        private readonly StockLedger $ledger,
        private readonly AuditLogger $audit,
    ) {}

    public static function financeLimit(): int
    {
        return AppSetting::int(AppSetting::PURCHASE_FINANCE_LIMIT, self::DEFAULT_FINANCE_LIMIT);
    }

    public function setFinanceLimit(int $amount, User $owner): void
    {
        $before = self::financeLimit();
        AppSetting::query()->updateOrCreate(['key' => AppSetting::PURCHASE_FINANCE_LIMIT], ['value' => ['v' => $amount], 'updated_by' => $owner->id]);
        $this->audit->record('SETTING_CHANGED', null, ['batas_persetujuan_finance' => ['dari' => $before, 'menjadi' => $amount]], Role::OWNER,
            user: $owner, subjectCode: AppSetting::PURCHASE_FINANCE_LIMIT);
    }

    /** @return array<string, mixed> */
    public static function requestRules(): array
    {
        return [
            ...Quantities::lineRules(),
            'needed_by' => ['nullable', 'date_format:Y-m-d'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    // --- Purchase Request ---

    /** @param  array<string, mixed>  $data */
    public function createRequest(array $data, User $user): PurchaseRequest
    {
        $warehouse = Location::query()->where('type', Location::TYPE_WAREHOUSE)->firstOrFail();
        if (! $user->hasRoleAt(Role::WAREHOUSE, $warehouse->id)) {
            throw new AuthorizationException('Purchase Request diajukan oleh staf gudang.');
        }

        return DB::transaction(function () use ($data, $user, $warehouse) {
            $pr = PurchaseRequest::query()->create([
                'number' => DocumentNumber::next('PR'),
                'location_id' => $warehouse->id,
                'status' => PurchaseRequest::STATUS_PENDING,
                'requested_by' => $user->id,
                'needed_by' => $data['needed_by'] ?? null,
            ]);
            $approval = app(ApprovalService::class)->submit(ApprovalRequest::TYPE_PURCHASE_REQUEST, $pr, $data, $data['reason'] ?? null, $user, Role::WAREHOUSE);
            $pr->update(['approval_request_id' => $approval->id]);

            return $pr;
        });
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function buildRequestPayload(PurchaseRequest $pr, array $input): array
    {
        $items = $this->quantities->stockItems($input['lines']);
        $lines = [];
        foreach ($input['lines'] as $i => $l) {
            /** @var Item $item */
            $item = $items->get($l['item_id']);
            $factor = $this->quantities->factor($item, (int) $l['unit_id'], "lines.{$i}.unit_id");
            $qty = Decimal::round((string) $l['qty']);
            $lines[] = [
                'item_id' => $item->id, 'item_code' => $item->code, 'item_name' => $item->name,
                'unit_id' => (int) $l['unit_id'], 'unit_code' => $item->units()->where('unit_id', $l['unit_id'])->first()?->unit?->code,
                'qty' => $qty, 'factor' => $factor, 'qty_base' => $this->quantities->toBase($qty, $factor),
            ];
        }

        return ['needed_by' => $input['needed_by'] ?? $pr->needed_by?->format('Y-m-d'), 'lines' => $lines];
    }

    public function applyRequest(ApprovalRequest $request): void
    {
        $pr = PurchaseRequest::query()->lockForUpdate()->findOrFail($request->subject_id);
        foreach ($request->payload['lines'] as $pos => $l) {
            PurchaseLine::query()->create([
                'document_type' => 'PR', 'document_id' => $pr->id, 'item_id' => $l['item_id'], 'unit_id' => $l['unit_id'],
                'qty' => $l['qty'], 'factor' => $l['factor'], 'qty_base' => $l['qty_base'], 'position' => $pos,
            ]);
        }
        $pr->update(['status' => PurchaseRequest::STATUS_APPROVED, 'approved_at' => now(), 'needed_by' => $request->payload['needed_by'] ?? null]);
    }

    public function requestClosed(ApprovalRequest $request): void
    {
        PurchaseRequest::query()->whereKey($request->subject_id)->update([
            'status' => $request->status === ApprovalRequest::STATUS_REJECTED ? PurchaseRequest::STATUS_REJECTED : PurchaseRequest::STATUS_CANCELLED,
        ]);
    }

    public function closeRequest(PurchaseRequest $pr, string $reason, User $user): PurchaseRequest
    {
        $this->ensureFinance($user);

        return DB::transaction(function () use ($pr, $reason, $user) {
            $pr = PurchaseRequest::query()->lockForUpdate()->findOrFail($pr->id);
            if ($pr->status !== PurchaseRequest::STATUS_APPROVED) {
                throw ValidationException::withMessages(['status' => 'Hanya PR yang sudah disetujui dan belum dipesan yang dapat ditutup.']);
            }
            $pr->update(['status' => PurchaseRequest::STATUS_CLOSED, 'close_note' => $reason]);
            $this->audit->record('PURCHASE_REQUEST_CLOSED', $pr, null, Role::FINANCE, $reason, user: $user, subjectCode: $pr->number);

            return $pr;
        });
    }

    // --- Purchase Order ---

    /** @return array<string, mixed> */
    public static function orderRules(): array
    {
        return [
            'purchase_request_id' => ['required', 'integer', 'exists:purchase_requests,id'],
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'expected_date' => ['nullable', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.purchase_line_id' => ['required', 'integer', 'distinct'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'lines.*.unit_price' => ['required', 'integer', 'min:1', 'max:10000000000'],
        ];
    }

    /** @param  array<string, mixed>  $data */
    public function createOrder(array $data, User $user): PurchaseOrder
    {
        $this->ensureFinance($user);
        $supplier = Supplier::query()->findOrFail($data['supplier_id']);
        if (! $supplier->is_active) {
            throw ValidationException::withMessages(['supplier_id' => "{$supplier->name} sedang nonaktif."]);
        }

        return DB::transaction(function () use ($data, $user, $supplier) {
            $pr = PurchaseRequest::query()->lockForUpdate()->findOrFail($data['purchase_request_id']);
            if ($pr->status !== PurchaseRequest::STATUS_APPROVED) {
                throw ValidationException::withMessages(['purchase_request_id' => 'PO dibuat dari PR yang sudah disetujui dan belum dipesan.']);
            }
            $prLines = $pr->lines()->get()->keyBy('id');
            $rows = [];
            $total = 0;
            foreach ($data['lines'] as $i => $l) {
                /** @var PurchaseLine|null $src */
                $src = $prLines->get($l['purchase_line_id']);
                if (! $src) {
                    throw ValidationException::withMessages(["lines.{$i}.purchase_line_id" => 'Barang ini tidak ada di PR.']);
                }
                $qty = Decimal::round((string) $l['qty']);
                $lineTotal = (int) round((float) bcmul($qty, (string) $l['unit_price'], 4));
                $total += $lineTotal;
                $rows[] = [$src, $qty, (int) $l['unit_price'], $lineTotal];
            }
            $needsOwner = $total > self::financeLimit();

            $po = PurchaseOrder::query()->create([
                'number' => DocumentNumber::next('PO'),
                'purchase_request_id' => $pr->id,
                'supplier_id' => $supplier->id,
                'location_id' => $pr->location_id,
                'status' => $needsOwner ? PurchaseOrder::STATUS_WAITING_OWNER : PurchaseOrder::STATUS_ORDERED,
                'total' => $total,
                'expected_date' => $data['expected_date'] ?? null,
                'note' => $data['note'] ?? null,
                'created_by' => $user->id,
                'ordered_at' => $needsOwner ? null : now(),
            ]);
            foreach ($rows as $pos => [$src, $qty, $price, $lineTotal]) {
                PurchaseLine::query()->create([
                    'document_type' => 'PO', 'document_id' => $po->id, 'item_id' => $src->item_id, 'unit_id' => $src->unit_id,
                    'qty' => $qty, 'factor' => $src->factor, 'qty_base' => $this->quantities->toBase($qty, (string) $src->factor),
                    'unit_price' => $price, 'line_total' => $lineTotal, 'position' => $pos,
                ]);
            }
            $pr->update(['status' => PurchaseRequest::STATUS_ORDERED]);
            $this->audit->record('PURCHASE_ORDER_CREATED', $po, [
                'supplier' => $supplier->code, 'total' => $total, 'pr' => $pr->number, 'perlu_owner' => $needsOwner,
            ], Role::FINANCE, $data['note'] ?? null, user: $user, subjectCode: $po->number);

            if ($needsOwner) {
                $approval = app(ApprovalService::class)->submit(ApprovalRequest::TYPE_PURCHASE_ORDER, $po, [], $data['note'] ?? null, $user, Role::FINANCE);
                $po->update(['approval_request_id' => $approval->id]);
            }

            return $po;
        });
    }

    /** @return array<string, mixed> */
    public function buildOrderPayload(PurchaseOrder $po): array
    {
        $po->loadMissing(['supplier', 'lines.item', 'lines.unit']);

        return [
            'supplier' => $po->supplier->name,
            'total' => $po->total,
            'limit' => self::financeLimit(),
            'lines' => $po->lines->map(fn (PurchaseLine $l) => [
                'item_name' => $l->item->name, 'qty' => (string) $l->qty, 'unit_code' => $l->unit->code,
                'unit_price' => $l->unit_price, 'line_total' => $l->line_total,
            ])->all(),
        ];
    }

    public function applyOrder(ApprovalRequest $request): void
    {
        PurchaseOrder::query()->whereKey($request->subject_id)->update(['status' => PurchaseOrder::STATUS_ORDERED, 'ordered_at' => now()]);
    }

    public function orderClosed(ApprovalRequest $request): void
    {
        $po = PurchaseOrder::query()->lockForUpdate()->findOrFail($request->subject_id);
        $po->update(['status' => $request->status === ApprovalRequest::STATUS_REJECTED ? PurchaseOrder::STATUS_REJECTED : PurchaseOrder::STATUS_CANCELLED]);
        PurchaseRequest::query()->whereKey($po->purchase_request_id)->update(['status' => PurchaseRequest::STATUS_APPROVED]);
    }

    /** Batalkan PO yang belum menerima barang; PR-nya bisa dipesan ulang. */
    public function cancelOrder(PurchaseOrder $po, string $reason, User $user): PurchaseOrder
    {
        $this->ensureFinance($user);

        return DB::transaction(function () use ($po, $reason, $user) {
            $po = PurchaseOrder::query()->lockForUpdate()->findOrFail($po->id);
            if ($po->status !== PurchaseOrder::STATUS_ORDERED || $po->receipts()->exists()) {
                throw ValidationException::withMessages(['status' => $po->status === PurchaseOrder::STATUS_WAITING_OWNER
                    ? 'PO ini masih menunggu Owner; batalkan pengajuannya di halaman Persetujuan.'
                    : 'Hanya PO yang belum menerima barang yang dapat dibatalkan. PO yang sudah menerima sebagian dapat ditutup.']);
            }
            $po->update(['status' => PurchaseOrder::STATUS_CANCELLED, 'closed_by' => $user->id, 'closed_at' => now(), 'close_note' => $reason]);
            PurchaseRequest::query()->whereKey($po->purchase_request_id)->update(['status' => PurchaseRequest::STATUS_APPROVED]);
            $this->audit->record('PURCHASE_ORDER_CANCELLED', $po, null, Role::FINANCE, $reason, user: $user, subjectCode: $po->number);

            return $po;
        });
    }

    /** Tutup PO yang sudah diterima sebagian; sisanya tidak akan dikirim (KOD-04). */
    public function closeOrder(PurchaseOrder $po, string $reason, User $user): PurchaseOrder
    {
        $this->ensureFinance($user);

        return DB::transaction(function () use ($po, $reason, $user) {
            $po = PurchaseOrder::query()->lockForUpdate()->findOrFail($po->id);
            if ($po->status !== PurchaseOrder::STATUS_PARTIAL) {
                throw ValidationException::withMessages(['status' => 'Hanya PO yang sudah diterima sebagian yang dapat ditutup.']);
            }
            $po->update(['status' => PurchaseOrder::STATUS_CLOSED, 'closed_by' => $user->id, 'closed_at' => now(), 'close_note' => $reason]);
            $this->audit->record('PURCHASE_ORDER_CLOSED', $po, ['sisa' => $this->remaining($po)], Role::FINANCE, $reason, user: $user, subjectCode: $po->number);

            return $po;
        });
    }

    /**
     * Sisa yang belum diterima baik per baris PO (dalam satuan PO).
     *
     * @return array<int, string>
     */
    public function remaining(PurchaseOrder $po): array
    {
        $accepted = GoodsReceiptLine::query()->whereIn('purchase_line_id', $po->lines()->select('id'))
            ->selectRaw('purchase_line_id, SUM(qty_accepted) AS q')->groupBy('purchase_line_id')->pluck('q', 'purchase_line_id');
        $out = [];
        foreach ($po->lines()->get() as $l) {
            $out[$l->id] = Decimal::round(bcsub((string) $l->qty, (string) ($accepted[$l->id] ?? '0'), 4));
        }

        return $out;
    }

    // --- Penerimaan barang + QC Incoming ---

    /** @return array<string, mixed> */
    public static function receiptRules(): array
    {
        return [
            'supplier_doc' => ['nullable', 'string', 'max:60'],
            'note' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.purchase_line_id' => ['required', 'integer', 'distinct'],
            'lines.*.qty_accepted' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'lines.*.qty_rejected' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'lines.*.reject_reason' => ['nullable', 'in:'.implode(',', GoodsReceiptLine::REASONS)],
            'lines.*.reject_note' => ['nullable', 'string', 'max:500'],
            'lines.*.batch_no' => ['nullable', 'string', 'max:60', 'regex:/^[A-Za-z0-9.\/-]+$/'],
            'lines.*.expiry_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /** @param  array<string, mixed>  $data */
    public function receive(PurchaseOrder $po, array $data, User $user): GoodsReceipt
    {
        if (! $user->hasRoleAt(Role::WAREHOUSE, $po->location_id)) {
            throw new AuthorizationException('Barang dari supplier diterima oleh staf gudang.');
        }
        if ($po->created_by === $user->id) {
            throw new ControlBlocked('Pembuat PO tidak boleh menerima barangnya sendiri (AB-15).', ControlBlocked::BUYER_RECEIVES, $po, $po->location_id);
        }

        return DB::transaction(function () use ($po, $data, $user) {
            $po = PurchaseOrder::query()->lockForUpdate()->findOrFail($po->id);
            if (! in_array($po->status, PurchaseOrder::RECEIVABLE, true)) {
                throw ValidationException::withMessages(['status' => $po->status === PurchaseOrder::STATUS_WAITING_OWNER
                    ? 'PO ini masih menunggu persetujuan Owner.'
                    : 'PO ini sudah tidak menerima barang.']);
            }
            $lines = $po->lines()->with('item')->get()->keyBy('id');
            $remaining = $this->remaining($po);
            $location = Location::query()->findOrFail($po->location_id);

            $rows = [];
            $value = 0;
            $rejectedAny = false;
            foreach ($data['lines'] as $i => $l) {
                /** @var PurchaseLine|null $pl */
                $pl = $lines->get($l['purchase_line_id']);
                if (! $pl) {
                    throw ValidationException::withMessages(["lines.{$i}.purchase_line_id" => 'Barang ini tidak ada di PO.']);
                }
                $accepted = Decimal::round((string) $l['qty_accepted']);
                $rejected = Decimal::round((string) ($l['qty_rejected'] ?? '0'));
                if (Decimal::isZero($accepted) && Decimal::isZero($rejected)) {
                    continue;
                }
                if (Decimal::cmp($accepted, $remaining[$pl->id]) > 0) {
                    throw ValidationException::withMessages(["lines.{$i}.qty_accepted" => "{$pl->item->name}: sisa pesanan ".self::plain($remaining[$pl->id]).'. Kelebihan kiriman tidak diterima.']);
                }
                if (! Decimal::isZero($rejected) && empty($l['reject_reason'])) {
                    throw ValidationException::withMessages(["lines.{$i}.reject_reason" => "Pilih alasan {$pl->item->name} ditolak."]);
                }
                $lineValue = (int) round((float) bcmul($accepted, (string) $pl->unit_price, 4));
                $value += $lineValue;
                $rejectedAny = $rejectedAny || ! Decimal::isZero($rejected);
                $rows[] = [$pl, $accepted, $rejected, $lineValue, $l];
            }
            if ($rows === []) {
                throw ValidationException::withMessages(['lines' => 'Isi jumlah yang diterima atau ditolak minimal untuk satu barang.']);
            }

            $receipt = GoodsReceipt::query()->create([
                'number' => DocumentNumber::next('TRM'),
                'purchase_order_id' => $po->id,
                'location_id' => $po->location_id,
                'received_by' => $user->id,
                'supplier_doc' => $data['supplier_doc'] ?? null,
                'note' => $data['note'] ?? null,
                'accepted_value' => $value,
                'received_at' => now(),
            ]);
            $source = ['type' => 'goods_receipt', 'id' => $receipt->id, 'number' => $receipt->number];
            foreach ($rows as [$pl, $accepted, $rejected, $lineValue, $l]) {
                GoodsReceiptLine::query()->create([
                    'goods_receipt_id' => $receipt->id,
                    'purchase_line_id' => $pl->id,
                    'item_id' => $pl->item_id,
                    'qty_accepted' => $accepted,
                    'qty_rejected' => $rejected,
                    'reject_reason' => Decimal::isZero($rejected) ? null : $l['reject_reason'],
                    'reject_note' => $l['reject_note'] ?? null,
                    'batch_no' => $l['batch_no'] ?? null,
                    'expiry_date' => $l['expiry_date'] ?? null,
                    'accepted_value' => $lineValue,
                ]);
                if (! Decimal::isZero($accepted)) {
                    $unitCost = Decimal::round(bcdiv((string) $pl->unit_price, (string) $pl->factor, Decimal::WORK));
                    $this->ledger->receive($pl->item, $location, $this->quantities->toBase($accepted, (string) $pl->factor), $unitCost,
                        StockMovement::PURCHASE_RECEIPT, $source, $user, [
                            'batch_no' => ($l['batch_no'] ?? null) ?: $receipt->number,
                            'expiry_date' => $l['expiry_date'] ?? null,
                            'batch_source' => StockMovement::PURCHASE_RECEIPT,
                            'note' => $po->number,
                        ]);
                }
            }
            $after = $this->remaining($po);
            $done = collect($after)->every(fn ($q) => Decimal::cmp($q, '0') <= 0);
            $po->update(['status' => $done ? PurchaseOrder::STATUS_RECEIVED : PurchaseOrder::STATUS_PARTIAL]);
            $this->audit->record('GOODS_RECEIVED', $receipt, ['po' => $po->number, 'nilai_diterima' => $value], Role::WAREHOUSE,
                $data['note'] ?? null, $rejectedAny ? ['QC_REJECTED'] : [], locationId: $po->location_id, user: $user, subjectCode: $receipt->number);

            return $receipt;
        });
    }

    public static function plain(string $qty): string
    {
        return rtrim(rtrim($qty, '0'), '.') ?: '0';
    }

    private function ensureFinance(User $user): void
    {
        if (! $user->hasActiveRole(Role::FINANCE)) {
            throw new AuthorizationException('Langkah ini dikerjakan Finance.');
        }
    }
}
