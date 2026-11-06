<?php

use App\Modules\Sales\Http\Controllers\PosController;
use App\Modules\Sales\Http\Controllers\SaleController;
use App\Modules\Sales\Http\Controllers\ShiftController;
use Illuminate\Support\Facades\Route;

/*
| POS (irisan 5). Wewenang per outlet diperiksa di service (SalesAccess):
| kasir di outlet yang ditugaskan; Owner dan Finance melihat semua.
*/
Route::middleware(['auth:sanctum', 'account.usable'])->group(function (): void {
    Route::get('/pos/context', [PosController::class, 'context'])->middleware('role:CASHIER');

    Route::get('/shifts', [ShiftController::class, 'index'])->middleware('role:CASHIER,OWNER,FINANCE');
    Route::get('/shifts/{shift}', [ShiftController::class, 'show'])->whereNumber('shift');
    Route::post('/shifts', [ShiftController::class, 'store'])->middleware('role:CASHIER');
    Route::post('/shifts/{shift}/count', [ShiftController::class, 'count'])->whereNumber('shift');
    Route::post('/shifts/{shift}/close', [ShiftController::class, 'close'])->whereNumber('shift');

    Route::get('/sales', [SaleController::class, 'index'])->middleware('role:CASHIER,OWNER,FINANCE');
    Route::get('/sales/{sale}', [SaleController::class, 'show'])->whereNumber('sale');
    Route::post('/sales', [SaleController::class, 'store'])->middleware('role:CASHIER');
    Route::post('/sales/{sale}/refunds', [SaleController::class, 'refund'])->whereNumber('sale')->middleware('role:CASHIER');
});
