<?php

namespace App\Modules\Sales\Services;

use App\Modules\Approval\Models\ApprovalRequest;
use App\Modules\Approval\Services\ApprovalService;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Sales\Models\CashierShift;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleLine;
use App\Modules\Sales\Models\SaleRefund;
use App\Modules\Sales\Models\SaleRefundLine;
use App\Modules\Sales\Models\SaleRefundPayment;
use App\Support\Decimal;
use App\Support\DocumentNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Void dan refund setelah bayar (KP-17, AB-24; keputusan Owner 6 Nov 2026):
 * - Kasir mengajukan dengan alasan dari shift yang sedang berjalan; berlaku setelah disetujui
 *   Owner atau penerima delegasinya. Boleh per menu dan per jumlah porsi.
 * - Kondisi per menu: "belum dibuat" → bahan kembali ke stok lewat catatan pembalik;
 *   "sudah dibuat/dibuang" → stok tidak kembali (HPP-nya dilaporkan sebagai waste).
 * - Uang dikembalikan lewat metode bayar transaksi asal, tunai lebih dulu; tunai diambil dari
 *   laci shift tempat pengajuan dibuat. Shift tidak bisa ditutup selama pengajuannya belum selesai.
 * - Void = seluruh transaksi dibatalkan sekaligus; refund = sebagian.
 */
class RefundService
{
    public function __construct(
        private readonly SalesAccess $access,
        private readonly StockLedger $ledger,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'reason_code' => ['required', 'in:'.implode(',', SaleRefund::REASONS)],
            'reason' => ['required', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.sale_line_id' => ['required', 'integer', 'distinct'],
            'lines.*.qty' => ['required', 'integer', 'min:1'],
            'lines.*.condition' => ['required', 'in:'.SaleRefund::CONDITION_NOT_MADE.','.SaleRefund::CONDITION_MADE],
        ];
    }

    /** @param  array<string, mixed>  $data */
    public function request(Sale $sale, array $data, User $user): SaleRefund
    {
        $sale->loadMissing('location');
        $this->access->ensureCashierAt($user, $sale->location);

        return DB::transaction(function () use ($sale, $data, $user) {
            Sale::query()->whereKey($sale->id)->sharedLock()->first();
            $shift = $this->ownOpenShift($sale, $user);
            $refund = SaleRefund::query()->create([
                'number' => DocumentNumber::next('RFD'),
                'sale_id' => $sale->id,
                'location_id' => $sale->location_id,
                'shift_id' => $shift->id,
                'reason_code' => $data['reason_code'],
                'requested_by' => $user->id,
            ]);
            $approval = app(ApprovalService::class)->submit(ApprovalRequest::TYPE_SALE_REFUND, $refund, $data, $data['reason'], $user, Role::CASHIER);
            $refund->update(['approval_request_id' => $approval->id]);

            return $refund;
        });
    }

