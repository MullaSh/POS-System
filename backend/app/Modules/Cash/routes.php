<?php

use App\Modules\Cash\Http\Controllers\CashOverviewController;
use App\Modules\Cash\Http\Controllers\ExpenseController;
use App\Modules\Cash\Http\Controllers\HandoverController;
use Illuminate\Support\Facades\Route;

/*
| Kas (irisan 6). Wewenang per lokasi dan peran diperiksa di service:
| kasir di outletnya, gudang di gudang, Finance memutuskan, Owner melihat semua.
*/
Route::middleware(['auth:sanctum', 'account.usable'])->prefix('cash')->group(function (): void {
    Route::get('/overview', [CashOverviewController::class, 'overview']);
    Route::get('/reconciliations', [CashOverviewController::class, 'reconciliations']);
    Route::post('/reconciliations', [CashOverviewController::class, 'reconcile'])->middleware('role:FINANCE');
    Route::get('/discrepancies', [CashOverviewController::class, 'discrepancyIndex']);
    Route::post('/discrepancies/resolve', [CashOverviewController::class, 'resolve'])->middleware('role:FINANCE');

    Route::get('/handovers', [HandoverController::class, 'index']);
    Route::get('/handovers/pending', [HandoverController::class, 'pending']);
    Route::get('/handovers/{handover}', [HandoverController::class, 'show'])->whereNumber('handover');
    Route::post('/handovers', [HandoverController::class, 'store']);
    Route::post('/handovers/{handover}/receive', [HandoverController::class, 'receive'])->whereNumber('handover');

    Route::get('/expenses', [ExpenseController::class, 'index']);
    Route::post('/expenses', [ExpenseController::class, 'store']);
    Route::post('/expenses/{expense}/cancel', [ExpenseController::class, 'cancel'])->whereNumber('expense');

    Route::get('/petty-cash/{location}', [ExpenseController::class, 'pettyCash'])->whereNumber('location');
    Route::post('/petty-cash/{location}/request', [ExpenseController::class, 'requestRefill'])->whereNumber('location');
    Route::post('/petty-cash/{location}/refill', [ExpenseController::class, 'refill'])->whereNumber('location')->middleware('role:FINANCE');
    Route::put('/petty-cash/{location}/target', [ExpenseController::class, 'setTarget'])->whereNumber('location')->middleware('role:OWNER');
});
