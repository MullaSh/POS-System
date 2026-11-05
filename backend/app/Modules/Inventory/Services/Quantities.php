<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\ItemUnit;
use App\Modules\Location\Models\Location;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/** Bantuan untuk baris dokumen stok: barang, satuan, dan konversi ke satuan dasar (C11, AB-02). */
class Quantities
{
    /** Faktor satuan ke satuan dasar barang. */
    public function factor(Item $item, int $unitId, string $field): string
    {
        $factor = ItemUnit::query()->where(['item_id' => $item->id, 'unit_id' => $unitId])->value('factor_to_base');
        if ($factor === null) {
            throw ValidationException::withMessages([$field => "Satuan ini belum diatur untuk {$item->name}."]);
        }

        return (string) $factor;
    }

    public function toBase(string $qty, string $factor): string
    {
        return Decimal::round(bcmul($qty, $factor, Decimal::WORK));
    }

    /**
     * Barang untuk setiap baris; harus barang stok yang aktif.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return Collection<int, Item>
     */
    public function stockItems(array $lines): Collection
    {
        $items = Item::query()->whereIn('id', array_column($lines, 'item_id'))->get()->keyBy('id');
        foreach ($lines as $i => $line) {
            $item = $items->get($line['item_id']);
            if (! $item || ! $item->isStockItem()) {
                throw ValidationException::withMessages(["lines.{$i}.item_id" => 'Pilih barang stok, bukan menu.']);
            }
            if (! $item->is_active) {
                throw ValidationException::withMessages(["lines.{$i}.item_id" => "{$item->name} sedang nonaktif."]);
            }
        }

        return $items;
    }

    public static function ensureUsable(Location $location, bool $allowInactive = false): void
    {
        if ($location->status === Location::STATUS_CLOSED || (! $allowInactive && $location->status !== Location::STATUS_ACTIVE)) {
            throw ValidationException::withMessages(['location_id' => "{$location->name} sedang tidak aktif."]);
        }
    }

    /** @return array<string, mixed> */
    public static function lineRules(string $qtyField = 'qty', bool $allowZero = false): array
    {
        return [
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.item_id' => ['required', 'integer', 'distinct'],
            'lines.*.unit_id' => ['required', 'integer'],
            "lines.*.{$qtyField}" => ['required', 'numeric', $allowZero ? 'min:0' : 'gt:0', 'max:99999999'],
        ];
    }
}
