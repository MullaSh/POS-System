<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Sales\Models\CashierShift;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SalePayment;
use App\Modules\Sales\Models\SaleRefund;
use App\Modules\Sales\Models\SaleRefundPayment;
use App\Modules\Sales\Services\SalesAccess;
use App\Modules\Sales\Services\ShiftService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Shift kasir: buka, kirim hasil hitung (hitung buta), tutup dengan alasan bila berselisih. */
class ShiftController
{
    use PresentsSales;

    public function __construct(
        private readonly SalesAccess $access,
        private readonly ShiftService $shifts,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'location_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['RUNNING', CashierShift::STATUS_CLOSED])],
        ]);
        /** @var User $user */
        $user = $request->user();
        $all = $this->access->canSeeAll($user);

        $page = CashierShift::query()->with(['location', 'cashier:id,name,username'])->withCount('sales')
            ->when(! $all, fn ($q) => $q->where('cashier_id', $user->id))
            ->when($data['location_id'] ?? null, fn ($q, $id) => $q->where('location_id', $id))
            ->when(($data['status'] ?? null) === 'RUNNING', fn ($q) => $q->whereIn('status', CashierShift::RUNNING))
            ->when(($data['status'] ?? null) === CashierShift::STATUS_CLOSED, fn ($q) => $q->where('status', CashierShift::STATUS_CLOSED))
            ->orderByDesc('opened_at')->orderByDesc('id')
            ->paginate(50);
        $page->getCollection()->transform(fn (CashierShift $s) => [...$this->shiftSummary($s, $all), 'sale_count' => $s->sales_count]);

        return response()->json($page);
    }

    public function show(Request $request, CashierShift $shift): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if (! $this->access->canViewShift($user, $shift)) {
            throw new AuthorizationException('Shift dan kas hanya dilihat kasir pemiliknya, Owner, dan Finance.');
        }

        return response()->json(['data' => $this->present($shift, $user)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'float_counted' => ['required', 'integer', 'min:0', 'max:100000000'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $shift = $this->shifts->open($data, $request->user());

        return response()->json(['data' => $this->present($shift, $request->user())], 201);
    }

    public function count(Request $request, CashierShift $shift): JsonResponse
    {
        $data = $request->validate(['counted_cash' => ['required', 'integer', 'min:0', 'max:1000000000']],
            ['counted_cash.required' => 'Isi jumlah seluruh uang tunai di laci.']);
        $shift = $this->shifts->count($shift, (int) $data['counted_cash'], $request->user());

        return response()->json(['data' => $this->present($shift->fresh(), $request->user())]);
    }

    public function close(Request $request, CashierShift $shift): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']], ['reason.required' => 'Tuliskan penjelasan selisih uang.']);
        $shift = $this->shifts->close($shift, $data['reason'], $request->user());

        return response()->json(['data' => $this->present($shift->fresh(), $request->user())]);
    }

    /** @return array<string, mixed> */
    private function present(CashierShift $shift, User $viewer): array
    {
        $all = $this->access->canSeeAll($viewer);
        $showCash = $all || $shift->status !== CashierShift::STATUS_OPEN;

        $salesQuery = Sale::query()->where('shift_id', $shift->id);
        $methods = SalePayment::query()->with('method:id,name')
            ->whereIn('sale_id', (clone $salesQuery)->select('id'))
            ->selectRaw('payment_method_id, BOOL_OR(is_cash) AS is_cash, SUM(amount) AS amount, COUNT(*) AS count')
            ->groupBy('payment_method_id')->get();
        $refundOut = SaleRefundPayment::query()->with('method:id,name')
            ->whereHas('refund', fn ($q) => $q->where('shift_id', $shift->id)->whereNotNull('applied_at'))
            ->selectRaw('payment_method_id, SUM(amount) AS amount')->groupBy('payment_method_id')->get()->keyBy('payment_method_id');
        $pending = SaleRefund::query()->where('shift_id', $shift->id)->whereNull('applied_at')
            ->whereHas('approval', fn ($q) => $q->whereIn('status', ['PENDING', 'RETURNED']))->get(['id', 'number', 'sale_id']);

        return [
            ...$this->shiftSummary($shift, $all),
            'is_mine' => $shift->cashier_id === $viewer->id,
            'sale_count' => (clone $salesQuery)->count(),
            // Rincian per metode tidak ditampilkan ke kasir sebelum hasil hitung dikirim (hitung buta).
            'totals' => $showCash ? [
                'sales' => (int) (clone $salesQuery)->sum('total'),
                'cash_in' => $this->shifts->cashIn($shift),
                'cash_out' => $this->shifts->cashOut($shift),
                'by_method' => $methods->map(fn ($m) => [
                    'payment_method_id' => $m->payment_method_id,
                    'name' => $m->method?->name,
                    'is_cash' => (bool) $m->is_cash,
                    'amount' => (int) $m->amount,
                    'count' => (int) $m->count,
                    'refunded' => (int) ($refundOut->get($m->payment_method_id)?->amount ?? 0),
                ])->values(),
            ] : null,
            'pending_refunds' => $pending->map(fn ($r) => ['id' => $r->id, 'number' => $r->number, 'sale_id' => $r->sale_id])->values(),
        ];
    }
}
