<?php

namespace App\Modules\Cash\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Cash\Models\CashHandover;
use App\Modules\Cash\Models\CashHandoverItem;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Location\Models\Location;
use App\Modules\Sales\Models\CashierShift;
use App\Support\DocumentNumber;
use App\Support\ControlBlocked;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Serah terima Sales Cash (C12; OD-17 keputusan Owner 6 Nov 2026: tetap lewat gudang):
 * - Tahap 1: kasir outlet menyerahkan Sales Cash dari shift yang sudah ditutup ke gudang.
 * - Tahap 2: gudang meneruskan uang yang sudah diterimanya ke Finance.
 * Setiap serah terima mencatat jumlah seharusnya, dilaporkan pengirim, dikonfirmasi penerima,
 * dan selisihnya (PV-04). Pengirim dan penerima harus orang berbeda (2F-KW03). Jumlah yang
 * sudah dikonfirmasi kedua pihak tidak diubah (3C-Q1). Bukti wajib bila ada selisih (KOD-02).
 */
class HandoverService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** Shift yang sudah ditutup dan Sales Cash-nya belum diserahkan. @return Collection<int, CashierShift> */
    public function pendingShifts(Location $outlet): Collection
    {
        return CashierShift::query()->where('location_id', $outlet->id)->where('status', CashierShift::STATUS_CLOSED)
            ->whereNotIn('id', CashHandoverItem::query()->whereNotNull('shift_id')->select('shift_id'))
            ->with('cashier:id,name,username')->orderBy('closed_at')->get();
    }

    /** Serah terima outlet yang sudah diterima gudang dan belum diteruskan ke Finance. @return Collection<int, CashHandover> */
    public function pendingAtWarehouse(): Collection
    {
        return CashHandover::query()->where('stage', CashHandover::STAGE_OUTLET)->where('status', CashHandover::STATUS_RECEIVED)
            ->whereNotIn('id', CashHandoverItem::query()->whereNotNull('source_handover_id')->select('source_handover_id'))
            ->with('fromLocation')->orderBy('received_at')->get();
    }

    /** @param  array{location_id: int, shift_ids: list<int>, reported_amount: int, note?: string|null}  $data */
    public function sendFromOutlet(array $data, User $user): CashHandover
    {
        $outlet = Location::query()->findOrFail($data['location_id']);
        if ($outlet->type !== Location::TYPE_OUTLET || ! $user->hasRoleAt(Role::CASHIER, $outlet->id)) {
            throw new AuthorizationException("Serah terima dari {$outlet->name} dikirim oleh kasir outlet itu.");
        }
        $warehouse = Location::query()->where('type', Location::TYPE_WAREHOUSE)->firstOrFail();

        return DB::transaction(function () use ($data, $user, $outlet, $warehouse) {
            Location::query()->whereKey($outlet->id)->lockForUpdate()->first();
            $pending = $this->pendingShifts($outlet)->keyBy('id');
            $shifts = collect($data['shift_ids'])->map(function ($id, $i) use ($pending) {
                return $pending->get($id) ?? throw ValidationException::withMessages([
                    "shift_ids.{$i}" => 'Shift ini belum ditutup, bukan dari outlet ini, atau sudah diserahkan.',
                ]);
            });
            $expected = (int) $shifts->sum('sales_cash');
            $reported = (int) $data['reported_amount'];
            $note = trim((string) ($data['note'] ?? '')) ?: null;
            if ($reported !== $expected && $note === null) {
                throw ValidationException::withMessages(['note' => 'Jumlah yang diserahkan berbeda dari Sales Cash shift ('.self::rupiah($expected).'). Tuliskan penjelasannya.']);
            }

            $handover = CashHandover::query()->create([
                'number' => DocumentNumber::next('STK'),
                'stage' => CashHandover::STAGE_OUTLET,
                'from_location_id' => $outlet->id,
                'to_location_id' => $warehouse->id,
                'status' => CashHandover::STATUS_SENT,
                'expected_amount' => $expected,
                'reported_amount' => $reported,
                'sender_id' => $user->id,
                'sender_role_id' => Role::idFor(Role::CASHIER),
                'sender_note' => $note,
                'sent_at' => now(),
            ]);
            foreach ($shifts as $shift) {
                CashHandoverItem::query()->create(['cash_handover_id' => $handover->id, 'shift_id' => $shift->id, 'amount' => (int) $shift->sales_cash]);
            }
            $this->audit->record('CASH_HANDOVER_SENT', $handover, [
                'tahap' => $handover->stage, 'seharusnya' => $expected, 'dilaporkan' => $reported, 'shift' => $shifts->pluck('number')->all(),
            ], Role::CASHIER, $note, $reported !== $expected ? ['CASH_DIFFERENCE'] : [], locationId: $outlet->id, user: $user, subjectCode: $handover->number);

            return $handover;
        });
    }

    /** @param  array{handover_ids: list<int>, reported_amount: int, note?: string|null}  $data */
    public function sendFromWarehouse(array $data, User $user): CashHandover
    {
        $warehouse = Location::query()->where('type', Location::TYPE_WAREHOUSE)->firstOrFail();
        if (! $user->hasRoleAt(Role::WAREHOUSE, $warehouse->id)) {
            throw new AuthorizationException('Kas di gudang diteruskan ke Finance oleh staf gudang.');
        }

        return DB::transaction(function () use ($data, $user, $warehouse) {
            Location::query()->whereKey($warehouse->id)->lockForUpdate()->first();
            $pending = $this->pendingAtWarehouse()->keyBy('id');
            $sources = collect($data['handover_ids'])->map(fn ($id, $i) => $pending->get($id) ?? throw ValidationException::withMessages([
                "handover_ids.{$i}" => 'Serah terima ini belum diterima gudang atau sudah diteruskan.',
            ]));
            $expected = (int) $sources->sum('received_amount');
            $reported = (int) $data['reported_amount'];
            $note = trim((string) ($data['note'] ?? '')) ?: null;
            if ($reported !== $expected && $note === null) {
                throw ValidationException::withMessages(['note' => 'Jumlah yang diserahkan berbeda dari kas yang diterima gudang ('.self::rupiah($expected).'). Tuliskan penjelasannya.']);
            }

            $handover = CashHandover::query()->create([
                'number' => DocumentNumber::next('STK'),
                'stage' => CashHandover::STAGE_WAREHOUSE,
                'from_location_id' => $warehouse->id,
                'to_location_id' => null,
                'status' => CashHandover::STATUS_SENT,
                'expected_amount' => $expected,
                'reported_amount' => $reported,
                'sender_id' => $user->id,
                'sender_role_id' => Role::idFor(Role::WAREHOUSE),
                'sender_note' => $note,
                'sent_at' => now(),
            ]);
            foreach ($sources as $source) {
                CashHandoverItem::query()->create(['cash_handover_id' => $handover->id, 'source_handover_id' => $source->id, 'amount' => (int) $source->received_amount]);
            }
            $this->audit->record('CASH_HANDOVER_SENT', $handover, [
                'tahap' => $handover->stage, 'seharusnya' => $expected, 'dilaporkan' => $reported, 'serah_terima' => $sources->pluck('number')->all(),
            ], Role::WAREHOUSE, $note, $reported !== $expected ? ['CASH_DIFFERENCE'] : [], locationId: $warehouse->id, user: $user, subjectCode: $handover->number);

            return $handover;
        });
    }

    /** @param  array{received_amount: int, note?: string|null, evidence_note?: string|null}  $data */
    public function receive(CashHandover $handover, array $data, User $user): CashHandover
    {
        $role = $handover->stage === CashHandover::STAGE_OUTLET ? Role::WAREHOUSE : Role::FINANCE;
        $allowed = $role === Role::WAREHOUSE ? $user->hasRoleAt(Role::WAREHOUSE, (int) $handover->to_location_id) : $user->hasActiveRole(Role::FINANCE);
        if (! $allowed) {
            throw new AuthorizationException($role === Role::WAREHOUSE ? 'Serah terima dari outlet dikonfirmasi staf gudang.' : 'Serah terima dari gudang dikonfirmasi Finance.');
        }

        return DB::transaction(function () use ($handover, $data, $user, $role) {
            $handover = CashHandover::query()->whereKey($handover->id)->lockForUpdate()->firstOrFail();
            if ($handover->status !== CashHandover::STATUS_SENT) {
                throw ValidationException::withMessages(['status' => 'Serah terima ini sudah dikonfirmasi.']);
            }
            if ($handover->sender_id === $user->id) {
                throw new ControlBlocked('Pengirim dan penerima kas harus orang yang berbeda.', ControlBlocked::SAME_SENDER_RECEIVER, $handover);
            }
            $received = (int) $data['received_amount'];
            $differs = $received !== $handover->expected_amount || $received !== $handover->reported_amount;
            $evidence = trim((string) ($data['evidence_note'] ?? '')) ?: null;
            if ($differs && $evidence === null) {
                throw ValidationException::withMessages(['evidence_note' => 'Ada selisih. Tuliskan bukti dan penjelasannya (mis. dihitung bersama siapa, nomor foto).']);
            }
            $handover->update([
                'status' => CashHandover::STATUS_RECEIVED,
                'received_amount' => $received,
                'receiver_id' => $user->id,
                'receiver_role_id' => Role::idFor($role),
                'receiver_note' => trim((string) ($data['note'] ?? '')) ?: null,
                'evidence_note' => $evidence,
                'received_at' => now(),
            ]);
            $this->audit->record('CASH_HANDOVER_RECEIVED', $handover, [
                'seharusnya' => $handover->expected_amount, 'dilaporkan' => $handover->reported_amount, 'diterima' => $received,
                'selisih' => $received - $handover->expected_amount,
            ], $role, $evidence, $differs ? ['CASH_DIFFERENCE'] : [], locationId: $handover->to_location_id, user: $user, subjectCode: $handover->number);

            return $handover;
        });
    }

    public static function rupiah(int $value): string
    {
        return ($value < 0 ? '-' : '').'Rp'.number_format(abs($value), 0, ',', '.');
    }
}
