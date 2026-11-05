<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Approval\Models\ApprovalRequest;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\StockAccess;
use App\Modules\Inventory\Services\StockCountService;
use App\Modules\Location\Models\Location;
use App\Support\Decimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Stock opname; keputusan Owner lewat modul Persetujuan (OD-21). */
class StockCountController
{
    use Presents;

    public function __construct(
        private readonly StockCountService $counts,
        private readonly StockAccess $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['location_id' => ['nullable', 'integer']]);
        /** @var User $user */
        $user = $request->user();
        $ids = $this->access->viewableLocationIds($user);

        $page = StockCount::query()->with(['location', 'creator:id,name,username', 'approval'])
            ->when($ids !== null, fn ($q) => $q->whereIn('location_id', $ids))
            ->when($data['location_id'] ?? null, fn ($q, $id) => $q->where('location_id', $id))
            ->orderByDesc('id')
            ->paginate(50);
        $page->getCollection()->transform(fn (StockCount $c) => $this->summary($c));

        return response()->json($page);
    }

    public function show(Request $request, StockCount $count): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->access->ensureCanView($user, $count->location_id);

        return response()->json(['data' => $this->present($count, $user)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['location_id' => ['required', 'integer', 'exists:locations,id'], ...StockCountService::rules()]);
        $count = $this->counts->submit(Location::query()->findOrFail($data['location_id']), $data, $request->user());

        return response()->json(['data' => $this->present($count->fresh(), $request->user())], 201);
    }

    /** @return array<string, mixed> */
    private function summary(StockCount $c): array
    {
        $lines = $c->approval?->payload['lines'] ?? [];

        return [
            'id' => $c->id,
            'number' => $c->number,
            'location' => $this->locationRef($c->location),
            'status' => $c->approval?->status,
            'approval_id' => $c->approval_request_id,
            'created_by' => $this->userRef($c->creator),
            'created_at' => $c->created_at,
            'applied_at' => $c->applied_at,
            'line_count' => count($lines),
            'difference_count' => count(array_filter($lines, fn ($l) => ! Decimal::isZero((string) $l['difference']))),
        ];
    }

    /** @return array<string, mixed> */
    private function present(StockCount $c, User $viewer): array
    {
        $c->load(['location', 'creator:id,name,username', 'approval.decider:id,name,username']);
        $seeCost = $this->access->canSeeCost($viewer);
        $approval = $c->approval;
        $payload = $approval?->payload ?? ['lines' => []];
        $items = Item::query()->with('baseUnit')->whereIn('id', array_column($payload['lines'], 'item_id'))->get()->keyBy('id');
        $units = Unit::query()->whereIn('id', array_column($payload['lines'], 'unit_id'))->get()->keyBy('id');
        $avg = $seeCost ? StockBalance::query()->where('location_id', $c->location_id)->pluck('avg_cost', 'item_id') : collect();
        $applied = $c->applied_at
            ? StockMovement::query()->where(['source_type' => 'stock_count', 'source_id' => $c->id])->get()->groupBy('item_id')
            : collect();

        $total = '0';
        $lines = [];
        foreach ($payload['lines'] as $l) {
            $value = null;
            if ($seeCost) {
                if ($applied->has($l['item_id'])) {
                    $sum = $applied[$l['item_id']]->sum(fn ($m) => (float) $m->value);
                    $value = Decimal::round((string) $sum, 2);
                } elseif (($cost = $avg->get($l['item_id'])) !== null) {
                    $value = Decimal::mul((string) $l['difference'], (string) $cost, 2);
                }
                if ($value !== null) {
                    $total = bcadd($total, $value, 2);
                }
            }
            $lines[] = [
                'item' => $this->itemRef($items->get($l['item_id'])),
                'unit' => $this->unitRef($units->get($l['unit_id'])),
                'unit_id' => $l['unit_id'],
                'counted_entered' => $l['counted_entered'],
                'counted_qty' => $l['counted_qty'],
                'system_qty' => $l['system_qty'],
                'difference' => $l['difference'],
                'value' => $value,
            ];
        }

        return [
            ...$this->summary($c),
            'counted_at' => $payload['counted_at'] ?? null,
            'reason' => $approval?->reason,
            'decider' => $this->userRef($approval?->decider),
            'decided_at' => $approval?->decided_at,
            'decision_note' => $approval?->decision_note,
            'lines' => $lines,
            'total_value' => $seeCost ? $total : null,
            'value_is_estimate' => $seeCost && ! $c->applied_at,
            'can_see_cost' => $seeCost,
            'can' => [
                'resubmit' => $approval?->status === ApprovalRequest::STATUS_RETURNED && $approval->requested_by === $viewer->id,
                'cancel' => $approval?->isOpen() && $approval->requested_by === $viewer->id,
                'decide' => $approval?->status === ApprovalRequest::STATUS_PENDING && $approval->requested_by !== $viewer->id && $viewer->isOwner(),
            ],
        ];
    }
}
