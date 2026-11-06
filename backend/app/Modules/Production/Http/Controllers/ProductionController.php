<?php

namespace App\Modules\Production\Http\Controllers;

use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Services\Quantities;
use App\Modules\Production\Models\ProductionFailure;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Models\ProductionOrderLine;
use App\Modules\Production\Services\ProductionService;
use App\Modules\Recipe\Models\Recipe;
use App\Support\Decimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Production Order (irisan 8). Dilihat Owner, Finance, dan gudang; dikerjakan gudang.
 * Biaya bahan dan harga pokok hasil hanya untuk Owner dan Finance (2G-Q2).
 */
class ProductionController
{
    public function __construct(private readonly ProductionService $production) {}

    private function seesCost(User $u): bool
    {
        return $u->hasActiveRole(Role::OWNER) || $u->hasActiveRole(Role::FINANCE);
    }

    private function ensureCanView(User $u): void
    {
        if (! $this->seesCost($u) && ! $u->hasActiveRole(Role::WAREHOUSE)) {
            throw new AuthorizationException('Produksi dilihat Owner, Finance, dan gudang.');
        }
    }

    private function isOperator(User $u): bool
    {
        return $u->hasRoleAt(Role::WAREHOUSE, ProductionService::warehouse()->id);
    }

    public function index(Request $request): JsonResponse
    {
        $this->ensureCanView($request->user());
        $data = $request->validate(['status' => ['nullable', Rule::in(['PLANNED', 'COMPLETED', 'CANCELLED', 'OVER_TOLERANCE'])]]);
        $status = $data['status'] ?? null;
        $page = ProductionOrder::query()->with(['outputItem.baseUnit', 'unit', 'creator:id,name', 'stockRequest:id,number'])
            ->when($status === 'OVER_TOLERANCE', fn ($q) => $q->where('over_tolerance', true))
            ->when($status && $status !== 'OVER_TOLERANCE', fn ($q) => $q->where('status', $status))
            ->orderByDesc('id')->paginate(50);
        $page->getCollection()->transform(fn (ProductionOrder $o) => $this->summary($o, $request->user()));

        return response()->json([...$page->toArray(), 'counts' => [
            'planned' => ProductionOrder::query()->where('status', ProductionOrder::STATUS_PLANNED)->count(),
            'over_tolerance' => ProductionOrder::query()->where('over_tolerance', true)->count(),
        ]]);
    }

    public function show(Request $request, ProductionOrder $order): JsonResponse
    {
        $this->ensureCanView($request->user());

        return response()->json(['data' => $this->present($order, $request->user())]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(ProductionService::planRules(), ['output_item_id.required' => 'Pilih barang yang diproduksi.', 'qty.required' => 'Isi target hasil.']);
        $order = $this->production->plan($data, $request->user());

        return response()->json(['data' => $this->present($order->fresh(), $request->user())], 201);
    }

    public function complete(Request $request, ProductionOrder $order): JsonResponse
    {
        $data = $request->validate(ProductionService::completeRules(), ['good_qty.required' => 'Isi jumlah hasil baik (0 bila semua gagal).']);
        $order = $this->production->complete($order, $data, $request->user());

        return response()->json(['data' => $this->present($order->fresh(), $request->user())]);
    }

    public function cancel(Request $request, ProductionOrder $order): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']], ['reason.required' => 'Tuliskan alasan rencana dibatalkan.']);
        $order = $this->production->cancel($order, $data['reason'], $request->user());

        return response()->json(['data' => $this->present($order->fresh(), $request->user())]);
    }

