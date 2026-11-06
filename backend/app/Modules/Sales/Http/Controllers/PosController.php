<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Modules\Catalog\Models\PaymentMethod;
use App\Modules\Identity\Models\User;
use App\Modules\Location\Models\Location;
use App\Modules\Sales\Services\SaleService;
use App\Modules\Sales\Services\SalesAccess;
use App\Modules\Sales\Services\ShiftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Data untuk layar kasir: outlet kasir, shift yang berjalan, menu, dan metode bayar. */
class PosController
{
    use PresentsSales;

    public function __construct(
        private readonly SalesAccess $access,
        private readonly ShiftService $shifts,
        private readonly SaleService $sales,
    ) {}

    public function context(Request $request): JsonResponse
    {
        $data = $request->validate(['location_id' => ['nullable', 'integer']]);
        /** @var User $user */
        $user = $request->user();
        $ids = $this->access->cashierLocationIds($user);
        $outlets = Location::query()->where('type', Location::TYPE_OUTLET)->where('status', Location::STATUS_ACTIVE)
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->orderBy('code')->get();
        $selected = $outlets->firstWhere('id', $data['location_id'] ?? null) ?? $outlets->first();

        $running = $selected ? $this->shifts->running($selected) : null;

        return response()->json(['data' => [
            'outlets' => $outlets->map(fn (Location $l) => $l->only(['id', 'code', 'name']))->values(),
            'location' => $selected?->only(['id', 'code', 'name']),
            'shift' => $running ? [
                ...$this->shiftSummary($running, false),
                'is_mine' => $running->cashier_id === $user->id,
            ] : null,
            'float_expected' => $selected ? $this->shifts->expectedFloat($selected) : ShiftService::floatAmount(),
            'menu' => $selected ? $this->sales->menu() : [],
            'payment_methods' => PaymentMethod::query()->where('is_active', true)->orderByDesc('is_cash')->orderBy('name')
                ->get(['id', 'code', 'name', 'is_cash']),
        ]]);
    }
}
