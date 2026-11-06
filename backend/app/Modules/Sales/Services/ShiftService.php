<?php

namespace App\Modules\Sales\Services;

use App\Modules\Approval\Models\ApprovalRequest;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Location\Models\Location;
use App\Modules\Sales\Models\CashierShift;
use App\Modules\Sales\Models\SalePayment;
use App\Modules\Sales\Models\SaleRefund;
use App\Modules\Sales\Models\SaleRefundPayment;
use App\Support\DocumentNumber;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Shift kasir (keputusan Owner 6 Nov 2026):
 * - OD-11: jumlah shift per hari tidak dipatok; satu laci per outlet, satu shift berjalan per
 *   outlet. Ganti kasir = tutup shift lalu buka shift baru. Hanya pemilik shift yang berjualan.
 * - KOD-01: Cash Float (C14, Rp50.000) tetap di laci. Kasir pembuka mengonfirmasi jumlahnya.
 * - OD-12: tutup shift dengan hitung buta. Kasir tidak melihat angka sistem sebelum mengirim
 *   hasil hitung; hasil hitung dikunci begitu dikirim; bila berselisih, alasan wajib (KP-12).
 * - Sisa tunai di atas Cash Float = Sales Cash yang siap diserahkan (serah terima di irisan 6).
 */
class ShiftService
{
    public function __construct(
        private readonly SalesAccess $access,
        private readonly AuditLogger $audit,
    ) {}

    public static function floatAmount(): int
    {
        return (int) config('pos.cash_float');
    }

    /** Cash Float yang seharusnya ada di laci: yang ditinggalkan shift terakhir, atau nilai baku. */
    public function expectedFloat(Location $location): int
    {
        $last = CashierShift::query()->where('location_id', $location->id)
            ->where('status', CashierShift::STATUS_CLOSED)->orderByDesc('closed_at')->orderByDesc('id')->first();

        return $last?->float_kept ?? self::floatAmount();
    }

    public function running(Location $location): ?CashierShift
    {
        return CashierShift::query()->where('location_id', $location->id)
            ->whereIn('status', CashierShift::RUNNING)->with('cashier:id,name,username')->first();
    }

    /** @param  array{location_id: int, float_counted: int, note?: string|null}  $data */
    public function open(array $data, User $user): CashierShift
    {
        $location = Location::query()->findOrFail($data['location_id']);
        $this->access->ensureCashierAt($user, $location);
        if ($location->status !== Location::STATUS_ACTIVE) {
            throw ValidationException::withMessages(['location_id' => "{$location->name} sedang tidak aktif."]);
        }

        return DB::transaction(function () use ($data, $user, $location) {
            Location::query()->whereKey($location->id)->lockForUpdate()->first();
            $running = $this->running($location);
            if ($running) {
                throw ValidationException::withMessages([
                    'location_id' => $running->cashier_id === $user->id
                        ? 'Shift Anda di outlet ini masih berjalan.'
                        : "Shift {$running->cashier->name} di {$location->name} masih berjalan. Minta {$running->cashier->name} menutup shift dulu.",
                ]);
            }
            $mine = CashierShift::query()->where('cashier_id', $user->id)->whereIn('status', CashierShift::RUNNING)->with('location')->first();
            if ($mine) {
                throw ValidationException::withMessages(['location_id' => "Shift Anda di {$mine->location->name} masih berjalan. Tutup shift itu dulu."]);
            }

            $expected = $this->expectedFloat($location);
            $counted = (int) $data['float_counted'];
            $note = trim((string) ($data['note'] ?? '')) ?: null;
            if ($counted !== $expected && $note === null) {
                throw ValidationException::withMessages([
                    'note' => 'Jumlah Cash Float berbeda dari yang seharusnya ('.self::rupiah($expected).'). Tuliskan penjelasannya.',
                ]);
            }

            $shift = CashierShift::query()->create([
                'number' => DocumentNumber::next('SHF'),
                'location_id' => $location->id,
                'cashier_id' => $user->id,
                'status' => CashierShift::STATUS_OPEN,
                'opened_at' => now(),
                'float_expected' => $expected,
                'float_counted' => $counted,
                'open_note' => $note,
            ]);
            $flags = $counted !== $expected ? ['FLOAT_DIFFERENCE'] : [];
            $this->audit->record('SHIFT_OPENED', $shift, ['cash_float' => ['seharusnya' => $expected, 'dihitung' => $counted]],
                Role::CASHIER, $note, $flags, locationId: $location->id, user: $user, subjectCode: $shift->number);

            return $shift;
        });
    }

