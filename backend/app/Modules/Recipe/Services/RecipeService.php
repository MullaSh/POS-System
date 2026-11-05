<?php

namespace App\Modules\Recipe\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\ItemUnit;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Recipe\Models\Recipe;
use App\Modules\Recipe\Models\RecipeVersion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Aturan resep (C2, KP-05, AB-08; keputusan Owner 5 Nov 2026: resep dikelola Owner):
 * - Resep menu: untuk barang menu; bahannya barang setengah jadi, bahan pendukung, packaging.
 * - Resep produksi: untuk barang setengah jadi atau bahan pendukung buatan sendiri;
 *   bahannya bahan baku, bahan pendukung, packaging, atau barang setengah jadi lain.
 * - Setiap perubahan = versi baru dengan tanggal berlaku; versi lama tidak diubah.
 */
class RecipeService
{
    public const MENU_INGREDIENTS = [Item::TYPE_SEMI_FINISHED, Item::TYPE_SUPPORTING, Item::TYPE_PACKAGING];

    public const PRODUCTION_INGREDIENTS = [Item::TYPE_RAW, Item::TYPE_SUPPORTING, Item::TYPE_PACKAGING, Item::TYPE_SEMI_FINISHED];

    public const PRODUCIBLE = [Item::TYPE_SEMI_FINISHED, Item::TYPE_SUPPORTING];

    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array<string, list<mixed>> */
    public static function versionRules(): array
    {
        return [
            'output_unit_id' => ['nullable', 'integer'],
            'output_qty' => ['nullable', 'numeric', 'gt:0', 'max:99999999'],
            'effective_date' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.item_id' => ['required', 'integer', 'distinct', 'exists:items,id'],
            'lines.*.unit_id' => ['required', 'integer'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0', 'max:99999999'],
        ];
    }

    public static function kindFor(Item $item): ?string
    {
        return match (true) {
            $item->isMenu() => Recipe::KIND_MENU,
            in_array($item->item_type, self::PRODUCIBLE, true) => Recipe::KIND_PRODUCTION,
            default => null,
        };
    }

    /** @param  array<string, mixed>  $input */
    public function create(Item $output, array $input, User $by): Recipe
    {
        $kind = self::kindFor($output);
        if ($kind === null) {
            throw ValidationException::withMessages([
                'output_item_id' => 'Resep hanya untuk menu, barang setengah jadi, atau bahan pendukung buatan sendiri.',
            ]);
        }

        return DB::transaction(function () use ($output, $input, $by, $kind) {
            if (Recipe::query()->where('output_item_id', $output->id)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['output_item_id' => 'Barang ini sudah punya resep. Ubah lewat versi baru.']);
            }
            $recipe = Recipe::query()->create(['kind' => $kind, 'output_item_id' => $output->id, 'created_by' => $by->id]);
            $version = $this->writeVersion($recipe, $output, $input, $by, 1);
            $this->audit->record('RECIPE_CREATED', $recipe, $this->describe($version), Role::OWNER, $input['notes'] ?? null,
                user: $by, subjectCode: $output->code);

            return $recipe;
        });
    }

    /** @param  array<string, mixed>  $input */
    public function addVersion(Recipe $recipe, array $input, User $by): RecipeVersion
    {
        return DB::transaction(function () use ($recipe, $input, $by) {
            $recipe = Recipe::query()->whereKey($recipe->id)->lockForUpdate()->firstOrFail();
            $next = (int) $recipe->versions()->max('version_no') + 1;
            $version = $this->writeVersion($recipe, $recipe->outputItem, $input, $by, $next);
            $this->audit->record('RECIPE_VERSION_CREATED', $recipe, $this->describe($version), Role::OWNER, $input['notes'] ?? null,
                user: $by, subjectCode: $recipe->outputItem->code);

            return $version;
        });
    }