    /** Barang yang punya resep produksi berlaku, untuk dipilih saat merencanakan. */
    public function products(Request $request): JsonResponse
    {
        $this->ensureCanView($request->user());
        $recipes = Recipe::query()->where('kind', Recipe::KIND_PRODUCTION)->with(['outputItem.baseUnit', 'outputItem.units.unit'])->get();
        $rows = [];
        foreach ($recipes as $r) {
            $version = $r->versionAt();
            $item = $r->outputItem;
            if (! $version || ! $item->is_active) {
                continue;
            }
            $rows[] = [
                'id' => $item->id, 'code' => $item->code, 'name' => $item->name, 'item_type' => $item->item_type,
                'base_unit' => $item->baseUnit?->only(['id', 'code', 'name']),
                'units' => $item->units->sortBy(fn ($u) => (float) $u->factor_to_base)->map(fn ($u) => [
                    'unit_id' => $u->unit_id, 'factor_to_base' => (string) $u->factor_to_base, 'unit' => $u->unit->only(['id', 'code', 'name']),
                ])->values(),
                'shelf_life_days' => $item->shelf_life_days,
                'recipe' => ['id' => $r->id, 'version_no' => $version->version_no, 'output_qty' => (string) $version->output_qty, 'output_unit_id' => $version->output_unit_id, 'output_entered_qty' => (string) $version->output_entered_qty],
            ];
        }
        usort($rows, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return response()->json(['data' => $rows]);
    }

    /** Bahan standar dan kekurangan stok gudang untuk target tertentu, sebelum rencana disimpan. */
    public function preview(Request $request): JsonResponse
    {
        $this->ensureCanView($request->user());
        $data = $request->validate([
            'output_item_id' => ['required', 'integer', 'exists:items,id'],
            'unit_id' => ['required', 'integer'],
            'qty' => ['required', 'numeric', 'gt:0', 'max:99999999'],
        ]);
        $item = Item::query()->findOrFail($data['output_item_id']);
        $version = $this->production->currentVersion($item);
        $factor = app(Quantities::class)->factor($item, (int) $data['unit_id'], 'unit_id');
        $planned = Decimal::round(bcmul(Decimal::round((string) $data['qty']), $factor, Decimal::WORK));
        $lines = $this->production->standardLines($version, $planned);
        $stock = $this->warehouseStock(array_map(fn ($l) => $l['item']->id, $lines));
        $units = Unit::query()->whereIn('id', array_column($lines, 'unit_id'))->pluck('code', 'id');

        return response()->json(['data' => [
            'planned_qty' => $planned,
            'recipe_version_no' => $version->version_no,
            'lines' => array_map(fn ($l) => $this->stockRow($l['item'], $l['unit_id'], $units[$l['unit_id']] ?? null, $l['factor'], $l['standard_qty'], $stock), $lines),
        ]]);
    }

    public function settings(Request $request): JsonResponse
    {
        $this->ensureCanView($request->user());

        return response()->json(['data' => ['variance_tolerance_pct' => ProductionService::tolerance(), 'can_edit' => $request->user()->hasActiveRole(Role::OWNER)]]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate(['variance_tolerance_pct' => ['required', 'integer', 'min:0', 'max:100']]);
        $this->production->setTolerance((int) $data['variance_tolerance_pct'], $request->user());

        return $this->settings($request);
    }

    // --- Bentuk data ---

    /**
     * @param  list<int>  $itemIds
     * @return \Illuminate\Support\Collection<int, string>
     */
    private function warehouseStock(array $itemIds)
    {
        return StockBalance::query()->where('location_id', ProductionService::warehouse()->id)->whereIn('item_id', $itemIds)->pluck('qty', 'item_id');
    }

    private static function inUnit(?string $qty, string $factor): ?string
    {
        return $qty === null ? null : Decimal::round(bcdiv($qty, $factor, Decimal::WORK));
    }

    /**
     * @param  \Illuminate\Support\Collection<int, string>  $stock
     * @return array<string, mixed>
     */
    private function stockRow(Item $item, int $unitId, ?string $unitCode, string $factor, string $standard, $stock): array
    {
        $item->loadMissing('baseUnit');
        $now = (string) ($stock->get($item->id) ?? '0');
        $short = Decimal::cmp($standard, $now) > 0 ? Decimal::round(bcsub($standard, $now, Decimal::WORK)) : '0.0000';

        return [
            'item' => ['id' => $item->id, 'code' => $item->code, 'name' => $item->name, 'item_type' => $item->item_type, 'base_unit' => $item->baseUnit?->only(['id', 'code', 'name'])],
            'unit' => ['id' => $unitId, 'code' => $unitCode],
            'factor' => $factor,
            'standard_qty' => $standard,
            'standard_entered' => self::inUnit($standard, $factor),
            'stock_qty' => $now,
            'shortage_qty' => $short,
            'shortage_entered' => self::inUnit($short, $factor),
        ];
    }

    /** @return array<string, mixed> */
    private function summary(ProductionOrder $o, User $viewer): array
    {
        $o->loadMissing(['outputItem.baseUnit', 'unit', 'creator:id,name', 'completer:id,name', 'canceller:id,name', 'stockRequest:id,number,status']);
        $cost = $this->seesCost($viewer);
        $factor = (string) $o->factor;

        return [
            'id' => $o->id,
            'number' => $o->number,
            'status' => $o->status,
            'output_item' => $o->outputItem ? [...$o->outputItem->only(['id', 'code', 'name', 'item_type', 'shelf_life_days']), 'base_unit' => $o->outputItem->baseUnit?->only(['id', 'code', 'name'])] : null,
            'unit' => $o->unit?->only(['id', 'code']),
            'factor' => $factor,
            'planned_qty' => (string) $o->planned_qty,
            'planned_entered' => self::inUnit((string) $o->planned_qty, $factor),
            'planned_date' => $o->planned_date?->format('Y-m-d'),
            'stock_request' => $o->stockRequest?->only(['id', 'number', 'status']),
            'notes' => $o->notes,
            'created_by' => $o->creator?->only(['id', 'name']),
            'created_at' => $o->created_at,
            'good_qty' => $o->good_qty === null ? null : (string) $o->good_qty,
            'good_entered' => self::inUnit($o->good_qty === null ? null : (string) $o->good_qty, $factor),
            'failed_qty' => $o->failed_qty === null ? null : (string) $o->failed_qty,
            'failed_entered' => self::inUnit($o->failed_qty === null ? null : (string) $o->failed_qty, $factor),
            'output_variance_pct' => $o->output_variance_pct === null ? null : (string) $o->output_variance_pct,
            'over_tolerance' => $o->over_tolerance,
            'tolerance_pct' => $o->tolerance_pct === null ? null : (string) $o->tolerance_pct,
            'variance_note' => $o->variance_note,
            'completion_note' => $o->completion_note,
            'batch_no' => $o->batch_no,
            'expiry_date' => $o->expiry_date?->format('Y-m-d'),
            'completed_by' => $o->completer?->only(['id', 'name']),
            'completed_at' => $o->completed_at,
            'cancelled_by' => $o->canceller?->only(['id', 'name']),
            'cancelled_at' => $o->cancelled_at,
            'cancel_reason' => $o->cancel_reason,
            'total_cost' => $cost && $o->total_cost !== null ? (string) $o->total_cost : null,
            'unit_cost' => $cost && $o->unit_cost !== null ? (string) $o->unit_cost : null,
            'cost_complete' => $cost ? $o->cost_complete : null,
            'failed_value' => $cost && $o->failed_value !== null ? (string) $o->failed_value : null,
        ];
    }

    /** @return array<string, mixed> */
    private function present(ProductionOrder $o, User $viewer): array
    {
        $o->loadMissing(['lines.item.baseUnit', 'lines.unit', 'failures', 'recipeVersion']);
        $planned = $o->status === ProductionOrder::STATUS_PLANNED;
        $stock = $planned ? $this->warehouseStock($o->lines->pluck('item_id')->all()) : collect();
        $cost = $this->seesCost($viewer);
        $operator = $this->isOperator($viewer);

        return [
            ...$this->summary($o, $viewer),
            'recipe_version_no' => $o->recipeVersion?->version_no,
            'recipe_id' => $o->recipeVersion?->recipe_id,
            'lines' => $o->lines->map(function (ProductionOrderLine $l) use ($planned, $stock, $cost) {
                $row = $this->stockRow($l->item, $l->unit_id, $l->unit?->code, (string) $l->factor, (string) $l->standard_qty, $stock);
                if (! $planned) {
                    unset($row['stock_qty'], $row['shortage_qty'], $row['shortage_entered']);
                }

                return [
                    'id' => $l->id,
                    ...$row,
                    'actual_qty' => $l->actual_qty === null ? null : (string) $l->actual_qty,
                    'actual_entered' => self::inUnit($l->actual_qty === null ? null : (string) $l->actual_qty, (string) $l->factor),
                    'variance_pct' => $l->variance_pct === null ? null : (string) $l->variance_pct,
                    'cost' => $cost && $l->cost !== null ? (string) $l->cost : null,
                ];
            })->values(),
            'failures' => $o->failures->map(fn (ProductionFailure $f) => [
                'reason' => $f->reason, 'qty' => (string) $f->qty, 'entered' => self::inUnit((string) $f->qty, (string) $o->factor), 'note' => $f->note,
            ])->values(),
            'can' => [
                'complete' => $planned && $operator,
                'cancel' => $planned && $operator,
                'request_purchase' => $planned && $viewer->hasRoleAt(Role::WAREHOUSE, $o->location_id),
            ],
        ];
    }
}
