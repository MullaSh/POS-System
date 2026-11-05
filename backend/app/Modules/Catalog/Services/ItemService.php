<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Models\Item;
use App\Modules\Identity\Models\Role;
use App\Modules\Location\Models\Location;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Aturan data barang (KP-01, AB-01, AB-02, AB-07, C11):
 * - Jenis barang dan satuan dasar tidak berubah setelah dibuat.
 * - Konversi selalu ke satuan dasar; satuan dasar sendiri bernilai 1.
 * - Menu bukan stok: tidak punya stok minimum.
 */
class ItemService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{code: string, name: string, item_type: string, base_unit_id: int, notes?: string|null}  $data
     * @param  list<array{unit_id: int, factor_to_base: numeric, is_purchase_default?: bool, is_usage_default?: bool}>  $units
     */
    public function create(array $data, array $units): Item
    {
        return DB::transaction(function () use ($data, $units) {
            $item = Item::query()->create([
                'code' => strtoupper($data['code']),
                'name' => $data['name'],
                'item_type' => $data['item_type'],
                'base_unit_id' => $data['base_unit_id'],
                'notes' => $data['notes'] ?? null,
            ]);

            $this->writeUnits($item, $units);

            $this->audit->record('ITEM_CREATED', $item, [
                'code' => $item->code,
                'name' => $item->name,
                'jenis' => $item->item_type,
                'satuan_dasar' => $item->baseUnit->code,
                'konversi' => $this->describeUnits($item),
            ], Role::OWNER);

            return $item;
        });
    }

    /** @param  array{name: string, notes?: string|null}  $data */
    public function update(Item $item, array $data): Item
    {
        return DB::transaction(function () use ($item, $data) {
            $before = $item->only(['name', 'notes']);
            $item->update(['name' => $data['name'], 'notes' => $data['notes'] ?? null]);
            $after = $item->only(['name', 'notes']);

            $changes = [];
            foreach ($after as $key => $value) {
                if ($before[$key] !== $value) {
                    $changes[$key] = ['dari' => $before[$key], 'menjadi' => $value];
                }
            }
            if ($changes) {
                $this->audit->record('ITEM_UPDATED', $item, $changes, Role::OWNER);
            }

            return $item;
        });
    }

    public function setActive(Item $item, bool $active): Item
    {
        return DB::transaction(function () use ($item, $active) {
            $item->update(['is_active' => $active]);
            $this->audit->record($active ? 'ITEM_ACTIVATED' : 'ITEM_DEACTIVATED', $item, actingRole: Role::OWNER);

            return $item;
        });
    }

    /** @param  list<array{unit_id: int, factor_to_base: numeric, is_purchase_default?: bool, is_usage_default?: bool}>  $units */
    public function replaceUnits(Item $item, array $units): Item
    {
        return DB::transaction(function () use ($item, $units) {
            $before = $this->describeUnits($item);
            $item->units()->delete();
            $this->writeUnits($item, $units);
            $this->audit->record('ITEM_UNITS_CHANGED', $item, [
                'dari' => $before,
                'menjadi' => $this->describeUnits($item->refresh()),
            ], Role::OWNER);

            return $item;
        });
    }

    /** @param  list<array{location_id: int, min_qty: numeric}>  $rows */
    public function replaceMinStocks(Item $item, array $rows): Item
    {
        if (! $item->isStockItem()) {
            throw ValidationException::withMessages(['min_stocks' => 'Menu bukan barang stok, sehingga tidak memiliki stok minimum.']);
        }

        $locationIds = array_column($rows, 'location_id');
        if (count($locationIds) !== count(array_unique($locationIds))) {
            throw ValidationException::withMessages(['min_stocks' => 'Setiap lokasi hanya boleh muncul sekali.']);
        }
        $closed = Location::query()->whereIn('id', $locationIds)->where('status', Location::STATUS_CLOSED)->exists();
        if ($closed) {
            throw ValidationException::withMessages(['min_stocks' => 'Lokasi yang sudah ditutup tidak dapat diberi stok minimum.']);
        }

        return DB::transaction(function () use ($item, $rows) {
            $before = $this->describeMinStocks($item);
            DB::table('item_min_stocks')->where('item_id', $item->id)->delete();
            $now = now();
            DB::table('item_min_stocks')->insert(array_map(fn ($r) => [
                'item_id' => $item->id,
                'location_id' => $r['location_id'],
                'min_qty' => $r['min_qty'],
                'created_at' => $now,
                'updated_at' => $now,
            ], $rows));

            $this->audit->record('ITEM_MIN_STOCK_CHANGED', $item, [
                'dari' => $before,
                'menjadi' => $this->describeMinStocks($item),
            ], Role::OWNER);

            return $item;
        });
    }

    /** @param  list<array{unit_id: int, factor_to_base: numeric, is_purchase_default?: bool, is_usage_default?: bool}>  $units */
    private function writeUnits(Item $item, array $units): void
    {
        $unitIds = array_column($units, 'unit_id');
        if (count($unitIds) !== count(array_unique($unitIds))) {
            throw ValidationException::withMessages(['units' => 'Setiap satuan hanya boleh muncul sekali.']);
        }
        if (count(array_filter($units, fn ($u) => ! empty($u['is_purchase_default']))) > 1) {
            throw ValidationException::withMessages(['units' => 'Hanya satu satuan yang boleh menjadi satuan beli utama.']);
        }
        if (count(array_filter($units, fn ($u) => ! empty($u['is_usage_default']))) > 1) {
            throw ValidationException::withMessages(['units' => 'Hanya satu satuan yang boleh menjadi satuan pakai utama.']);
        }

        // Satuan dasar selalu ada dengan faktor 1.
        $rows = collect($units)->reject(fn ($u) => (int) $u['unit_id'] === $item->base_unit_id);
        $base = collect($units)->first(fn ($u) => (int) $u['unit_id'] === $item->base_unit_id);

        $item->units()->create([
            'unit_id' => $item->base_unit_id,
            'factor_to_base' => 1,
            'is_purchase_default' => (bool) ($base['is_purchase_default'] ?? false),
            'is_usage_default' => (bool) ($base['is_usage_default'] ?? false),
        ]);

        foreach ($rows as $u) {
            $item->units()->create([
                'unit_id' => $u['unit_id'],
                'factor_to_base' => $u['factor_to_base'],
                'is_purchase_default' => (bool) ($u['is_purchase_default'] ?? false),
                'is_usage_default' => (bool) ($u['is_usage_default'] ?? false),
            ]);
        }
    }

    /** @return list<string> */
    private function describeUnits(Item $item): array
    {
        $base = $item->baseUnit->code;

        return $item->units()->with('unit')->orderBy('factor_to_base')->get()
            ->map(fn ($u) => sprintf('1 %s = %s %s', $u->unit->code, rtrim(rtrim((string) $u->factor_to_base, '0'), '.'), $base))
            ->values()->all();
    }

    /** @return array<string, string> */
    private function describeMinStocks(Item $item): array
    {
        return DB::table('item_min_stocks')
            ->join('locations', 'locations.id', '=', 'item_min_stocks.location_id')
            ->where('item_id', $item->id)
            ->orderBy('locations.code')
            ->pluck('item_min_stocks.min_qty', 'locations.code')
            ->map(fn ($q) => rtrim(rtrim((string) $q, '0'), '.'))
            ->all();
    }
}
