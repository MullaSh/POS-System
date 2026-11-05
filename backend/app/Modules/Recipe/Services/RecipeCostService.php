<?php

namespace App\Modules\Recipe\Services;

use App\Modules\Catalog\Models\Item;
use App\Modules\Recipe\Contracts\CostSource;
use App\Modules\Recipe\Models\Recipe;
use App\Modules\Recipe\Models\RecipeVersion;
use Illuminate\Support\Carbon;

/**
 * HPP dari resep (C16, AB-09). Keputusan Owner 5 Nov 2026 (OD-07): HPP hanya dari bahan
 * dan kemasan; gas, listrik, gaji, dan sewa dicatat sebagai biaya operasional.
 *
 * Harga pokok bahan diambil dari rata-rata stok (KP-04). Bila belum ada dan bahannya dibuat
 * sendiri, dipakai perkiraan dari resep produksinya ("HPP standar"). HPP yang dikunci pada
 * penjualan dihitung di modul penjualan (irisan 5) memakai rata-rata outlet saat transaksi.
 */
class RecipeCostService
{
    private const SCALE = 6;

    private const MAX_DEPTH = 10;

    public function __construct(private readonly CostSource $costs) {}

    /**
     * Rincian biaya satu versi resep.
     *
     * @return array{lines: list<array<string, mixed>>, total: string|null, per_output_unit: string|null, missing: list<string>}
     */
    public function breakdown(RecipeVersion $version, ?int $locationId = null, int $depth = 0): array
    {
        $version->loadMissing('lines.item');
        $rows = [];
        $total = '0';
        $missing = [];

        foreach ($version->lines as $line) {
            [$unitCost, $source] = $this->unitCost($line->item, $locationId, $depth + 1, $version->effective_from);
            $lineCost = $unitCost === null ? null : bcmul((string) $line->qty, $unitCost, self::SCALE);
            if ($lineCost === null) {
                $missing[] = $line->item->name;
            } else {
                $total = bcadd($total, $lineCost, self::SCALE);
            }
            $rows[] = [
                'item_id' => $line->item_id,
                'unit_cost' => $unitCost === null ? null : $this->round($unitCost, 4),
                'line_cost' => $lineCost === null ? null : $this->round($lineCost, 2),
                'source' => $source,
            ];
        }

        $complete = $missing === [];

        return [
            'lines' => $rows,
            'total' => $complete ? $this->round($total, 2) : null,
            'per_output_unit' => $complete ? $this->round(bcdiv($total, (string) $version->output_qty, self::SCALE), 4) : null,
            'missing' => $missing,
        ];
    }

    /**
     * Harga pokok per satuan dasar dan sumbernya: STOCK_AVERAGE, FROM_RECIPE, atau null.
     *
     * @return array{0: string|null, 1: string|null}
     */
    public function unitCost(Item $item, ?int $locationId = null, int $depth = 0, ?Carbon $at = null): array
    {
        $cost = $this->costs->unitCost($item, $locationId);
        if ($cost !== null) {
            return [$cost, 'STOCK_AVERAGE'];
        }
        if ($depth >= self::MAX_DEPTH) {
            return [null, null];
        }

        $recipe = Recipe::query()->where('output_item_id', $item->id)->where('kind', Recipe::KIND_PRODUCTION)->first();
        $version = $recipe?->versionAt($at);
        if ($version === null) {
            return [null, null];
        }
        $perUnit = $this->breakdown($version, $locationId, $depth)['per_output_unit'];

        return [$perUnit, $perUnit === null ? null : 'FROM_RECIPE'];
    }

    private function round(string $value, int $scale): string
    {
        // Pembulatan setengah ke atas (bcmath hanya memotong).
        $factor = bcpow('10', (string) $scale);
        $shifted = bcmul($value, $factor, self::SCALE);
        $rounded = bccomp($shifted, '0', self::SCALE) >= 0
            ? bcadd($shifted, '0.5', 0)
            : bcsub($shifted, '0.5', 0);

        return bcdiv($rounded, $factor, $scale);
    }
}