    /**
     * Kirim hasil hitung uang di laci (hitung buta). Bila cocok, shift langsung ditutup;
     * bila berselisih, shift menunggu alasan (status COUNTED) dan tidak bisa dipakai berjualan.
     */
    public function count(CashierShift $shift, int $countedCash, User $user): CashierShift
    {
        return DB::transaction(function () use ($shift, $countedCash, $user) {
            $shift = $this->lockOwn($shift, $user);
            if ($shift->status !== CashierShift::STATUS_OPEN) {
                throw ValidationException::withMessages(['status' => 'Hasil hitung shift ini sudah dikirim.']);
            }
            $pending = SaleRefund::query()->where('shift_id', $shift->id)
                ->whereHas('approval', fn ($q) => $q->whereIn('status', ApprovalRequest::OPEN_STATUSES))->pluck('number');
            if ($pending->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'counted_cash' => 'Masih ada pengajuan pembatalan yang belum selesai ('.$pending->implode(', ').'). Tunggu keputusannya atau batalkan pengajuan itu dulu.',
                ]);
            }

            $expected = $this->expectedCash($shift);
            $difference = $countedCash - $expected;
            $shift->fill([
                'counted_cash' => $countedCash,
                'expected_cash' => $expected,
                'cash_difference' => $difference,
                'counted_at' => now(),
                'status' => CashierShift::STATUS_COUNTED,
            ]);
            $shift->save();
            $this->audit->record('SHIFT_COUNTED', $shift, ['dihitung' => $countedCash, 'seharusnya' => $expected, 'selisih' => $difference],
                Role::CASHIER, null, locationId: $shift->location_id, user: $user, subjectCode: $shift->number);

            if ($difference === 0) {
                $this->finish($shift, null, $user);
            }

            return $shift;
        });
    }

    /** Tutup shift yang hasil hitungnya berselisih, dengan alasan (KP-12). */
    public function close(CashierShift $shift, string $reason, User $user): CashierShift
    {
        return DB::transaction(function () use ($shift, $reason, $user) {
            $shift = $this->lockOwn($shift, $user);
            if ($shift->status !== CashierShift::STATUS_COUNTED) {
                throw ValidationException::withMessages(['status' => $shift->status === CashierShift::STATUS_OPEN
                    ? 'Kirim hasil hitung uang di laci dulu.'
                    : 'Shift ini sudah ditutup.']);
            }
            $this->finish($shift, $reason, $user);

            return $shift;
        });
    }

    /** Tunai yang seharusnya ada di laci: Cash Float awal + tunai penjualan − tunai yang dikembalikan. */
    public function expectedCash(CashierShift $shift): int
    {
        return $shift->float_counted + $this->cashIn($shift) - $this->cashOut($shift);
    }

    public function cashIn(CashierShift $shift): int
    {
        return (int) SalePayment::query()->where('is_cash', true)
            ->whereHas('sale', fn ($q) => $q->where('shift_id', $shift->id))
            ->sum('amount');
    }

    public function cashOut(CashierShift $shift): int
    {
        return (int) SaleRefundPayment::query()->where('is_cash', true)
            ->whereHas('refund', fn ($q) => $q->where('shift_id', $shift->id)->whereNotNull('applied_at'))
            ->sum('amount');
    }

    private function finish(CashierShift $shift, ?string $reason, User $user): void
    {
        $kept = min(self::floatAmount(), (int) $shift->counted_cash);
        $shift->fill([
            'status' => CashierShift::STATUS_CLOSED,
            'close_note' => $reason,
            'float_kept' => $kept,
            'sales_cash' => (int) $shift->counted_cash - $kept,
            'closed_at' => now(),
        ]);
        $shift->save();
        $flags = $shift->cash_difference !== 0 ? ['CASH_DIFFERENCE'] : [];
        $this->audit->record('SHIFT_CLOSED', $shift, [
            'selisih' => $shift->cash_difference, 'cash_float_di_laci' => $kept, 'sales_cash' => $shift->sales_cash,
        ], Role::CASHIER, $reason, $flags, locationId: $shift->location_id, user: $user, subjectCode: $shift->number);
    }

    private function lockOwn(CashierShift $shift, User $user): CashierShift
    {
        $shift = CashierShift::query()->whereKey($shift->id)->lockForUpdate()->firstOrFail();
        if ($shift->cashier_id !== $user->id) {
            throw new AuthorizationException('Hanya kasir pemilik shift yang dapat menutup shift ini.');
        }

        return $shift;
    }

    public static function rupiah(int $value): string
    {
        return 'Rp'.number_format($value, 0, ',', '.');
    }
}
