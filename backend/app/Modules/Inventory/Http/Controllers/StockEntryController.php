<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\StockEntry;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\StockAccess;
use App\Modules\Inventory\Services\StockEntryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Saldo awal dan waste. */
class StockEntryController
{
    use Presents;

    public function __construct(
        private readonly StockEntryService $entries,
        private readonly StockAccess $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['nullable', Rule::in([StockEntry::KIND_OPENING, StockEntry::KIND_WASTE])],
            'location_id' => ['nullable', 'integer'],
        ]);
        /** @var User $user */
        $user = $request->user();
        $ids = $this->access->viewableLocationIds($user);

        $page = StockEntry::query()->with(['location', 'creator:id,name,username'])->withCount('movements')
            ->when($ids !== null, fn ($q) => $q->whereIn('location_id', $ids))
            ->when($data['kind'] ?? null, fn ($q, $k) => $q->where('kind', $k))
            ->when($data['location_id'] ?? null, fn ($q, $id) => $q->where('location_id', $id))
            ->orderByDesc('id')
            ->paginate(50);
        $page->getCollection()->transform(fn (StockEntry $e) => $this->summary($e));

        return response()->json($page);
    }

    public function show(Request $request, StockEntry $entry): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->access->ensureCanView($user, $entry->location_id);

        return response()->json(['data' => $this->present($entry, $user)]);
    }

    public function storeOpening(Request $request): JsonResponse
    {
        $data = $request->validate(StockEntryService::openingRules());
        $entry = $this->entries->opening($data, $request->user());

        return response()->json(['data' => $this->present($entry, $request->user())], 201);
    }

    public function storeWaste(Request $request): JsonResponse
    {
        $data = $request->validate(StockEntryService::wasteRules(), ['notes.required' => 'Tuliskan keterangan barang yang dibuang.']);
        $entry = $this->entries->waste($data, $request->user());

        return response()->json(['data' => $this->present($entry, $request->user())], 201);
    }

    /** @return array<string, mixed> */
    private function summary(StockEntry $e): array
    {
        return [
            'id' => $e->id,
            'number' => $e->number,
            'kind' => $e->kind,
            'location' => $this->locationRef($e->location),
            'reason_code' => $e->reason_code,
            'notes' => $e->notes,
            'created_by' => $this->userRef($e->creator),
            'created_at' => $e->created_at,
            'line_count' => $e->movements_count ?? $e->movements()->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function present(StockEntry $e, User $viewer): array
    {
        $seeCost = $this->access->canSeeCost($viewer);
        $e->load(['location', 'creator:id,name,username', 'movements.item.baseUnit', 'movements.batch', 'movements.location', 'movements.user']);

        return [
            ...$this->summary($e),
            'movements' => $e->movements->map(fn (StockMovement $m) => $this->movementRow($m, $seeCost))->values(),
            'can_see_cost' => $seeCost,
        ];
    }
}
