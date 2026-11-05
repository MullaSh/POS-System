<?php

namespace App\Modules\Recipe\Http\Controllers;

use App\Modules\Catalog\Models\Item;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Recipe\Models\Recipe;
use App\Modules\Recipe\Models\RecipeVersion;
use App\Modules\Recipe\Models\RecipeVersionLine;
use App\Modules\Recipe\Services\RecipeCostService;
use App\Modules\Recipe\Services\RecipeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Resep menu dan resep produksi. Semua peran melihat isi resep; harga pokok dan HPP
 * hanya untuk Owner dan Finance (2G-Q2). Resep dibuat dan diubah Owner (keputusan 5 Nov 2026).
 */
class RecipeController
{
    public function __construct(
        private readonly RecipeService $recipes,
        private readonly RecipeCostService $costs,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['kind' => ['nullable', Rule::in([Recipe::KIND_MENU, Recipe::KIND_PRODUCTION])]]);
        $seeCost = $this->canSeeCost($request->user());

        $recipes = Recipe::query()
            ->with('outputItem.baseUnit')
            ->when($request->query('kind'), fn ($q, $k) => $q->where('kind', $k))
            ->when($request->query('output_item_id'), fn ($q, $id) => $q->where('output_item_id', $id))
            ->get()
            ->sortBy(fn (Recipe $r) => $r->outputItem->name)
            ->values()
            ->map(function (Recipe $r) use ($seeCost) {
                $current = $r->versionAt();

                return [
                    'id' => $r->id,
                    'kind' => $r->kind,
                    'output_item' => $this->itemRef($r->outputItem),
                    'current' => $current ? [
                        'version_no' => $current->version_no,
                        'effective_from' => $current->effective_from,
                        'line_count' => $current->lines()->count(),
                        'output_qty' => $current->output_qty,
                    ] : null,
                    'upcoming_count' => $this->upcoming($r)->count(),
                    'cost' => $seeCost && $current ? $this->costSummary($current) : null,
                ];
            });

