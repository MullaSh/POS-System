<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\SupplierInvoice;
use App\Modules\Purchasing\Models\SupplierPayment;
use App\Support\DocumentNumber;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tagihan dan hutang supplier (KP-11, AB-16; keputusan Owner 6 Nov 2026):
 * - Finance mencatat tagihan per penerimaan, sebesar barang yang diterima baik (bukan yang dipesan).
 *   Bila jumlah tagihan berbeda dari nilai penerimaan (harga PO), alasan wajib; nilai stok tidak
 *   diubah mundur, selisihnya tampil di daftar pengawasan Owner (penanda PRICE_DIFFERENCE).
 * - Jatuh tempo = tanggal tagihan + termin supplier. Pembayaran boleh sebagian.
 * - Ongkos kirim dicatat sebagai biaya operasional, bukan bagian tagihan barang (KOD-05).
 */
class PayableService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array<string, mixed> */
    public static function invoiceRules(): array
    {
        return [
            'invoice_no' => ['required', 'string', 'max:60'],
            'invoice_date' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', 'integer', 'min:1', 'max:10000000000'],
            'difference_note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, mixed> */
    public static function paymentRules(): array
    {
        return [
            'amount' => ['required', 'integer', 'min:1', 'max:10000000000'],
            'paid_date' => ['required', 'date_format:Y-m-d'],
            'method' => ['required', 'in:TRANSFER,CASH'],
            'reference' => ['nullable', 'string', 'max:60'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @param  array<string, mixed>  $data */
    public function recordInvoice(GoodsReceipt $receipt, array $data, User $user): SupplierInvoice
    {
        $this->ensureFinance($user);

        return DB::transaction(function () use ($receipt, $data, $user) {
            $receipt = GoodsReceipt::query()->with('order.supplier')->lockForUpdate()->findOrFail($receipt->id);
            if ($receipt->invoice()->exists()) {
                throw ValidationException::withMessages(['goods_receipt_id' => 'Tagihan untuk penerimaan ini sudah dicatat.']);
            }
            if ($receipt->accepted_value === 0) {
                throw ValidationException::withMessages(['goods_receipt_id' => 'Semua barang di penerimaan ini ditolak; tidak ada yang ditagih.']);
            }
            $amount = (int) $data['amount'];
            $note = trim((string) ($data['difference_note'] ?? '')) ?: null;
            if ($amount !== $receipt->accepted_value && $note === null) {
                throw ValidationException::withMessages(['difference_note' => 'Jumlah tagihan berbeda dari nilai barang yang diterima ('.self::rupiah($receipt->accepted_value).'). Tuliskan alasannya.']);
            }
            $supplier = $receipt->order->supplier;
            $invoiceDate = Carbon::parse($data['invoice_date']);
            $invoice = SupplierInvoice::query()->create([
                'number' => DocumentNumber::next('TGH'),
                'goods_receipt_id' => $receipt->id,
                'supplier_id' => $supplier->id,
                'invoice_no' => $data['invoice_no'],
                'invoice_date' => $invoiceDate->toDateString(),
                'due_date' => $invoiceDate->copy()->addDays((int) $supplier->payment_term_days)->toDateString(),
                'expected_amount' => $receipt->accepted_value,
                'amount' => $amount,
                'difference_note' => $note,
                'recorded_by' => $user->id,
            ]);
            $this->audit->record('SUPPLIER_INVOICE_RECORDED', $invoice, [
                'supplier' => $supplier->code, 'penerimaan' => $receipt->number, 'nilai_diterima' => $receipt->accepted_value, 'tagihan' => $amount,
            ], Role::FINANCE, $note, $amount !== $receipt->accepted_value ? ['PRICE_DIFFERENCE'] : [], user: $user, subjectCode: $invoice->number);

            return $invoice;
        });
    }

    public function paid(SupplierInvoice $invoice): int
    {
        return (int) SupplierPayment::query()->where('supplier_invoice_id', $invoice->id)->sum('amount');
    }

    /** @param  array<string, mixed>  $data */
    public function pay(SupplierInvoice $invoice, array $data, User $user): SupplierPayment
    {
        $this->ensureFinance($user);

        return DB::transaction(function () use ($invoice, $data, $user) {
            $invoice = SupplierInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $outstanding = $invoice->amount - $this->paid($invoice);
            if ($outstanding <= 0) {
                throw ValidationException::withMessages(['amount' => 'Tagihan ini sudah lunas.']);
            }
            if ((int) $data['amount'] > $outstanding) {
                throw ValidationException::withMessages(['amount' => 'Melebihi sisa tagihan ('.self::rupiah($outstanding).').']);
            }
            if ($data['paid_date'] > now()->setTimezone(config('app.timezone'))->toDateString()) {
                throw ValidationException::withMessages(['paid_date' => 'Tanggal bayar tidak boleh sesudah hari ini.']);
            }
            $payment = SupplierPayment::query()->create([
                'number' => DocumentNumber::next('BYR'),
                'supplier_invoice_id' => $invoice->id,
                'amount' => (int) $data['amount'],
                'paid_date' => $data['paid_date'],
                'method' => $data['method'],
                'reference' => $data['reference'] ?? null,
                'note' => $data['note'] ?? null,
                'paid_by' => $user->id,
            ]);
            $this->audit->record('SUPPLIER_PAID', $payment, [
                'tagihan' => $invoice->number, 'jumlah' => $payment->amount, 'sisa' => $outstanding - $payment->amount, 'metode' => $payment->method,
            ], Role::FINANCE, $data['note'] ?? null, user: $user, subjectCode: $payment->number);

            return $payment;
        });
    }

    private function ensureFinance(User $user): void
    {
        if (! $user->hasActiveRole(Role::FINANCE)) {
            throw new AuthorizationException('Tagihan dan pembayaran supplier dicatat Finance.');
        }
    }

    public static function rupiah(int $v): string
    {
        return 'Rp'.number_format($v, 0, ',', '.');
    }
}
