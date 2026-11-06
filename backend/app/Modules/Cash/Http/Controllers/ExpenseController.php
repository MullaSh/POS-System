<?php

namespace App\Modules\Cash\Http\Controllers;

use App\Modules\Cash\Models\Expense;
use App\Modules\Cash\Models\PettyCashMovement;
use App\Modules\Cash\Models\PettyCashRefill;
use App\Modules\Cash\Services\CashAccess;
use App\Modules\Cash\Services\PettyCashService;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Location\Models\Location;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Biaya operasional dan Petty Cash outlet. */
class ExpenseController
{
    public function __construct(
        private readonly PettyCashService $petty,
        private readonly CashAccess $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'location_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['RECORDED', 'VERIFIED', 'REJECTED', 'CANCELLED'])],
            'month' => ['nullable', 'date_format:Y-m'],
        ]);
        /** @var User $user */
        $user = $request->user();
        $ids = $this->access->locationIds($user);
        $page = Expense::query()->with(['location', 'category', 'recorder:id,name,username', 'decider:id,name,username'])
            ->when($ids !== null, fn ($q) => $q->whereIn('location_id', $ids))
            ->when($data['location_id'] ?? null, fn ($q, $id) => $q->where('location_id', $id))
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($data['month'] ?? null, fn ($q, $m) => $q->whereRaw("to_char(business_date, 'YYYY-MM') = ?", [$m]))
            ->orderByDesc('id')->paginate(50);
        $page->getCollection()->transform(fn (Expense $e) => $this->present($e, $user));

        return response()->json($page);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(PettyCashService::expenseRules(), [
            'description.required' => 'Tuliskan untuk apa biaya ini.',
            'amount.required' => 'Isi jumlah biaya.',
        ]);
        $expense = $this->petty->recordExpense($data, $request->user());

        return response()->json(['data' => $this->present($expense->fresh(), $request->user())], 201);
    }

    public function cancel(Request $request, Expense $expense): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']], ['reason.required' => 'Tuliskan alasan pembatalan.']);
        $expense = $this->petty->cancelExpense($expense, $data['reason'], $request->user());

        return response()->json(['data' => $this->present($expense->fresh(), $request->user())]);
    }

    /** Petty Cash satu outlet: saldo, saldo tetap, kartu, biaya yang belum diperiksa, dan permintaan isi ulang. */
    public function pettyCash(Request $request, Location $location): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if ($location->type !== Location::TYPE_OUTLET) {
            return response()->json(['message' => 'Petty Cash hanya untuk outlet.'], 404);
        }
        if (! $this->access->canSee($user, $location->id)) {
            throw new AuthorizationException('Anda tidak memiliki izin untuk melihat Petty Cash outlet ini.');
        }
        $request_ = PettyCashRefill::query()->where(['location_id' => $location->id, 'status' => PettyCashRefill::STATUS_REQUESTED])->with('requester:id,name')->first();
        $movements = PettyCashMovement::query()->where('location_id', $location->id)->with('user:id,name')->orderByDesc('id')->limit(100)->get();

        return response()->json(['data' => [
            'location' => $location->only(['id', 'code', 'name']),
            'target' => $location->petty_cash_amount,
            'balance' => $this->petty->balance($location->id),
            'pending' => $this->petty->pendingExpenses($location->id)->map(fn (Expense $e) => $this->present($e, $user))->values(),
            'request' => $request_ ? ['id' => $request_->id, 'number' => $request_->number, 'by' => $request_->requester?->name, 'at' => $request_->requested_at, 'note' => $request_->request_note] : null,
            'movements' => $movements->map(fn (PettyCashMovement $m) => [
                'id' => $m->id, 'type' => $m->type, 'amount' => $m->amount, 'balance_after' => $m->balance_after,
                'source' => ['type' => $m->source_type, 'id' => $m->source_id, 'number' => $m->source_number],
                'user' => $m->user?->name, 'created_at' => $m->created_at,
            ])->values(),
            'can' => [
                'record' => $user->hasRoleAt(Role::CASHIER, $location->id),
                'request' => $user->hasRoleAt(Role::CASHIER, $location->id) && ! $request_,
                'refill' => $user->hasActiveRole(Role::FINANCE) && $location->petty_cash_amount !== null,
                'set_target' => $user->hasActiveRole(Role::OWNER),
            ],
        ]]);
    }

    public function requestRefill(Request $request, Location $location): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $this->petty->requestRefill($location, $data['note'] ?? null, $request->user());

        return $this->pettyCash($request, $location->fresh());
    }

    public function refill(Request $request, Location $location): JsonResponse
    {
        $data = $request->validate([
            'decisions' => ['present', 'array'],
            'decisions.*.decision' => ['required', Rule::in([Expense::STATUS_VERIFIED, Expense::STATUS_REJECTED])],
            'decisions.*.note' => ['nullable', 'string', 'max:500'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $this->petty->refill($location, $data['decisions'], $data['note'] ?? null, $request->user());

        return $this->pettyCash($request, $location->fresh());
    }

    public function setTarget(Request $request, Location $location): JsonResponse
    {
        $data = $request->validate(['amount' => ['required', 'integer', 'min:0', 'max:100000000']]);
        $this->petty->setTarget($location, (int) $data['amount'], $request->user());

        return $this->pettyCash($request, $location->fresh());
    }

    /** @return array<string, mixed> */
    private function present(Expense $e, User $viewer): array
    {
        $e->loadMissing(['location', 'category', 'recorder:id,name,username', 'decider:id,name,username']);

        return [
            'id' => $e->id,
            'number' => $e->number,
            'location' => $e->location?->only(['id', 'code', 'name', 'type']),
            'category' => $e->category?->only(['id', 'code', 'name']),
            'amount' => $e->amount,
            'description' => $e->description,
            'evidence_note' => $e->evidence_note,
            'paid_from' => $e->paid_from,
            'status' => $e->status,
            'business_date' => $e->business_date?->format('Y-m-d'),
            'recorded_by' => $e->recorder?->only(['id', 'name', 'username']),
            'created_at' => $e->created_at,
            'decided_by' => $e->decider?->only(['id', 'name', 'username']),
            'decided_at' => $e->decided_at,
            'decision_note' => $e->decision_note,
            'can' => ['cancel' => $e->status === Expense::STATUS_RECORDED && $e->paid_from === Expense::PAID_PETTY && $e->recorded_by === $viewer->id],
        ];
    }
}
