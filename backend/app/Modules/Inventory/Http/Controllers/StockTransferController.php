<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\StockTransferLine;
use App\Modules\Inventory\Services\StockAccess;
use App\Modules\Inventory\Services\StockTransferService;
use App\Support\Decimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Pengiriman gudang → outlet dan retur outlet → gudang (AB-13; OD-18; G-03). */
class StockTransferController
{
    use Presents;

    public function __construct(
        private readonly StockTransferService $transfers,
        private readonly StockAccess $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['OPEN', 'NEEDS_EXPLANATION', 'DONE', StockTransfer::IN_TRANSIT, StockTransfer::RECEIVED, StockTransfer::CANCELLED])],
            'kind' => ['nullable', Rule::in([StockTransfer::KIND_DISTRIBUTION, StockTransfer::KIND_RETURN])],
            'location_id' => ['nullable', 'integer'],
        ]);
        /** @var User $user */
        $user = $request->user();
        $ids = $this->access->viewableLocationIds($user);
        $needsExplanation = fn ($q) => $q->whereHas('lines', fn ($l) => $l->whereNotNull('received_qty')->whereColumn('received_qty', '<>', 'sent_qty')->whereNull('resolution'));

        $page = StockTransfer::query()->with(['fromLocation', 'toLocation', 'sender:id,name,username', 'receiver:id,name,username', 'stockRequest:id,number', 'lines'])
            ->when($ids !== null, fn ($q) => $q->where(fn ($w) => $w->whereIn('from_location_id', $ids)->orWhereIn('to_location_id', $ids)))
            ->when($data['kind'] ?? null, fn ($q, $k) => $q->where('kind', $k))
            ->when($data['location_id'] ?? null, fn ($q, $id) => $q->where(fn ($w) => $w->where('from_location_id', $id)->orWhere('to_location_id', $id)))
            ->when($data['status'] ?? null, fn ($q, $s) => match ($s) {
                'OPEN' => $q->where(fn ($w) => $w->where('status', StockTransfer::IN_TRANSIT)->orWhere(fn ($r) => $needsExplanation($r->where('status', StockTransfer::RECEIVED)))),
                'NEEDS_EXPLANATION' => $needsExplanation($q->where('status', StockTransfer::RECEIVED)),
                'DONE' => $q->where('status', '<>', StockTransfer::IN_TRANSIT)->whereDoesntHave('lines', fn ($l) => $l->whereNotNull('received_qty')->whereColumn('received_qty', '<>', 'sent_qty')->whereNull('resolution')),
                default => $q->where('status', $s),
            })
            ->orderByRaw("CASE WHEN status = 'IN_TRANSIT' THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->paginate(50);
        $page->getCollection()->transform(fn (StockTransfer $t) => $this->summary($t, $user));

        return response()->json($page);
    }

    public function show(Request $request, StockTransfer $transfer): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->ensureVisible($user, $transfer);

        return response()->json(['data' => $this->present($transfer, $user)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(StockTransferService::rules());
        $t = $this->transfers->send($data, $request->user());

        return response()->json(['data' => $this->present($t, $request->user())], 201);
    }

    public function receive(Request $request, StockTransfer $transfer): JsonResponse
    {
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.line_id' => ['required', 'integer', 'distinct'],
            'lines.*.received_qty' => ['required', 'numeric', 'min:0', 'max:99999999'],
        ]);
        $t = $this->transfers->receive($transfer, $data['lines'], $data['note'] ?? null, $request->user());

        return response()->json(['data' => $this->present($t, $request->user())]);
    }

    public function resolve(Request $request, StockTransfer $transfer, StockTransferLine $line): JsonResponse
    {
        $data = $request->validate([
            'resolution' => ['required', Rule::in([StockTransferLine::SHORT_SHIPPED, StockTransferLine::LOST_IN_TRANSIT, StockTransferLine::OVER_SENT])],
            'note' => ['required', 'string', 'max:500'],
        ], ['note.required' => 'Tuliskan penjelasan selisihnya.', 'resolution.required' => 'Pilih penjelasan selisih.']);
        $t = $this->transfers->resolve($transfer, $line, $data['resolution'], $data['note'], $request->user());

        return response()->json(['data' => $this->present($t, $request->user())]);
    }

    public function cancel(Request $request, StockTransfer $transfer): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:500']], ['note.required' => 'Tuliskan alasan pembatalan.']);
        $t = $this->transfers->cancel($transfer, $data['note'], $request->user());

        return response()->json(['data' => $this->present($t, $request->user())]);
    }

    private function ensureVisible(User $user, StockTransfer $t): void
    {
        if (! $this->access->canView($user, $t->from_location_id) && ! $this->access->canView($user, $t->to_location_id)) {
            throw new AuthorizationException('Anda tidak memiliki izin untuk melihat pengiriman ini.');
        }
    }

    /** @return array<string, mixed> */
    private function summary(StockTransfer $t, User $viewer): array
    {
        $t->loadMissing(['fromLocation', 'toLocation', 'lines']);
        $pending = $t->lines->filter(fn (StockTransferLine $l) => $l->needsResolution())->count();
        $inTransit = $t->status === StockTransfer::IN_TRANSIT;

        return [
            'id' => $t->id,
            'number' => $t->number,
            'kind' => $t->kind,
            'status' => $t->status,
            'from_location' => $this->locationRef($t->fromLocation),
            'to_location' => $this->locationRef($t->toLocation),
            'stock_request' => $t->stockRequest ? ['id' => $t->stockRequest->id, 'number' => $t->stockRequest->number] : null,
            'reason_code' => $t->reason_code,
            'notes' => $t->notes,
            'sent_by' => $this->userRef($t->sender),
            'sent_at' => $t->sent_at,
            'received_by' => $this->userRef($t->receiver),
            'received_at' => $t->received_at,
            'line_count' => $t->lines->count(),
            'unexplained_count' => $pending,
            'can' => [
                'receive' => $inTransit && $t->sent_by !== $viewer->id && $this->access->isOperator($viewer, $t->toLocation),
                'cancel' => $inTransit && $this->access->isOperator($viewer, $t->fromLocation),
                'resolve' => $pending > 0 && $this->access->isOperator($viewer, $t->fromLocation),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function present(StockTransfer $t, User $viewer): array
    {
        $t = $t->fresh(['fromLocation', 'toLocation', 'sender:id,name,username', 'receiver:id,name,username', 'canceller:id,name,username', 'stockRequest:id,number', 'lines.item.baseUnit', 'lines.unit', 'lines.resolver:id,name,username']);
        $seeCost = $this->access->canSeeCost($viewer);

        return [
            ...$this->summary($t, $viewer),
            'receive_note' => $t->receive_note,
            'cancelled_by' => $this->userRef($t->canceller),
            'cancelled_at' => $t->cancelled_at,
            'cancel_note' => $t->cancel_note,
            'can_see_cost' => $seeCost,
            'lines' => $t->lines->map(function (StockTransferLine $l) use ($seeCost) {
                $diff = $l->difference();

                return [
                    'id' => $l->id,
                    'item' => $this->itemRef($l->item),
                    'unit' => $this->unitRef($l->unit),
                    'factor' => $l->factor,
                    'sent_qty' => $l->sent_qty,
                    'sent_entered' => $this->inUnit($l->sent_qty, $l->factor),
                    'received_qty' => $l->received_qty,
                    'received_entered' => $this->inUnit($l->received_qty, $l->factor),
                    'difference' => $diff,
                    'unit_cost' => $seeCost ? $l->unit_cost : null,
                    'difference_value' => $seeCost && $diff !== null && $l->unit_cost !== null ? Decimal::mul($diff, (string) $l->unit_cost, 2) : null,
                    'needs_resolution' => $l->needsResolution(),
                    'resolution' => $l->resolution,
                    'resolution_note' => $l->resolution_note,
                    'resolved_by' => $this->userRef($l->resolver),
                    'resolved_at' => $l->resolved_at,
                ];
            })->values(),
        ];
    }
}