    /** @param  array<string, mixed>  $input */
    private function writeVersion(Recipe $recipe, Item $output, array $input, User $by, int $versionNo): RecipeVersion
    {
        $allowed = $recipe->kind === Recipe::KIND_MENU ? self::MENU_INGREDIENTS : self::PRODUCTION_INGREDIENTS;
        $lines = [];

        foreach ($input['lines'] as $i => $line) {
            $item = Item::query()->findOrFail($line['item_id']);
            $field = "lines.{$i}.item_id";
            if ($item->id === $output->id) {
                throw ValidationException::withMessages([$field => 'Barang hasil tidak boleh menjadi bahannya sendiri.']);
            }
            if (! in_array($item->item_type, $allowed, true)) {
                throw ValidationException::withMessages([$field => $recipe->kind === Recipe::KIND_MENU
                    ? "{$item->name} tidak dapat dipakai di resep menu. Gunakan barang setengah jadi, bahan pendukung, atau packaging."
                    : "{$item->name} tidak dapat dipakai di resep produksi."]);
            }
            if (! $item->is_active) {
                throw ValidationException::withMessages([$field => "{$item->name} sedang nonaktif."]);
            }
            $lines[] = [
                'item_id' => $item->id,
                'unit_id' => (int) $line['unit_id'],
                'entered_qty' => (string) $line['qty'],
                'qty' => $this->toBase($item, (int) $line['unit_id'], (string) $line['qty'], "lines.{$i}.unit_id"),
                'position' => $i + 1,
            ];
        }

        if ($recipe->kind === Recipe::KIND_PRODUCTION) {
            if (empty($input['output_unit_id']) || empty($input['output_qty'])) {
                throw ValidationException::withMessages(['output_qty' => 'Isi jumlah hasil untuk satu kali produksi.']);
            }
            $outputUnit = (int) $input['output_unit_id'];
            $outputEntered = (string) $input['output_qty'];
            $outputQty = $this->toBase($output, $outputUnit, $outputEntered, 'output_unit_id');
            $this->ensureNoCycle($output, array_column($lines, 'item_id'));
        } else {
            // Resep menu selalu untuk satu porsi (satuan dasar menu).
            [$outputUnit, $outputEntered, $outputQty] = [$output->base_unit_id, '1', '1'];
        }

        $version = RecipeVersion::query()->create([
            'recipe_id' => $recipe->id,
            'version_no' => $versionNo,
            'effective_from' => $this->effectiveFrom($input['effective_date'] ?? null),
            'output_qty' => $outputQty,
            'output_unit_id' => $outputUnit,
            'output_entered_qty' => $outputEntered,
            'notes' => $input['notes'] ?? null,
            'created_by' => $by->id,
        ]);
        foreach ($lines as $line) {
            $version->lines()->create($line);
        }

        return $version->load(['lines.item', 'lines.unit', 'outputUnit']);
    }

    /** Ubah jumlah ke satuan dasar barang memakai konversi barang itu (C11). */
    private function toBase(Item $item, int $unitId, string $qty, string $field): string
    {
        $factor = ItemUnit::query()->where(['item_id' => $item->id, 'unit_id' => $unitId])->value('factor_to_base');
        if ($factor === null) {
            throw ValidationException::withMessages([$field => "Satuan ini belum diatur untuk {$item->name}. Tambahkan di konversi satuan barang."]);
        }
        $base = bcmul($qty, (string) $factor, 4);
        if (bccomp($base, '0', 4) <= 0) {
            throw ValidationException::withMessages([$field => 'Jumlah terlalu kecil.']);
        }

        return $base;
    }

    /**
     * Cegah resep produksi melingkar, mis. A dibuat dari B dan B dibuat dari A.
     *
     * @param  list<int>  $ingredientIds
     */
    private function ensureNoCycle(Item $output, array $ingredientIds): void
    {
        $seen = [];
        $stack = $ingredientIds;
        while ($stack) {
            $id = array_pop($stack);
            if ($id === $output->id) {
                throw ValidationException::withMessages(['lines' => "Resep melingkar: salah satu bahan dibuat dari {$output->name}."]);
            }
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $versionId = RecipeVersion::query()
                ->whereHas('recipe', fn ($q) => $q->where('output_item_id', $id)->where('kind', Recipe::KIND_PRODUCTION))
                ->max('id');
            if ($versionId) {
                array_push($stack, ...DB::table('recipe_version_lines')->where('recipe_version_id', $versionId)->pluck('item_id')->all());
            }
        }
    }

    /** Tanggal di masa depan berlaku mulai 00.00 WIB; selain itu langsung berlaku. */
    private function effectiveFrom(?string $date): Carbon
    {
        $now = now();
        if ($date === null) {
            return $now;
        }
        if ($date < $now->copy()->setTimezone(config('app.timezone'))->format('Y-m-d')) {
            throw ValidationException::withMessages(['effective_date' => 'Tanggal berlaku tidak boleh sebelum hari ini.']);
        }
        $start = Carbon::createFromFormat('Y-m-d', $date, config('app.timezone'))->startOfDay();

        return $start->greaterThan($now) ? $start : $now;
    }

    /** @return array<string, mixed> */
    private function describe(RecipeVersion $v): array
    {
        $fmt = fn (string $n) => rtrim(rtrim($n, '0'), '.');

        return [
            'versi' => $v->version_no,
            'berlaku_mulai' => $v->effective_from->toIso8601String(),
            'hasil' => $fmt((string) $v->output_entered_qty).' '.$v->outputUnit->code,
            'bahan' => $v->lines->map(fn ($l) => "{$l->item->code}: {$fmt((string) $l->entered_qty)} {$l->unit->code}")->all(),
        ];
    }
}
