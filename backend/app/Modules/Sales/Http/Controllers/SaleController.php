<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Sales\Models\CashierShift;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SalePayment;
use App\Modules\Sales\Models\SaleRefund;
use App\Modules\Sales\Services\RefundService;
use App\Modules\Sales\Services\SaleService;
use App\Modules\Sales\Services\SalesAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Transaksi penjualan dan pengajuan void/refund. */
class SaleController
{
    use PresentsSales;

    public function __construct(
        private readonly SalesAccess $access,
        private readonly SaleService $sales,
        private readonly RefundService $refunds,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'location_id' => ['nullable', 'integer'],
            'shift_id' => ['nullable', 'integer'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'q' => ['nullable', 'string', 'max:40'],
        ]);
        /** @var User $user */
        $user = $request->user();
        $ids = $this->access->viewableLocationIds($user);
        $seeCost = $this->access->canSeeCost($user);

        $page = Sale::query()->with(['location', 'cashier:id,name,username', 'payments.method', 'refunds.approval'])
            ->when($ids !== null, fn ($q) => $q->whereIn('location_id', $ids))
            ->when($data['location_id'] ?? null, fn ($q, $id) => $q->where('location_id', $id))
            ->when($data['shift_id'] ?? null, fn ($q, $id) => $q->where('shift_id', $id))
            ->when($data['date'] ?? null, fn ($q, $d) => $q->where('business_date', $d))
            ->when($data['q'] ?? null, fn ($q, $s) => $q->where('number', 'ilike', '%'.$s.'%'))
            ->orderByDesc('paid_at')->orderByDesc('id')
            ->paginate(50);
        $page->getCollection()->transform(fn (Sale $s) => $this->saleSummary($s, $seeCost));

        return response()->json([...$page->toArray(), 'can_see_cost' => $seeCost]);
    }

    public function show(Request $request, Sale $sale): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->access->ensureCanViewLocation($user, $sale->location_id);

        return response()->json(['data' => $this->present($sale, $user)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(SaleService::rules(), [
            'lines.required' => 'Pilih minimal satu menu.',
            'payments.required' => 'Pilih cara bayar.',
        ]);
        $result = $this->sales->create($data, $request->user());

        return response()->json(['data' => $this->present($result['sale'], $request->user()), 'warnings' => $result['warnings']], 201);
    }

    public function refund(Request $request, Sale $sale): JsonResponse
    {
        $data = $request->validate(RefundService::rules(), ['reason.required' => 'Tuliskan alasan pembatalan.']);
        $this->refunds->request($sale, $data, $request->user());

        return response()->json(['data' => $this->present($sale->fresh(), $request->user())], 201);
    }

    /** @return array<string, mixed> */
    private function present(Sale $sale, User $viewer): array
    {
        $seeCost = $this->access->canSeeCost($viewer);
        $sale->load(['location', 'cashier:id,name,username', 'shift:id,number,status,cashier_id', 'payments.method',
            'lines.item', 'lines.recipeVersion', 'lines.components.item.baseUnit', 'refunds.approval']);
        $refunded = $this->refunds->refundedQty($sale);
        $hasOpenRefund = $sale->refunds->contains(fn (SaleRefund $r) => self::isOpenApproval($r->approval));
        $remaining = $sale->lines->sum(fn ($l) => $l->qty - ($refunded[$l->id] ?? 0));
        $ownShift = CashierShift::query()->where('location_id', $sale->location_id)->where('cashier_id', $viewer->id)
            ->where('status', CashierShift::STATUS_OPEN)->exists();

        return [
            ...$this->saleSummary($sale, $seeCost),
            'subtotal' => $sale->subtotal,
            'discount_total' => $sale->discount_total,
            'tax_total' => $sale->tax_total,
            'paid_total' => $sale->paid_total,
            'change_total' => $sale->change_total,
            'hpp_complete' => $seeCost ? $sale->hpp_complete : null,
            'shift' => $sale->shift?->only(['id', 'number', 'status']),
            'lines' => $sale->lines->map(fn ($l) => $this->lineRow($l, $refunded, $seeCost))->values(),
            'payments' => $sale->payments->map(fn (SalePayment $p) => [
                'payment_method_id' => $p->payment_method_id,
                'name' => $p->method?->name,
                'is_cash' => $p->is_cash,
                'amount' => $p->amount,
                'received' => $p->received,
                'change' => $p->change,
                'reference' => $p->reference,
            ])->values(),
            'refunds' => $sale->refunds->map(fn (SaleRefund $r) => $this->refundRow($r, $viewer))->values(),
            'can_see_cost' => $seeCost,
            'can' => [
                'refund' => $remaining > 0 && ! $hasOpenRefund && $ownShift && $this->access->isCashierAt($viewer, $sale->location),
            ],
            'refund_blocker' => match (true) {
                $remaining === 0 => 'Seluruh transaksi sudah dibatalkan.',
                $hasOpenRefund => 'Masih ada pengajuan pembatalan yang menunggu keputusan.',
                ! $this->access->isCashierAt($viewer, $sale->location) => null,
                ! $ownShift => 'Buka shift di outlet ini untuk mengajukan pembatalan.',
                default => null,
            },
        ];
    }
}
