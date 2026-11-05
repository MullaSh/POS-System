<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockEntry;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Location\Models\Location;
use App\Support\Decimal;
use App\Support\DocumentNumber;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Dokumen stok satu langkah:
 * - Saldo awal: diisi Owner atau Finance bersama harga pokoknya, satu kali per barang per lokasi
 *   (keputusan Owner 5 Nov 2026). Koreksi sesudahnya lewat stock opname.
 * - Waste: barang rusak atau dibuang dicatat langsung oleh petugas lokasi dengan alasan, tanpa
 *   menunggu persetujuan, dan dipantau Owner (OD-21, keputusan Owner 5 Nov 2026).
 */
class StockEntryService
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly Quantities $quantities,
        private readonly StockAccess $access,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array<string, mixed> */
    public static function openingRules(): array
    {
        return [
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
            ...Quantities::lineRules(),
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'lines.*.batch_no' => ['nullable', 'string', 'max:60', 'regex:/^[A-Za-z0-9.\/-]+$/'],
            'lines.*.expiry_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /** @return array<string, mixed> */
    public static function wasteRules(): array
    {
        return [
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'reason_code' => ['required', 'in:'.implode(',', StockEntry::WASTE_REASONS)],
            'notes' => ['required', 'string', 'max:1000'],
            ...Quantities::lineRules(),
            'lines.*.batch_id' => ['nullable', 'integer'],
        ];
    }

    /** @param  array<string, mixed>  $data */
    public function opening(array $data, User $user): StockEntry
    {
        $role = match (true) {
            $user->hasActiveRole(Role::OWNER) => Role::OWNER,
            $user->hasActiveRole(Role::FINANCE) => Role::FINANCE,
            default => throw new AuthorizationException('Saldo awal hanya diisi Owner atau Finance.'),
        };
        $location = Location::query()->findOrFail($data['location_id']);
        Quantities::ensureUsable($location, allowInactive: true);

        return DB::transaction(function () use ($data, $user, $role, $location) {
            $items = $this->quantities->stockItems($data['lines']);
            $isWarehouse = $location->type === Location::TYPE_WAREHOUSE;

            foreach ($data['lines'] as $i => $line) {
                $item = $items[$line['item_id']];
                if ($this->ledger->hasMovements($item->id, $location->id)) {
                    throw ValidationException::withMessages([
                        "lines.{$i}.item_id" => "{$item->name} sudah punya catatan stok di {$location->name}. Koreksi jumlahnya lewat stock opname.",
                    ]);
                }
                if (! $isWarehouse && (! empty($line['batch_no']) || ! empty($line['expiry_date']))) {
                    throw ValidationException::withMessages(["lines.{$i}.batch_no" => 'Batch dan kedaluwarsa hanya dicatat di gudang.']);
                }
            }

            $entry = StockEntry::query()->create([
                'number' => DocumentNumber::next('SA'),
                'kind' => StockEntry::KIND_OPENING,
                'location_id' => $location->id,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
                'role_id' => Role::idFor($role),
                'business_date' => now()->setTimezone(config('app.timezone'))->toDateString(),
            ]);
            $source = ['type' => 'stock_entry', 'id' => $entry->id, 'number' => $entry->number];

            $summary = [];
            foreach ($data['lines'] as $i => $line) {
                $item = $items[$line['item_id']];
                $factor = $this->quantities->factor($item, (int) $line['unit_id'], "lines.{$i}.unit_id");
                $qty = $this->quantities->toBase((string) $line['qty'], $factor);
                $unitCost = Decimal::round(bcdiv((string) $line['unit_cost'], $factor, Decimal::WORK));
                $this->ledger->receive($item, $location, $qty, $unitCost, StockMovement::OPENING, $source, $user, [
                    'batch_no' => $line['batch_no'] ?? null ?: $entry->number,
                    'expiry_date' => $line['expiry_date'] ?? null,
                    'batch_source' => 'OPENING',
                ]);
                $summary[] = ['barang' => $item->code, 'jumlah' => $qty, 'harga_pokok' => $unitCost];
            }

            $this->audit->record('STOCK_OPENING_RECORDED', $entry, ['lokasi' => $location->code, 'baris' => $summary],
                $role, $data['notes'] ?? null, locationId: $location->id, user: $user);

            return $entry;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function waste(array $data, User $user): StockEntry
    {
        $location = Location::query()->findOrFail($data['location_id']);
        $role = $this->access->ensureOperator($user, $location);
        Quantities::ensureUsable($location, allowInactive: true);

        return DB::transaction(function () use ($data, $user, $role, $location) {
            $items = $this->quantities->stockItems($data['lines']);
            $entry = StockEntry::query()->create([
                'number' => DocumentNumber::next('WST'),
                'kind' => StockEntry::KIND_WASTE,
                'location_id' => $location->id,
                'reason_code' => $data['reason_code'],
                'notes' => $data['notes'],
                'created_by' => $user->id,
                'role_id' => Role::idFor($role),
                'business_date' => now()->setTimezone(config('app.timezone'))->toDateString(),
            ]);
            $source = ['type' => 'stock_entry', 'id' => $entry->id, 'number' => $entry->number];

            $summary = [];
            foreach ($data['lines'] as $i => $line) {
                $item = $items[$line['item_id']];
                $batchId = $location->type === Location::TYPE_WAREHOUSE ? ($line['batch_id'] ?? null) : null;
                if ($batchId && ! StockBatch::query()->whereKey($batchId)->where(['item_id' => $item->id, 'location_id' => $location->id])->exists()) {
                    throw ValidationException::withMessages(["lines.{$i}.batch_id" => "Batch tidak cocok dengan {$item->name}."]);
                }
                $factor = $this->quantities->factor($item, (int) $line['unit_id'], "lines.{$i}.unit_id");
                $qty = $this->quantities->toBase((string) $line['qty'], $factor);
                $this->ledger->issue($item, $location, $qty, StockMovement::WASTE, $source, $user, $batchId ? (int) $batchId : null);
                $summary[] = ['barang' => $item->code, 'jumlah' => $qty];
            }

            $this->audit->record('STOCK_WASTE_RECORDED', $entry, ['lokasi' => $location->code, 'alasan' => $data['reason_code'], 'baris' => $summary],
                $role, $data['notes'], locationId: $location->id, user: $user);

            return $entry;
        });
    }
}
