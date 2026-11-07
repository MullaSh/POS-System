<?php

namespace App\Modules\Reporting\Http\Controllers;

use App\Modules\Reporting\Services\CashReport;
use App\Modules\Reporting\Services\DashboardService;
use App\Modules\Reporting\Services\Period;
use App\Modules\Reporting\Services\ProfitReport;
use App\Modules\Reporting\Services\PurchasingReport;
use App\Modules\Reporting\Services\SalesReport;
use App\Modules\Reporting\Services\StockReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Laporan MVP (dokumen 11 §3.1 nomor 12) dan beranda Owner (nomor 13). Hanya membaca; tidak mengubah apa pun. */
class ReportController
{
    public function __construct(
        private readonly SalesReport $sales,
        private readonly ProfitReport $profit,
        private readonly StockReport $stock,
        private readonly CashReport $cash,
        private readonly PurchasingReport $purchasing,
    ) {}

    public function dashboard(DashboardService $dashboard): JsonResponse
    {
        return $this->ok($dashboard->build());
    }

    public function sales(Request $request): JsonResponse
    {
        return $this->ok($this->sales->byOutlet(Period::fromRequest($request), $this->location($request)));
    }

    public function menuHpp(Request $request): JsonResponse
    {
        return $this->ok($this->sales->hppByMenu(Period::fromRequest($request), $this->location($request)));
    }

    public function profit(Request $request): JsonResponse
    {
        return $this->ok($this->profit->build(Period::fromRequest($request), $this->location($request)));
    }

    public function expenses(Request $request): JsonResponse
    {
        return $this->ok($this->cash->expenses(Period::fromRequest($request), $this->location($request)));
    }

    public function valuation(Request $request): JsonResponse
    {
        $data = $request->validate(['as_of' => ['nullable', 'date_format:Y-m-d']]);
        $asOf = min($data['as_of'] ?? Period::today(), Period::today());

        return $this->ok($this->stock->valuation($asOf, $this->location($request)));
    }

    public function cashFlow(Request $request): JsonResponse
    {
        return $this->ok($this->cash->cashFlow(Period::fromRequest($request)));
    }

    public function cashReconciliation(Request $request): JsonResponse
    {
        return $this->ok($this->cash->reconciliation(Period::fromRequest($request)));
    }

    public function stockMovements(Request $request): JsonResponse
    {
        return $this->ok($this->stock->movements(Period::fromRequest($request), $this->location($request)));
    }

    public function productionWaste(Request $request): JsonResponse
    {
        return $this->ok($this->stock->productionAndWaste(Period::fromRequest($request), $this->location($request)));
    }

    public function purchasing(Request $request): JsonResponse
    {
        return $this->ok($this->purchasing->purchasing(Period::fromRequest($request)));
    }

    public function payables(): JsonResponse
    {
        return $this->ok($this->purchasing->payables());
    }

    private function location(Request $request): ?int
    {
        $data = $request->validate(['location_id' => ['nullable', 'integer', 'exists:locations,id']]);

        return isset($data['location_id']) ? (int) $data['location_id'] : null;
    }

    /** @param  array<string, mixed>  $data */
    private function ok(array $data): JsonResponse
    {
        return response()->json(['data' => $data]);
    }
}
