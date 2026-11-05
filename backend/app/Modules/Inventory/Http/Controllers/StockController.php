<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\ItemMinStock;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\StockAccess;
use App\Modules\Location\Models\Location;
use App\Support\Decimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Saldo stok, kartu stok, dan batch per lokasi (AB-03, AB-05, AB-07). */
class StockController
{
    use Presents;

    public function __construct(private readonly StockAccess $access) {}

    /** Lokasi yang stoknya boleh dilihat user, dan tindakan yang boleh dilakukannya di sana. */
    public function locations(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $ids = $this->access->viewableLocationIds($user);
        $canOpening = $user->hasActiveRole(Role::OWNER) || $user->hasActiveRole(Role::FINANCE);

        $rows = Location::query()
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->orderByRaw("CASE WHEN type = 'WAREHOUSE' THEN 0 ELSE 1 END")->orderBy('name')
            ->get()
            ->map(fn (Location $l) => [
                ...$this->locationRef($l),
                'can' => [
                    'operate' => $l->status !== Location::STATUS_CLOSED && $this->access->isOperator($user, $l),
                    'request_stock' => $l->isOutlet() && $l->status === Location::STATUS_ACTIVE && $user->hasRoleAt(Role::CASHIER, $l->id),
                    'opening' => $canOpening && $l->status !== Location::STATUS_CLOSED,
                ],
            ]);

        return response()->json(['data' => $rows, 'can_see_cost' => $this->access->canSeeCost($user)]);
    }

    public function balances(Request $request): JsonResponse
    {
        $data = $request->validate([
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::in([Item::TYPE_RAW, Item::TYPE_SEMI_FINISHED, Item::TYPE_SUPPORTING, Item::TYPE_PACKAGING])],
            'only' => ['nullable', Rule::in(['low', 'negative', 'with_stock', 'relevant'])],
        ]);
        /** @var User $user */
        $user = $request->user();
        $locationId = (int) $data['location_id'];
        $this->access->ensureCanView($user, $locationId);
        $seeCost = $this->access->canSeeCost($user);

        $balances = StockBalance::query()->where('location_id', $locationId)->get()->keyBy('item_id');
        $mins = ItemMinStock::query()->where('location_id', $locationId)->pluck('min_qty', 'item_id');

        $items = Item::query()->with('baseUnit:id,code,name')
            ->where('item_type', '<>', Item::TYPE_MENU)
            ->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $balances->keys()))
            ->when($data['type'] ?? null, fn ($q, $t) => $q->where('item_type', $t))
            ->when($data['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'ilike', "%{$s}%")->orWhere('code', 'ilike', "%{$s}%")))
            ->orderBy('name')
            ->get();

        $rows = [];
        $totalValue = '0';
        foreach ($items as $item) {
            $b = $balances->get($item->id);
            $qty = $b ? (string) $b->qty : '0';
            $min = $mins->get($item->id);
            $negative = Decimal::cmp($qty, '0') < 0;
            $low = $min !== null && Decimal::cmp($qty, (string) $min) < 0;
            $value = $seeCost && $b?->avg_cost !== null && Decimal::cmp($qty, '0') > 0 ? Decimal::mul($qty, (string) $b->avg_cost, 2) : null;
            if ($value !== null) {
                $totalValue = bcadd($totalValue, $value, 2);
            }
            $row = [
                'item' => $this->itemRef($item),
                'qty' => $qty,
                'min_qty' => $min === null ? null : (string) $min,
                'is_negative' => $negative,
                'is_low' => $low,
                'has_movements' => $b !== null && $b->last_movement_at !== null,
                'last_movement_at' => $b?->last_movement_at,
                'avg_cost' => $seeCost ? $b?->avg_cost : null,
                'value' => $value,
            ];
            $keep = match ($data['only'] ?? null) {
                'low' => $low || $negative,
                'negative' => $negative,
                'with_stock' => ! Decimal::isZero($qty),
                // Barang yang pernah bergerak, punya saldo, atau punya stok minimum di lokasi ini.
                'relevant' => $row['has_movements'] || $min !== null || ! Decimal::isZero($qty),
                default => true,
            };
            if ($keep) {
                $rows[] = $row;
            }
        }

        return response()->json([
            'data' => $rows,
            'summary' => [
                'item_count' => count($rows),
                'low_count' => count(array_filter($rows, fn ($r) => $r['is_low'] && ! $r['is_negative'])),
                'negative_count' => count(array_filter($rows, fn ($r) => $r['is_negative'])),
                'total_value' => $seeCost ? $totalValue : null,
            ],
            'can_see_cost' => $seeCost,
        ]);
    }

    /** Kartu stok satu barang di satu lokasi, terbaru di atas. */
    public function movements(Request $request): JsonResponse
    {
        $data = $request->validate([
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'item_id' => ['nullable', 'integer', 'exists:items,id'],
            'type' => ['nullable', 'string', 'max:30'],
        ]);
        /** @var User $user */
        $user = $request->user();
        $this->access->ensureCanView($user, (int) $data['location_id']);
        $seeCost = $this->access->canSeeCost($user);

        $page = StockMovement::query()
            ->with(['item.baseUnit', 'location', 'batch', 'user:id,name,username'])
            ->where('location_id', $data['location_id'])
            ->when($data['item_id'] ?? null, fn ($q, $id) => $q->where('item_id', $id))
            ->when($data['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->orderByDesc('id')
            ->paginate(50);
        $page->getCollection()->transform(fn (StockMovement $m) => $this->movementRow($m, $seeCost));

        $balance = isset($data['item_id'])
            ? StockBalance::query()->where(['location_id' => $data['location_id'], 'item_id' => $data['item_id']])->first()
            : null;

        return response()->json([
            ...$page->toArray(),
            'balance' => $balance ? ['qty' => $balance->qty, 'avg_cost' => $seeCost ? $balance->avg_cost : null] : null,
            'can_see_cost' => $seeCost,
        ]);
    }

    /** Batch gudang yang masih punya sisa, paling dekat kedaluwarsa lebih dulu (AB-05). */
    public function batches(Request $request): JsonResponse
    {
        $data = $request->validate([
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'item_id' => ['nullable', 'integer', 'exists:items,id'],
        ]);
        $this->access->ensureCanView($request->user(), (int) $data['location_id']);

        $rows = StockBatch::query()
            ->where('location_id', $data['location_id'])
            ->when($data['item_id'] ?? null, fn ($q, $id) => $q->where('item_id', $id))
            ->where('qty_remaining', '>', 0)
            ->orderByRaw('expiry_date IS NULL, expiry_date')->orderBy('id')
            ->get()
            ->map(fn (StockBatch $b) => [
                'id' => $b->id,
                'item_id' => $b->item_id,
                'batch_no' => $b->batch_no,
                'expiry_date' => $b->expiry_date?->format('Y-m-d'),
                'qty_remaining' => $b->qty_remaining,
                'source' => $b->source,
            ]);

        return response()->json(['data' => $rows]);
    }
}
