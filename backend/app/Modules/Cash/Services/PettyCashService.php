<?php

namespace App\Modules\Cash\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Cash\Models\Expense;
use App\Modules\Cash\Models\PettyCashMovement;
use App\Modules\Cash\Models\PettyCashRefill;
use App\Modules\Catalog\Models\ExpenseCategory;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Location\Models\Location;
use App\Support\DocumentNumber;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Petty Cash dan biaya operasional (C14, C15; KP-13, KP-14; keputusan Owner 6 Nov 2026):
 * - Saldo tetap per outlet, diatur Owner, dipegang kasir.
 * - Kasir mencatat biaya dengan bukti dan langsung membayarnya dari Petty Cash, tanpa persetujuan
 *   di depan (R4/K-11). Saldo Petty Cash tidak bisa minus; bila habis, kasir meminta isi ulang
 *   ke Finance dan tidak memakai Sales Cash atau Cash Float (F-3 s.d. F-5).
 * - Finance memeriksa setiap biaya (terbukti atau ditolak) dan mengisi ulang sebesar biaya yang
 *   terbukti (KP-14). Biaya yang ditolak menjadi selisih yang diputuskan Finance (KP-12).
 * - Biaya gudang dan pusat dicatat dan dibayar langsung oleh Finance (G-02).
 */
