<?php

use App\Modules\Reporting\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

/*
| Laporan dan beranda Owner (irisan 9). Laporan untuk Owner dan Finance, semua lokasi (KOD-07,
| keputusan Owner 6 Nov 2026; 2G-Q2: berisi HPP, laba, dan nilai). Beranda hanya untuk Owner.
*/
Route::middleware(['auth:sanctum', 'account.usable'])->prefix('reports')->group(function (): void {
    Route::get('/dashboard', [ReportController::class, 'dashboard'])->middleware('role:OWNER');

    Route::middleware('role:OWNER,FINANCE')->group(function (): void {
        Route::get('/sales', [ReportController::class, 'sales']);
        Route::get('/menu-hpp', [ReportController::class, 'menuHpp']);
        Route::get('/profit', [ReportController::class, 'profit']);
        Route::get('/expenses', [ReportController::class, 'expenses']);
        Route::get('/inventory-valuation', [ReportController::class, 'valuation']);
        Route::get('/cash-flow', [ReportController::class, 'cashFlow']);
        Route::get('/cash-reconciliation', [ReportController::class, 'cashReconciliation']);
        Route::get('/stock-movements', [ReportController::class, 'stockMovements']);
        Route::get('/production-waste', [ReportController::class, 'productionWaste']);
        Route::get('/purchasing', [ReportController::class, 'purchasing']);
        Route::get('/payables', [ReportController::class, 'payables']);
    });
});
