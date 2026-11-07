<?php

namespace App\Modules\Cash\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Cash\Models\CashHandover;
use App\Modules\Cash\Models\DiscrepancyResolution;
use App\Modules\Cash\Models\Expense;
use App\Modules\Cash\Models\NoncashReconciliation;
use App\Modules\Catalog\Models\PaymentMethod;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Sales\Models\CashierShift;
use App\Modules\Sales\Models\SalePayment;
use App\Modules\Sales\Models\SaleRefundPayment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pencocokan non-tunai dan penyelesaian selisih:
 * - KP-15, OD-16 (keputusan Owner 6 Nov 2026): Finance mencocokkan harian per metode bayar
 *   non-tunai: total di sistem (pembayaran dikurangi pengembalian) dibanding mutasi rekening pusat.
 *   Selisih wajib dijelaskan.
 * - KP-12: setiap selisih (shift, serah terima, biaya ditolak, non-tunai) diputuskan Finance dan
 *   dilihat Owner. Keputusan dicatat sekali dan tidak diubah.
 */
class ReconciliationService
{
    public const SUBJECTS = [
        'cashier_shift' => CashierShift::class,
        'cash_handover' => CashHandover::class,
        'expense' => Expense::class,
        'noncash_reconciliation' => NoncashReconciliation::class,
    ];

    /** Kategori keputusan selisih (irisan 9): menentukan dampak ke laba bersih. */
    public const CATEGORIES = ['STAFF', 'BUSINESS', 'RECORDING_ERROR'];

    public function __construct(private readonly AuditLogger $audit) {}

    /** Total non-tunai di sistem untuk satu tanggal dan metode, semua outlet. */
    public function systemAmount(string $date, int $methodId): int
    {
        $paid = (int) SalePayment::query()->where('payment_method_id', $methodId)->where('is_cash', false)
            ->whereHas('sale', fn ($q) => $q->where('business_date', $date))->sum('amount');
        $tz = config('app.timezone');
        $refunded = (int) SaleRefundPayment::query()->where('payment_method_id', $methodId)->where('is_cash', false)
            ->whereHas('refund', fn ($q) => $q->whereNotNull('applied_at')
                ->whereRaw("(applied_at AT TIME ZONE ?)::date = ?", [$tz, $date]))->sum('amount');

        return $paid - $refunded;
    }

