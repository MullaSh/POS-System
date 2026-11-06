<?php

namespace App\Modules\Cash\Http\Controllers;

use App\Modules\Cash\Models\CashHandover;
use App\Modules\Cash\Models\CashHandoverItem;
use App\Modules\Cash\Services\CashAccess;
use App\Modules\Cash\Services\HandoverService;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Location\Models\Location;
use App\Modules\Sales\Models\CashierShift;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Serah terima Sales Cash Outlet → Gudang → Finance. */
class HandoverController
{
    public function __construct(
        private readonly HandoverService $handovers,
        private readonly CashAccess $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in([CashHandover::STATUS_SENT, CashHandover::STATUS_RECEIVED])],
            'stage' => ['nullable', Rule::in([CashHandover::STAGE_OUTLET, CashHandover::STAGE_WAREHOUSE])],
        ]);
        /** @var User $user */
        $user = $request->user();
        $ids = $this->access->locationIds($user);
        $page = CashHandover::query()->with(['fromLocation', 'toLocation', 'sender:id,name,username', 'receiver:id,name,username'])
            ->when($ids !== null, fn ($q) => $q->where(fn ($w) => $w->whereIn('from_location_id', $ids)->orWhereIn('to_location_id', $ids)))
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($data['stage'] ?? null, fn ($q, $s) => $q->where('stage', $s))
            ->orderByDesc('sent_at')->orderByDesc('id')->paginate(50);
        $page->getCollection()->transform(fn (CashHandover $h) => $this->present($h, $user));

        return response()->json($page);
    }

    public function show(Request $request, CashHandover $handover): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->ensureVisible($user, $handover);

        return response()->json(['data' => $this->present($handover, $user, true)]);
    }

    /** Yang siap diserahkan: shift outlet yang sudah ditutup, atau kas yang ada di gudang. */
    public function pending(Request $request): JsonResponse
    {
        $data = $request->validate(['location_id' => ['required', 'integer', 'exists:locations,id']]);
        /** @var User $user */
        $user = $request->user();
        $location = Location::query()->findOrFail($data['location_id']);
        if (! $this->access->canSee($user, $location->id)) {
            throw new AuthorizationException('Anda tidak memiliki izin untuk melihat kas lokasi ini.');
        }
        if ($location->type === Location::TYPE_OUTLET) {
            $shifts = $this->handovers->pendingShifts($location);

            return response()->json(['data' => [
                'location' => $location->only(['id', 'code', 'name', 'type']),
                'can_send' => $user->hasRoleAt(Role::CASHIER, $location->id),
                'shifts' => $shifts->map(fn (CashierShift $s) => [
                    'id' => $s->id, 'number' => $s->number, 'cashier' => $s->cashier?->only(['id', 'name']),
                    'closed_at' => $s->closed_at, 'sales_cash' => $s->sales_cash,
                ])->values(),
                'handovers' => [],
                'total' => (int) $shifts->sum('sales_cash'),
            ]]);
        }
        $list = $this->handovers->pendingAtWarehouse();

        return response()->json(['data' => [
            'location' => $location->only(['id', 'code', 'name', 'type']),
            'can_send' => $user->hasRoleAt(Role::WAREHOUSE, $location->id),
            'shifts' => [],
            'handovers' => $list->map(fn (CashHandover $h) => [
                'id' => $h->id, 'number' => $h->number, 'from' => $h->fromLocation?->only(['id', 'name']),
                'received_at' => $h->received_at, 'received_amount' => $h->received_amount,
            ])->values(),
            'total' => (int) $list->sum('received_amount'),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'shift_ids' => ['array', 'max:100'],
            'shift_ids.*' => ['integer', 'distinct'],
            'handover_ids' => ['array', 'max:200'],
            'handover_ids.*' => ['integer', 'distinct'],
            'reported_amount' => ['required', 'integer', 'min:0', 'max:10000000000'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], ['reported_amount.required' => 'Isi jumlah uang yang diserahkan.']);
        $location = Location::query()->findOrFail($data['location_id']);
        $isOutlet = $location->type === Location::TYPE_OUTLET;
        $key = $isOutlet ? 'shift_ids' : 'handover_ids';
        if (empty($data[$key])) {
            return response()->json(['message' => 'Pilih yang diserahkan.', 'errors' => [$key => [$isOutlet ? 'Pilih minimal satu shift.' : 'Pilih minimal satu serah terima dari outlet.']]], 422);
        }
        $handover = $isOutlet
            ? $this->handovers->sendFromOutlet($data, $request->user())
            : $this->handovers->sendFromWarehouse($data, $request->user());

        return response()->json(['data' => $this->present($handover, $request->user(), true)], 201);
    }

    public function receive(Request $request, CashHandover $handover): JsonResponse
    {
        $data = $request->validate([
            'received_amount' => ['required', 'integer', 'min:0', 'max:10000000000'],
            'note' => ['nullable', 'string', 'max:1000'],
            'evidence_note' => ['nullable', 'string', 'max:1000'],
        ], ['received_amount.required' => 'Isi jumlah uang yang Anda hitung saat menerima.']);
        $handover = $this->handovers->receive($handover, $data, $request->user());

        return response()->json(['data' => $this->present($handover->fresh(), $request->user(), true)]);
    }

    private function ensureVisible(User $user, CashHandover $h): void
    {
        if (! $this->access->canSee($user, $h->from_location_id) && ! ($h->to_location_id && $this->access->canSee($user, $h->to_location_id))) {
            throw new AuthorizationException('Anda tidak memiliki izin untuk melihat serah terima ini.');
        }
    }

    /** @return array<string, mixed> */
    private function present(CashHandover $h, User $viewer, bool $detail = false): array
    {
        $h->loadMissing(['fromLocation', 'toLocation', 'sender:id,name,username', 'receiver:id,name,username']);
        $canReceive = $h->status === CashHandover::STATUS_SENT && $h->sender_id !== $viewer->id && (
            $h->stage === CashHandover::STAGE_OUTLET ? $viewer->hasRoleAt(Role::WAREHOUSE, (int) $h->to_location_id) : $viewer->hasActiveRole(Role::FINANCE)
        );

        return [
            'id' => $h->id,
            'number' => $h->number,
            'stage' => $h->stage,
            'status' => $h->status,
            'from' => $h->fromLocation?->only(['id', 'code', 'name', 'type']),
            'to' => $h->toLocation?->only(['id', 'code', 'name', 'type']),
            'expected_amount' => $h->expected_amount,
            'reported_amount' => $h->reported_amount,
            'received_amount' => $h->received_amount,
            'difference' => $h->difference(),
            'sender' => $h->sender?->only(['id', 'name', 'username']),
            'sent_at' => $h->sent_at,
            'sender_note' => $h->sender_note,
            'receiver' => $h->receiver?->only(['id', 'name', 'username']),
            'received_at' => $h->received_at,
            'receiver_note' => $h->receiver_note,
            'evidence_note' => $h->evidence_note,
            'items' => $detail ? $h->items()->with(['shift.cashier:id,name', 'source.fromLocation'])->get()->map(fn (CashHandoverItem $i) => [
                'amount' => $i->amount,
                'shift' => $i->shift ? ['id' => $i->shift->id, 'number' => $i->shift->number, 'cashier' => $i->shift->cashier?->name, 'closed_at' => $i->shift->closed_at] : null,
                'source' => $i->source ? ['id' => $i->source->id, 'number' => $i->source->number, 'from' => $i->source->fromLocation?->name] : null,
            ])->values() : null,
            'can' => ['receive' => $canReceive],
        ];
    }
}