        return response()->json(['data' => $recipes]);
    }

    public function show(Request $request, Recipe $recipe): JsonResponse
    {
        return response()->json(['data' => $this->present($recipe, $request->user())]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'output_item_id' => ['required', 'integer', 'exists:items,id'],
            ...RecipeService::versionRules(),
        ]);
        $recipe = $this->recipes->create(Item::query()->findOrFail($data['output_item_id']), $data, $request->user());

        return response()->json(['data' => $this->present($recipe, $request->user())], 201);
    }

    public function storeVersion(Request $request, Recipe $recipe): JsonResponse
    {
        $data = $request->validate(RecipeService::versionRules());
        $this->recipes->addVersion($recipe, $data, $request->user());

        return response()->json(['data' => $this->present($recipe->fresh(), $request->user())], 201);
    }

    /** Resep yang memakai suatu barang sebagai bahan pada versi yang berlaku. */
    public function usage(Request $request): JsonResponse
    {
        $data = $request->validate(['item_id' => ['required', 'integer']]);
        $now = now();
        // Versi berlaku per resep = id terbesar dengan effective_from <= sekarang.
        $currentIds = DB::table('recipe_versions')
            ->where('effective_from', '<=', $now)
            ->groupBy('recipe_id')
            ->selectRaw('max(id) as id');
        $recipeIds = RecipeVersionLine::query()
            ->whereIn('recipe_version_id', $currentIds)
            ->where('item_id', $data['item_id'])
            ->join('recipe_versions', 'recipe_versions.id', '=', 'recipe_version_lines.recipe_version_id')
            ->pluck('recipe_versions.recipe_id');

        $rows = Recipe::query()->with('outputItem')->whereIn('id', $recipeIds)->get()
            ->map(fn (Recipe $r) => ['id' => $r->id, 'kind' => $r->kind, 'output_item' => $this->itemRef($r->outputItem)])
            ->sortBy('output_item.name')->values();

        return response()->json(['data' => $rows]);
    }

    /** @return array<string, mixed> */
    private function present(Recipe $recipe, User $viewer): array
    {
        $recipe->load('outputItem.baseUnit');
        $seeCost = $this->canSeeCost($viewer);
        $current = $recipe->versionAt();
        $all = $recipe->versions()->with(['lines.item.baseUnit', 'lines.unit', 'outputUnit', 'creator:id,name'])->orderByDesc('id')->get();
        $superseded = fn (RecipeVersion $v) => $all->contains(fn (RecipeVersion $later) => $later->id > $v->id && $later->effective_from <= $v->effective_from);

        $versionRow = function (RecipeVersion $v, bool $withCost) use ($current, $superseded) {
            $cost = $withCost ? $this->costs->breakdown($v) : null;

            return [
                'id' => $v->id,
                'version_no' => $v->version_no,
                'effective_from' => $v->effective_from,
                'status' => match (true) {
                    $current?->id === $v->id => 'CURRENT',
                    $superseded($v) => 'SUPERSEDED',
                    $v->effective_from > now() => 'UPCOMING',
                    default => 'PAST',
                },
                'output_qty' => $v->output_qty,
                'output_entered_qty' => $v->output_entered_qty,
                'output_unit' => $v->outputUnit->only(['id', 'code', 'name']),
                'notes' => $v->notes,
                'created_by' => $v->creator?->only(['id', 'name']),
                'created_at' => $v->created_at,
                'lines' => $v->lines->values()->map(fn (RecipeVersionLine $l, int $i) => [
                    'item' => $this->itemRef($l->item),
                    'qty' => $l->qty,
                    'entered_qty' => $l->entered_qty,
                    'unit' => $l->unit->only(['id', 'code', 'name']),
                    'unit_cost' => $cost['lines'][$i]['unit_cost'] ?? null,
                    'line_cost' => $cost['lines'][$i]['line_cost'] ?? null,
                    'cost_source' => $cost['lines'][$i]['source'] ?? null,
                ]),
                'cost' => $cost ? ['total' => $cost['total'], 'per_output_unit' => $cost['per_output_unit'], 'missing' => $cost['missing']] : null,
            ];
        };

        return [
            'id' => $recipe->id,
            'kind' => $recipe->kind,
            'output_item' => $this->itemRef($recipe->outputItem),
            'can_see_cost' => $seeCost,
            'versions' => $all->map(fn (RecipeVersion $v) => $versionRow($v, $seeCost && in_array($v->id, [$current?->id, ...$this->upcoming($recipe)->pluck('id')], true)))->values(),
        ];
    }

    /** @return \Illuminate\Support\Collection<int, RecipeVersion> */
    private function upcoming(Recipe $recipe)
    {
        $currentId = $recipe->versionAt()?->id ?? 0;
        $future = $recipe->versions()->where('effective_from', '>', now())->where('id', '>', $currentId)->get();

        return $future->filter(fn (RecipeVersion $v) => ! $future->contains(fn (RecipeVersion $later) => $later->id > $v->id && $later->effective_from <= $v->effective_from))->values();
    }

    /** @return array<string, mixed> */
    private function costSummary(RecipeVersion $v): array
    {
        $c = $this->costs->breakdown($v);

        return ['total' => $c['total'], 'per_output_unit' => $c['per_output_unit'], 'missing_count' => count($c['missing'])];
    }

    /** @return array<string, mixed> */
    private function itemRef(Item $item): array
    {
        return [
            'id' => $item->id,
            'code' => $item->code,
            'name' => $item->name,
            'item_type' => $item->item_type,
            'is_active' => $item->is_active,
            'base_unit' => $item->baseUnit?->only(['id', 'code', 'name']),
        ];
    }

    private function canSeeCost(User $user): bool
    {
        return $user->hasActiveRole(Role::OWNER) || $user->hasActiveRole(Role::FINANCE);
    }
}