    /**
     * Isi pengajuan: menu, jumlah, kondisi, nilai uang, pembagian per metode bayar, dan HPP.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function buildPayload(SaleRefund $refund, array $input): array
    {
        $sale = Sale::query()->with(['lines.item', 'payments.method'])->findOrFail($refund->sale_id);
        $shift = CashierShift::query()->findOrFail($refund->shift_id);
        if ($shift->status !== CashierShift::STATUS_OPEN) {
            throw ValidationException::withMessages(['shift' => "Shift {$shift->number} sudah tidak berjalan. Batalkan pengajuan ini lalu ajukan lagi dari shift yang sedang berjalan."]);
        }
        $other = SaleRefund::query()->where('sale_id', $sale->id)->where('id', '<>', $refund->id)
            ->whereHas('approval', fn ($q) => $q->whereIn('status', ApprovalRequest::OPEN_STATUSES))->first();
        if ($other) {
            throw ValidationException::withMessages(['sale' => "Masih ada pengajuan {$other->number} untuk transaksi ini yang belum selesai."]);
        }

        $refunded = $this->refundedQty($sale);
        $byId = $sale->lines->keyBy('id');
        $lines = [];
        $amount = 0;
        foreach ($input['lines'] as $i => $row) {
            /** @var SaleLine|null $line */
            $line = $byId->get($row['sale_line_id']);
            if (! $line) {
                throw ValidationException::withMessages(["lines.{$i}.sale_line_id" => 'Menu ini tidak ada di transaksi.']);
            }
            $available = $line->qty - ($refunded[$line->id] ?? 0);
            $qty = (int) $row['qty'];
            if ($qty > $available) {
                throw ValidationException::withMessages(["lines.{$i}.qty" => $available === 0
                    ? "{$line->item->name} sudah dibatalkan seluruhnya."
                    : "{$line->item->name} paling banyak {$available} porsi."]);
            }
            $lineAmount = $qty * $line->unit_price;
            $amount += $lineAmount;
            $lines[] = [
                'sale_line_id' => $line->id,
                'item_id' => $line->item_id,
                'item_name' => $line->item->name,
                'qty' => $qty,
                'unit_price' => $line->unit_price,
                'amount' => $lineAmount,
                'condition' => $row['condition'],
                'hpp_total' => $line->unit_hpp === null ? null : Decimal::round(bcmul((string) $line->unit_hpp, (string) $qty, Decimal::WORK)),
            ];
        }

        $remainingAll = $sale->lines->every(fn (SaleLine $l) => ($refunded[$l->id] ?? 0) === 0);
        $coversAll = $remainingAll && $sale->lines->every(function (SaleLine $l) use ($lines) {
            $row = collect($lines)->firstWhere('sale_line_id', $l->id);

            return $row !== null && $row['qty'] === $l->qty;
        });

        return [
            'sale_id' => $sale->id,
            'sale_number' => $sale->number,
            'kind' => $coversAll ? SaleRefund::KIND_VOID : SaleRefund::KIND_REFUND,
            'reason_code' => $input['reason_code'],
            'amount' => $amount,
            'lines' => $lines,
            'payments' => $this->allocate($sale, $amount),
        ];
    }

    public function apply(ApprovalRequest $request, User $approver): void
    {
        /** @var SaleRefund $refund */
        $refund = SaleRefund::query()->with(['sale.location', 'shift'])->lockForUpdate()->findOrFail($request->subject_id);
        if ($refund->applied_at) {
            return;
        }
        if ($refund->shift->status !== CashierShift::STATUS_OPEN) {
            throw ValidationException::withMessages(['shift' => "Shift {$refund->shift->number} sudah tidak berjalan, sehingga uang tidak bisa dikembalikan dari lacinya."]);
        }
        $payload = $request->payload;
        // Periksa ulang terhadap keadaan sekarang (mis. pembatalan lain yang baru berlaku).
        $refunded = $this->refundedQty($refund->sale);
        $source = ['type' => 'sale_refund', 'id' => $refund->id, 'number' => $refund->number];
        $creator = $refund->requester;

        foreach ($payload['lines'] as $row) {
            /** @var SaleLine $line */
            $line = SaleLine::query()->with('components.item')->findOrFail($row['sale_line_id']);
            if ($row['qty'] > $line->qty - ($refunded[$line->id] ?? 0)) {
                throw ValidationException::withMessages(['lines' => "Jumlah {$row['item_name']} yang dibatalkan melebihi sisa transaksi."]);
            }
            SaleRefundLine::query()->create([
                'sale_refund_id' => $refund->id,
                'sale_line_id' => $line->id,
                'qty' => $row['qty'],
                'amount' => $row['amount'],
                'condition' => $row['condition'],
                'hpp_total' => $row['hpp_total'],
            ]);
            if ($row['condition'] === SaleRefund::CONDITION_NOT_MADE) {
                foreach ($line->components as $c) {
                    $back = Decimal::round(bcdiv(bcmul((string) $c->qty, (string) $row['qty'], Decimal::WORK), (string) $line->qty, Decimal::WORK));
                    if (Decimal::isZero($back)) {
                        continue;
                    }
                    $this->ledger->receive($c->item, $refund->sale->location, $back, $c->unit_cost === null ? null : (string) $c->unit_cost,
                        StockMovement::SALE_REVERSAL, $source, $creator, ['note' => "Pembatalan {$refund->sale->number}"]);
                }
            }
        }
        foreach ($payload['payments'] as $p) {
            SaleRefundPayment::query()->create([
                'sale_refund_id' => $refund->id,
                'payment_method_id' => $p['payment_method_id'],
                'is_cash' => $p['is_cash'],
                'amount' => $p['amount'],
            ]);
        }
        $refund->update([
            'kind' => $payload['kind'],
            'amount' => $payload['amount'],
            'reason_code' => $payload['reason_code'],
            'applied_at' => now(),
        ]);

        $this->audit->record('SALE_REFUND_APPLIED', $refund, [
            'transaksi' => $refund->sale->number,
            'jenis' => $payload['kind'],
            'jumlah' => $payload['amount'],
            'baris' => array_map(fn ($l) => ['menu' => $l['item_name'], 'porsi' => $l['qty'], 'kondisi' => $l['condition']], $payload['lines']),
            'pengajuan' => $request->number,
        ], Role::OWNER, $request->decision_note, $request->delegation_id ? [AuditLogger::FLAG_DELEGATED] : [],
            locationId: $refund->location_id, user: $approver, subjectCode: $refund->number);
    }

    /**
     * Porsi yang sudah dibatalkan per baris (hanya pembatalan yang sudah berlaku).
     *
     * @return array<int, int>
     */
    public function refundedQty(Sale $sale): array
    {
        return SaleRefundLine::query()
            ->whereHas('refund', fn ($q) => $q->where('sale_id', $sale->id)->whereNotNull('applied_at'))
            ->selectRaw('sale_line_id, SUM(qty) AS qty')->groupBy('sale_line_id')
            ->pluck('qty', 'sale_line_id')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * Bagi uang yang dikembalikan ke metode bayar asal: tunai lebih dulu, lalu metode lain
     * sesuai urutan, tidak melebihi sisa yang belum dikembalikan per metode.
     *
     * @return list<array{payment_method_id: int, method_name: string, is_cash: bool, amount: int}>
     */
    private function allocate(Sale $sale, int $amount): array
    {
        $returned = SaleRefundPayment::query()
            ->whereHas('refund', fn ($q) => $q->where('sale_id', $sale->id)->whereNotNull('applied_at'))
            ->selectRaw('payment_method_id, SUM(amount) AS amount')->groupBy('payment_method_id')
            ->pluck('amount', 'payment_method_id')->map(fn ($v) => (int) $v)->all();

        $payments = $sale->payments->sortBy(fn ($p) => [$p->is_cash ? 0 : 1, $p->position]);
        $left = $amount;
        $out = [];
        foreach ($payments as $p) {
            if ($left === 0) {
                break;
            }
            $room = $p->amount - ($returned[$p->payment_method_id] ?? 0);
            $take = min($room, $left);
            if ($take <= 0) {
                continue;
            }
            $out[] = ['payment_method_id' => $p->payment_method_id, 'method_name' => $p->method->name, 'is_cash' => $p->is_cash, 'amount' => $take];
            $left -= $take;
        }
        if ($left > 0) {
            throw ValidationException::withMessages(['lines' => 'Nilai pembatalan melebihi sisa pembayaran transaksi.']);
        }

        return $out;
    }

    private function ownOpenShift(Sale $sale, User $user): CashierShift
    {
        $shift = CashierShift::query()->where('location_id', $sale->location_id)->where('cashier_id', $user->id)
            ->where('status', CashierShift::STATUS_OPEN)->first();
        if (! $shift) {
            throw ValidationException::withMessages(['shift' => 'Pembatalan diajukan dari shift Anda yang sedang berjalan di outlet ini. Buka shift dulu.']);
        }

        return $shift;
    }
}
