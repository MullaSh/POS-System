<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockRequest;
use App\Modules\Inventory\Models\StockRequestLine;
use App\Modules\Inventory\Services\StockAccess;
use App\Modules\Inventory\Services\StockRequestService;
use App\Modules\Location\Models\Location;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Recipe\Models\Recipe;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Permintaan stok outlet (AB-12). Gudang melihat semua permintaan; kasir hanya outletnya. */
class StockRequestController
{
    use Presents;

    public function __construct(
        private readonly StockRequestService $requests,
        private readonly StockAccess $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['OPEN', 'DONE', StockRequest::SUBMITTED, StockRequest::IN_TRANSIT, StockRequest::COMPLETED, StockRequest::REJECTED, StockRequest::CANCELLED])],
            'location_id' => ['nullable', 'integer'],
        ]);
        /** @var User $user */
        $user = $request->user();
        $ids = $this->visibleOutletIds($user);

        $page = StockRequest::query()->with(['location', 'requester:id,name,username', 'closer:id,name,username', 'transfer'])->withCount('lines')
            ->when($ids !== null, fn ($q) => $q->whereIn('location_id', $ids))
            ->when($data['location_id'] ?? null, fn ($q, $id) => $q->where('location_id', $id))
            ->when($data['status'] ?? null, fn ($q, $s) => match ($s) {
                'OPEN' => $q->whereIn('status', [StockRequest::SUBMITTED, StockRequest::IN_TRANSIT]),
                'DONE' => $q->whereIn('status', [StockRequest::COMPLETED, StockRequest::REJECTED, StockRequest::CANCELLED]),
                default => $q->where('status', $s),
            })
            ->orderByRaw("CASE WHEN status = 'SUBMITTED' THEN 0 WHEN status = 'IN_TRANSIT' THEN 1 ELSE 2 END")
            ->orderByDesc('id')
            ->paginate(50);
        $page->getCollection()->transform(fn (StockRequest $r) => $this->summary($r, $user));

        return response()->json($page);
    }

    public function show(Request $request, StockRequest $stockRequest): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->ensureVisible($user, $stockRequest);

        return response()->json(['data' => $this->present($stockRequest, $user)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(StockRequestService::rules());
        $sr = $this->requests->create($data, $request->user());

        return response()->json(['data' => $this->present($sr, $request->user())], 201);
    }

    public function cancel(Request $request, StockRequest $stockRequest): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $sr = $this->requests->cancel($stockRequest, $request->user(), $data['note'] ?? null);

        return response()->json(['data' => $this->present($sr, $request->user())]);
    }

    public function reject(Request $request, StockRequest $stockRequest): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:500']], ['note.required' => 'Tuliskan alasan penolakan.']);
        $sr = $this->requests->reject($stockRequest, $request->user(), $data['note']);

        return response()->json(['data' => $this->present($sr, $request->user())]);
    }

    /** @return list<int>|null */
    private function visibleOutletIds(User $user): ?array
    {
        return $user->hasActiveRole(Role::WAREHOUSE) ? null : $this->access->viewableLocationIds($user);
    }

    private function ensureVisible(User $user, StockRequest $sr): void
    {
        $ids = $this->visibleOutletIds($user);
        if ($ids !== null && ! in_array($sr->location_id, $ids, true)) {
            throw new AuthorizationException('Anda tidak memiliki izin untuk melihat permintaan ini.');
        }
    }

    /** @return array<string, mixed> */
    private function summary(StockRequest $r, User $viewer): array
    {
        $warehouseId = Location::query()->where('type', Location::TYPE_WAREHOUSE)->value('id');
        $isWarehouse = $warehouseId && $viewer->hasRoleAt(Role::WAREHOUSE, $warehouseId);
        $open = $r->status === StockRequest::SUBMITTED;

        return [
            'id' => $r->id,
            'number' => $r->number,
            'location' => $this->locationRef($r->location),
            'status' => $r->status,
            'needed_date' => $r->needed_date?->format('Y-m-d'),
            'notes' => $r->notes,
            'requested_by' => $this->userRef($r->requester),
            'created_at' => $r->created_at,
            'closed_by' => $this->userRef($r->closer),
            'closed_at' => $r->closed_at,
            'close_note' => $r->close_note,
            'line_count' => $r->lines_count ?? $r->lines()->count(),
            'transfer' => $r->transfer ? ['id' => $r->transfer->id, 'number' => $r->transfer->number, 'status' => $r->transfer->status] : null,
            'can' => [
                'fulfil' => $open && $isWarehouse,
                'reject' => $open && $isWarehouse,
                'cancel' => $open && $r->requested_by === $viewer->id,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function present(StockRequest $r, User $viewer): array
    {
        $r->load(['location', 'requester:id,name,username', 'closer:id,name,username', 'transfer', 'lines.item.baseUnit', 'lines.unit']);
        $warehouseId = Location::query()->where('type', Location::TYPE_WAREHOUSE)->value('id');
        $stock = $warehouseId && $this->access->canView($viewer, $warehouseId)
            ? StockBalance::query()->where('location_id', $warehouseId)->whereIn('item_id', $r->lines->pluck('item_id'))->pluck('qty', 'item_id')
            : null;

        // Barang yang punya resep produksi dapat dibuatkan Production Order bila stok gudang kurang (irisan 8).
        $producible = Recipe::query()->where('kind', Recipe::KIND_PRODUCTION)->whereIn('output_item_id', $r->lines->pluck('item_id'))->pluck('output_item_id')->all();
        $productions = ProductionOrder::query()->with('outputItem:id,name')->where('stock_request_id', $r->id)->orderBy('id')->get();

        return [
            ...$this->summary($r, $viewer),
            'lines' => $r->lines->map(fn (StockRequestLine $l) => [
                'item' => $this->itemRef($l->item),
                'unit' => $this->unitRef($l->unit),
                'factor' => $l->factor,
                'qty' => $l->qty,
                'entered_qty' => $this->inUnit($l->qty, $l->factor),
                'warehouse_qty' => $stock === null ? null : (string) ($stock->get($l->item_id) ?? '0'),
                'producible' => in_array((int) $l->item_id, array_map('intval', $producible), true),
            ])->values(),
            'productions' => $productions->map(fn (ProductionOrder $o) => [
                'id' => $o->id, 'number' => $o->number, 'status' => $o->status, 'item' => $o->outputItem?->name,
            ])->values(),
        ];
    }
}