class PettyCashService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function balance(int $locationId): int
    {
        return (int) (PettyCashMovement::query()->where('location_id', $locationId)->orderByDesc('id')->value('balance_after') ?? 0);
    }

    public function setTarget(Location $outlet, int $amount, User $owner): Location
    {
        if ($outlet->type !== Location::TYPE_OUTLET) {
            throw ValidationException::withMessages(['location_id' => 'Petty Cash hanya untuk outlet.']);
        }
        $before = $outlet->petty_cash_amount;
        $outlet->update(['petty_cash_amount' => $amount]);
        $this->audit->record('PETTY_CASH_TARGET_SET', $outlet, ['petty_cash' => ['dari' => $before, 'menjadi' => $amount]], Role::OWNER,
            locationId: $outlet->id, user: $owner);

        return $outlet;
    }

    /** @return array<string, mixed> */
    public static function expenseRules(): array
    {
        return [
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'expense_category_id' => ['required', 'integer', 'exists:expense_categories,id'],
            'amount' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'description' => ['required', 'string', 'max:500'],
            'evidence_note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** Kasir mencatat biaya outlet (dibayar dari Petty Cash) atau Finance mencatat biaya yang dibayarnya. @param array<string, mixed> $data */
    public function recordExpense(array $data, User $user): Expense
    {
        $location = Location::query()->findOrFail($data['location_id']);
        $category = ExpenseCategory::query()->findOrFail($data['expense_category_id']);
        if (! $category->is_active) {
            throw ValidationException::withMessages(['expense_category_id' => "Kategori {$category->name} sedang nonaktif."]);
        }
        $fromPetty = $location->type === Location::TYPE_OUTLET && $user->hasRoleAt(Role::CASHIER, $location->id);
        if (! $fromPetty && ! $user->hasActiveRole(Role::FINANCE)) {
            throw new AuthorizationException('Biaya outlet dicatat kasir outlet itu; biaya gudang dan pusat dicatat Finance.');
        }
        $role = $fromPetty ? Role::CASHIER : Role::FINANCE;

        return DB::transaction(function () use ($data, $user, $location, $category, $fromPetty, $role) {
            Location::query()->whereKey($location->id)->lockForUpdate()->first();
            $amount = (int) $data['amount'];
            if ($fromPetty) {
                $balance = $this->balance($location->id);
                if ($amount > $balance) {
                    throw ValidationException::withMessages(['amount' => 'Petty Cash tidak cukup (sisa '.HandoverService::rupiah($balance).'). Minta isi ulang ke Finance; jangan memakai Sales Cash atau Cash Float.']);
                }
            }
            $expense = Expense::query()->create([
                'number' => DocumentNumber::next('BYA'),
                'location_id' => $location->id,
                'expense_category_id' => $category->id,
                'amount' => $amount,
                'description' => $data['description'],
                'evidence_note' => $data['evidence_note'] ?? null,
                'paid_from' => $fromPetty ? Expense::PAID_PETTY : Expense::PAID_FINANCE,
                'status' => $fromPetty ? Expense::STATUS_RECORDED : Expense::STATUS_VERIFIED,
                'recorded_by' => $user->id,
                'role_id' => Role::idFor($role),
                'business_date' => now()->setTimezone(config('app.timezone'))->toDateString(),
                'decided_by' => $fromPetty ? null : $user->id,
                'decided_at' => $fromPetty ? null : now(),
            ]);
            if ($fromPetty) {
                $this->move($location->id, 'EXPENSE', -$amount, ['type' => 'expense', 'id' => $expense->id, 'number' => $expense->number], $user);
            }
            $this->audit->record('EXPENSE_RECORDED', $expense, [
                'kategori' => $category->code, 'jumlah' => $amount, 'dibayar_dari' => $expense->paid_from,
            ], $role, $data['description'], locationId: $location->id, user: $user, subjectCode: $expense->number);

            return $expense;
        });
    }

    /** Pencatat membatalkan biaya Petty Cash yang belum diperiksa Finance (salah input); uangnya kembali ke saldo. */
    public function cancelExpense(Expense $expense, string $reason, User $user): Expense
    {
        return DB::transaction(function () use ($expense, $reason, $user) {
            Location::query()->whereKey($expense->location_id)->lockForUpdate()->first();
            $expense = Expense::query()->whereKey($expense->id)->lockForUpdate()->firstOrFail();
            if ($expense->recorded_by !== $user->id) {
                throw new AuthorizationException('Hanya pencatat biaya yang dapat membatalkannya.');
            }
            if ($expense->status !== Expense::STATUS_RECORDED || $expense->paid_from !== Expense::PAID_PETTY) {
                throw ValidationException::withMessages(['status' => 'Biaya ini sudah diperiksa Finance dan tidak dapat dibatalkan.']);
            }
            $expense->update(['status' => Expense::STATUS_CANCELLED, 'decided_by' => $user->id, 'decided_at' => now(), 'decision_note' => $reason]);
            $this->move($expense->location_id, 'EXPENSE_CANCEL', $expense->amount, ['type' => 'expense', 'id' => $expense->id, 'number' => $expense->number], $user);
            $this->audit->record('EXPENSE_CANCELLED', $expense, ['jumlah' => $expense->amount], Role::CASHIER, $reason,
                locationId: $expense->location_id, user: $user, subjectCode: $expense->number);

            return $expense;
        });
    }

    public function requestRefill(Location $outlet, ?string $note, User $user): PettyCashRefill
    {
        if ($outlet->type !== Location::TYPE_OUTLET || ! $user->hasRoleAt(Role::CASHIER, $outlet->id)) {
            throw new AuthorizationException('Isi ulang Petty Cash diminta oleh kasir outlet itu.');
        }

        return DB::transaction(function () use ($outlet, $note, $user) {
            Location::query()->whereKey($outlet->id)->lockForUpdate()->first();
            if (PettyCashRefill::query()->where(['location_id' => $outlet->id, 'status' => PettyCashRefill::STATUS_REQUESTED])->exists()) {
                throw ValidationException::withMessages(['location_id' => 'Permintaan isi ulang untuk outlet ini masih menunggu Finance.']);
            }
            $refill = PettyCashRefill::query()->create([
                'number' => DocumentNumber::next('ISP'),
                'location_id' => $outlet->id,
                'status' => PettyCashRefill::STATUS_REQUESTED,
                'requested_by' => $user->id,
                'request_note' => $note,
                'requested_at' => now(),
            ]);
            $this->audit->record('PETTY_CASH_REFILL_REQUESTED', $refill, ['saldo' => $this->balance($outlet->id)], Role::CASHIER, $note,
                locationId: $outlet->id, user: $user, subjectCode: $refill->number);

            return $refill;
        });
    }

    /**
     * Finance memeriksa setiap biaya Petty Cash yang belum diperiksa, lalu mengisi ulang:
     * jumlah = biaya terbukti + kekurangan terhadap saldo tetap (isi awal atau saldo tetap dinaikkan),
     * tanpa mengganti biaya yang ditolak.
     *
     * @param  array<int, array{decision: string, note?: string|null}>  $decisions  per id biaya
     */
    public function refill(Location $outlet, array $decisions, ?string $note, User $user): PettyCashRefill
    {
        if (! $user->hasActiveRole(Role::FINANCE)) {
            throw new AuthorizationException('Petty Cash diisi ulang oleh Finance.');
        }
        if ($outlet->type !== Location::TYPE_OUTLET) {
            throw ValidationException::withMessages(['location_id' => 'Petty Cash hanya untuk outlet.']);
        }
        if ($outlet->petty_cash_amount === null) {
            throw ValidationException::withMessages(['location_id' => "Saldo tetap Petty Cash {$outlet->name} belum diatur Owner."]);
        }

        return DB::transaction(function () use ($outlet, $decisions, $note, $user) {
            Location::query()->whereKey($outlet->id)->lockForUpdate()->first();
            $pending = $this->pendingExpenses($outlet->id);
            $verified = 0;
            $rejected = 0;
            foreach ($pending as $expense) {
                $d = $decisions[$expense->id] ?? null;
                if (! $d || ! in_array($d['decision'], [Expense::STATUS_VERIFIED, Expense::STATUS_REJECTED], true)) {
                    throw ValidationException::withMessages(["decisions.{$expense->id}" => "Periksa biaya {$expense->number} dulu: terbukti atau ditolak."]);
                }
                $dNote = trim((string) ($d['note'] ?? '')) ?: null;
                if ($d['decision'] === Expense::STATUS_REJECTED && $dNote === null) {
                    throw ValidationException::withMessages(["decisions.{$expense->id}" => "Tuliskan alasan biaya {$expense->number} ditolak."]);
                }
                $d['decision'] === Expense::STATUS_VERIFIED ? $verified += $expense->amount : $rejected += $expense->amount;
            }

            $refill = PettyCashRefill::query()->where(['location_id' => $outlet->id, 'status' => PettyCashRefill::STATUS_REQUESTED])->lockForUpdate()->first()
                ?? PettyCashRefill::query()->create(['number' => DocumentNumber::next('ISP'), 'location_id' => $outlet->id, 'status' => PettyCashRefill::STATUS_REQUESTED]);

            $balance = $this->balance($outlet->id);
            $gap = max(0, (int) $outlet->petty_cash_amount - $balance - $verified - $rejected);
            $amount = $verified + $gap;

            foreach ($pending as $expense) {
                $d = $decisions[$expense->id];
                $expense->update([
                    'status' => $d['decision'], 'decided_by' => $user->id, 'decided_at' => now(),
                    'decision_note' => trim((string) ($d['note'] ?? '')) ?: null, 'refill_id' => $refill->id,
                ]);
            }
            $refill->update([
                'status' => PettyCashRefill::STATUS_DONE, 'amount' => $amount, 'verified_total' => $verified, 'rejected_total' => $rejected,
                'refilled_by' => $user->id, 'refill_note' => $note, 'refilled_at' => now(),
            ]);
            if ($amount > 0) {
                $this->move($outlet->id, 'REFILL', $amount, ['type' => 'petty_cash_refill', 'id' => $refill->id, 'number' => $refill->number], $user);
            }
            $this->audit->record('PETTY_CASH_REFILLED', $refill, [
                'jumlah' => $amount, 'biaya_terbukti' => $verified, 'biaya_ditolak' => $rejected, 'saldo_tetap' => $outlet->petty_cash_amount,
            ], Role::FINANCE, $note, $rejected > 0 ? ['EXPENSE_REJECTED'] : [], locationId: $outlet->id, user: $user, subjectCode: $refill->number);

            return $refill;
        });
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Expense> */
    public function pendingExpenses(int $locationId)
    {
        return Expense::query()->where(['location_id' => $locationId, 'paid_from' => Expense::PAID_PETTY, 'status' => Expense::STATUS_RECORDED])
            ->with(['category', 'recorder:id,name,username'])->orderBy('id')->get();
    }

    /** @param  array{type: string, id: int, number: string}  $source */
    private function move(int $locationId, string $type, int $amount, array $source, User $user): void
    {
        PettyCashMovement::query()->create([
            'location_id' => $locationId,
            'type' => $type,
            'amount' => $amount,
            'balance_after' => $this->balance($locationId) + $amount,
            'source_type' => $source['type'],
            'source_id' => $source['id'],
            'source_number' => $source['number'],
            'user_id' => $user->id,
        ]);
    }
}
