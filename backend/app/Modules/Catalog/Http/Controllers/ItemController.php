<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\ItemPrice;
use App\Modules\Catalog\Services\ItemService;
use App\Modules\Catalog\Services\PriceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ItemController
{
    public function __construct(
        private readonly ItemService $items,
        private readonly PriceService $prices,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Item::query()
            ->with('baseUnit:id,code,name')
            ->select('items.*')
            ->selectSub(ItemPrice::currentPriceSubquery(), 'current_price')
            ->when($request->query('type'), fn ($q, $t) => $q->where('item_type', $t))
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('name', 'ilike', "%{$s}%")->orWhere('code', 'ilike', "%{$s}%")))
            ->when($request->query('active') !== null, fn ($q) => $q->where('is_active', $request->boolean('active')))
            ->orderBy('name');

        return response()->json($query->paginate(50));
    }

    public function show(Item $item): JsonResponse
    {
        return response()->json(['data' => $this->present($item)]);
    }

    public function store(Request $request): JsonResponse
    {
        // Kode disimpan seragam; samakan sebelum cek keunikan.
        if (is_string($request->input('code'))) {
            $request->merge(['code' => strtoupper(trim($request->input('code')))]);
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9-]+$/', Rule::unique('items', 'code')],
            'name' => ['required', 'string', 'max:150'],
            'item_type' => ['required', Rule::in(Item::TYPES)],
            'base_unit_id' => ['required', 'integer', 'exists:units,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
            ...$this->unitRules(),
        ]);

        $item = $this->items->create($data, $data['units'] ?? []);

        return response()->json(['data' => $this->present($item)], 201);
    }

    public function update(Request $request, Item $item): JsonResponse
    {
        // Jenis dan satuan dasar tidak dapat diubah setelah barang dibuat.
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        return response()->json(['data' => $this->present($this->items->update($item, $data))]);
    }

    public function deactivate(Item $item): JsonResponse
    {
        return response()->json(['data' => $this->present($this->items->setActive($item, false))]);
    }

    public function activate(Item $item): JsonResponse
    {
        return response()->json(['data' => $this->present($this->items->setActive($item, true))]);
    }

    public function updateUnits(Request $request, Item $item): JsonResponse
    {
        $data = $request->validate($this->unitRules(required: true));

        return response()->json(['data' => $this->present($this->items->replaceUnits($item, $data['units']))]);
    }

    public function updateMinStocks(Request $request, Item $item): JsonResponse
    {
        $data = $request->validate([
            'min_stocks' => ['present', 'array'],
            'min_stocks.*.location_id' => ['required', 'integer', 'exists:locations,id'],
            'min_stocks.*.min_qty' => ['required', 'numeric', 'min:0', 'max:99999999999999'],
        ]);

        return response()->json(['data' => $this->present($this->items->replaceMinStocks($item, $data['min_stocks']))]);
    }

    /** @return array<string, list<mixed>> */
    private function unitRules(bool $required = false): array
    {
        return [
            'units' => [$required ? 'present' : 'sometimes', 'array'],
            'units.*.unit_id' => ['required', 'integer', 'exists:units,id'],
            'units.*.factor_to_base' => ['required', 'numeric', 'gt:0', 'max:99999999999999'],
            'units.*.is_purchase_default' => ['boolean'],
            'units.*.is_usage_default' => ['boolean'],
        ];
    }

    /** @return array<string, mixed> */
    private function present(Item $item): array
    {
        $item->load(['baseUnit:id,code,name', 'units.unit:id,code,name', 'minStocks.location:id,code,name,type']);

        return [
            'id' => $item->id,
            'code' => $item->code,
            'name' => $item->name,
            'item_type' => $item->item_type,
            'is_stock_item' => $item->isStockItem(),
            'is_active' => $item->is_active,
            'notes' => $item->notes,
            'base_unit' => $item->baseUnit,
            'units' => $item->units->map(fn ($u) => [
                'unit_id' => $u->unit_id,
                'code' => $u->unit->code,
                'name' => $u->unit->name,
                'factor_to_base' => $u->factor_to_base,
                'is_purchase_default' => $u->is_purchase_default,
                'is_usage_default' => $u->is_usage_default,
            ])->sortBy(fn ($u) => (float) $u['factor_to_base'])->values(),
            'price' => $item->isMenu() ? $this->presentPrice($item) : null,
            'min_stocks' => $item->minStocks->map(fn ($m) => [
                'location_id' => $m->location_id,
                'location_code' => $m->location->code,
                'location_name' => $m->location->name,
                'min_qty' => $m->min_qty,
            ])->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentPrice(Item $item): array
    {
        $row = fn (ItemPrice $p) => [
            'id' => $p->id,
            'price' => $p->price,
            'effective_from' => $p->effective_from,
            'set_by' => $p->setter?->only(['id', 'name']),
            'approval_number' => $p->approvalRequest?->number,
            'reason' => $p->reason,
            'created_at' => $p->created_at,
        ];
        $open = $this->prices->openRequest($item);

        return [
            'current' => ($c = $this->prices->current($item)) ? $row($c) : null,
            'upcoming' => $this->prices->upcoming($item)->map($row)->values(),
            'history' => $item->prices()->with(['setter:id,name', 'approvalRequest:id,number'])->orderByDesc('id')->limit(50)->get()->map($row)->values(),
            'open_request' => $open ? [
                'id' => $open->id,
                'number' => $open->number,
                'status' => $open->status,
                'payload' => $open->payload,
                'requester' => $open->requester?->only(['id', 'name']),
            ] : null,
        ];
    }
}