    /** @param  array{business_date: string, payment_method_id: int, bank_amount: int, note?: string|null}  $data */
    public function reconcile(array $data, User $user): NoncashReconciliation
    {
        if (! $user->hasActiveRole(Role::FINANCE)) {
            throw new AuthorizationException('Pencocokan non-tunai dilakukan Finance.');
        }
        $method = PaymentMethod::query()->findOrFail($data['payment_method_id']);
        if ($method->is_cash) {
            throw ValidationException::withMessages(['payment_method_id' => 'Tunai tidak dicocokkan dengan mutasi rekening; tunai lewat serah terima kas.']);
        }
        $today = now()->setTimezone(config('app.timezone'))->toDateString();
        if ($data['business_date'] > $today) {
            throw ValidationException::withMessages(['business_date' => 'Tanggal tidak boleh sesudah hari ini.']);
        }
        if (NoncashReconciliation::query()->where(['business_date' => $data['business_date'], 'payment_method_id' => $method->id])->exists()) {
            throw ValidationException::withMessages(['business_date' => "{$method->name} tanggal ini sudah dicocokkan."]);
        }
        $system = $this->systemAmount($data['business_date'], $method->id);
        $bank = (int) $data['bank_amount'];
        $note = trim((string) ($data['note'] ?? '')) ?: null;
        if ($bank !== $system && $note === null) {
            throw ValidationException::withMessages(['note' => 'Mutasi berbeda dari total di sistem ('.HandoverService::rupiah($system).'). Tuliskan penjelasannya.']);
        }

        try {
            return DB::transaction(function () use ($data, $method, $system, $bank, $note, $user) {
                $rec = NoncashReconciliation::query()->create([
                    'business_date' => $data['business_date'],
                    'payment_method_id' => $method->id,
                    'system_amount' => $system,
                    'bank_amount' => $bank,
                    'difference' => $bank - $system,
                    'note' => $note,
                    'reconciled_by' => $user->id,
                ]);
                $this->audit->record('NONCASH_RECONCILED', $rec, [
                    'tanggal' => $data['business_date'], 'metode' => $method->code, 'sistem' => $system, 'mutasi' => $bank, 'selisih' => $bank - $system,
                ], Role::FINANCE, $note, $bank !== $system ? ['CASH_DIFFERENCE'] : [], user: $user, subjectCode: "{$method->code} {$data['business_date']}");

                return $rec;
            });
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'noncash_reconciliations_business_date_payment_method_id_unique')) {
                throw ValidationException::withMessages(['business_date' => "{$method->name} tanggal ini sudah dicocokkan."]);
            }
            throw $e;
        }
    }

    public function resolve(string $subjectType, int $subjectId, string $decision, User $user, string $category): DiscrepancyResolution
    {
        if (! $user->hasActiveRole(Role::FINANCE)) {
            throw new AuthorizationException('Penyelesaian selisih diputuskan Finance.');
        }
        $class = self::SUBJECTS[$subjectType] ?? throw ValidationException::withMessages(['subject_type' => 'Jenis selisih tidak dikenal.']);
        $subject = $class::query()->findOrFail($subjectId);
        if (! $this->hasDifference($subjectType, $subject)) {
            throw ValidationException::withMessages(['subject_id' => 'Catatan ini tidak berselisih.']);
        }
        if (! in_array($category, self::CATEGORIES, true)) {
            throw ValidationException::withMessages(['category' => 'Pilih jenis penyelesaian selisih.']);
        }
        if (DiscrepancyResolution::query()->where(['subject_type' => $subjectType, 'subject_id' => $subjectId])->exists()) {
            throw ValidationException::withMessages(['subject_id' => 'Selisih ini sudah diputuskan.']);
        }

        return DB::transaction(function () use ($subjectType, $subject, $decision, $user, $category) {
            $res = DiscrepancyResolution::query()->create([
                'subject_type' => $subjectType, 'subject_id' => $subject->getKey(), 'decision' => $decision, 'decided_by' => $user->id, 'category' => $category,
            ]);
            $this->audit->record('DISCREPANCY_RESOLVED', $subject, ['jenis' => $subjectType, 'jumlah' => $this->amount($subjectType, $subject), 'kategori' => $category],
                Role::FINANCE, $decision, user: $user, subjectCode: $subject->getAttribute('number'));

            return $res;
        });
    }

    /** Semua selisih kas dengan keputusannya, terbaru dulu (KP-12). @return list<array<string, mixed>> */
    public function discrepancies(): array
    {
        $resolutions = DiscrepancyResolution::query()->with('decider:id,name')->get()->keyBy(fn ($r) => "{$r->subject_type}|{$r->subject_id}");
        $res = fn (string $type, int $id) => ($r = $resolutions->get("{$type}|{$id}"))
            ? ['decision' => $r->decision, 'category' => $r->category, 'by' => $r->decider?->name, 'at' => $r->created_at] : null;
        $rows = [];

        foreach (CashierShift::query()->with(['location', 'cashier:id,name'])->where('status', CashierShift::STATUS_CLOSED)->where('cash_difference', '<>', 0)->orderByDesc('closed_at')->limit(300)->get() as $s) {
            $rows[] = ['subject_type' => 'cashier_shift', 'subject_id' => $s->id, 'number' => $s->number, 'kind' => 'Tutup shift',
                'location' => $s->location?->name, 'person' => $s->cashier?->name, 'amount' => (int) $s->cash_difference,
                'explanation' => $s->close_note, 'at' => $s->closed_at, 'link' => "/shift/{$s->id}", 'resolution' => $res('cashier_shift', $s->id)];
        }
        foreach (CashHandover::query()->with(['fromLocation', 'receiver:id,name'])->where('status', CashHandover::STATUS_RECEIVED)->whereColumn('received_amount', '<>', 'expected_amount')->orderByDesc('received_at')->limit(300)->get() as $h) {
            $rows[] = ['subject_type' => 'cash_handover', 'subject_id' => $h->id, 'number' => $h->number,
                'kind' => $h->stage === CashHandover::STAGE_OUTLET ? 'Serah terima outlet → gudang' : 'Serah terima gudang → Finance',
                'location' => $h->fromLocation?->name, 'person' => $h->receiver?->name, 'amount' => (int) $h->difference(),
                'explanation' => trim(($h->sender_note ? "Pengirim: {$h->sender_note}. " : '').($h->evidence_note ? "Penerima: {$h->evidence_note}" : '')),
                'at' => $h->received_at, 'link' => "/kas/serah-terima/{$h->id}", 'resolution' => $res('cash_handover', $h->id)];
        }
        foreach (Expense::query()->with(['location', 'recorder:id,name'])->where('status', Expense::STATUS_REJECTED)->orderByDesc('decided_at')->limit(300)->get() as $e) {
            $rows[] = ['subject_type' => 'expense', 'subject_id' => $e->id, 'number' => $e->number, 'kind' => 'Biaya Petty Cash ditolak',
                'location' => $e->location?->name, 'person' => $e->recorder?->name, 'amount' => -$e->amount,
                'explanation' => "{$e->description}. Alasan ditolak: {$e->decision_note}", 'at' => $e->decided_at, 'link' => '/kas/biaya', 'resolution' => $res('expense', $e->id)];
        }
        foreach (NoncashReconciliation::query()->with('method')->where('difference', '<>', 0)->orderByDesc('business_date')->limit(300)->get() as $n) {
            $rows[] = ['subject_type' => 'noncash_reconciliation', 'subject_id' => $n->id, 'number' => ($n->method?->name ?? '').' '.$n->business_date->format('d/m/Y'),
                'kind' => 'Pencocokan non-tunai', 'location' => 'Rekening pusat', 'person' => null, 'amount' => (int) $n->difference,
                'explanation' => $n->note, 'at' => $n->created_at, 'link' => '/kas/rekonsiliasi', 'resolution' => $res('noncash_reconciliation', $n->id)];
        }
        usort($rows, fn ($a, $b) => Carbon::parse($b['at'])->timestamp <=> Carbon::parse($a['at'])->timestamp);

        return $rows;
    }

    public function hasDifference(string $type, mixed $subject): bool
    {
        return $this->amount($type, $subject) !== 0;
    }

    /** Nilai selisih: positif = lebih, negatif = kurang. */
    public function amount(string $type, mixed $subject): int
    {
        return match ($type) {
            'cashier_shift' => $subject->status === CashierShift::STATUS_CLOSED ? (int) $subject->cash_difference : 0,
            'cash_handover' => $subject->status === CashHandover::STATUS_RECEIVED ? (int) $subject->difference() : 0,
            'expense' => $subject->status === Expense::STATUS_REJECTED ? -$subject->amount : 0,
            'noncash_reconciliation' => (int) $subject->difference,
            default => 0,
        };
    }
}
