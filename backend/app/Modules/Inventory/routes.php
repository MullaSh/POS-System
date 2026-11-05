<?php

use App\Modules\Inventory\Http\Controllers\StockController;
use App\Modules\Inventory\Http\Controllers\StockCountController;
use App\Modules\Inventory\Http\Controllers\StockEntryController;
use App\Modules\Inventory\Http\Controllers\StockRequestController;
use App\Modules\Inventory\Http\Controllers\StockTransferController;
use Illuminate\Support\Facades\Route;

/*
| Stok (irisan 4). Wewenang per lokasi diperiksa di service (StockAccess):
| petugas gudang di gudang, kasir di outlet yang ditugaskan; Owner dan Finance melihat semua.
*/
Route::middleware(['auth:sanctum', 'account.usable'])->group(function (): void {
    Route::get('/stock/locations', [StockController::class, 'locations']);
    Route::get('/stock/balances', [StockController::class, 'balances']);
    Route::get('/stock/movements', [StockController::class, 'movements']);
    Route::get('/stock/batches', [StockController::class, 'batches']);

    Route::get('/stock/entries', [StockEntryController::class, 'index']);
    Route::get('/stock/entries/{entry}', [StockEntryController::class, 'show'])->whereNumber('entry');
    // Saldo awal oleh Owner atau Finance (keputusan Owner 5 Nov 2026).
    Route::post('/stock/openings', [StockEntryController::class, 'storeOpening'])->middleware('role:OWNER,FINANCE');
    Route::post('/stock/wastes', [StockEntryController::class, 'storeWaste']);

    Route::get('/stock/counts', [StockCountController::class, 'index']);
    Route::get('/stock/counts/{count}', [StockCountController::class, 'show'])->whereNumber('count');
    Route::post('/stock/counts', [StockCountController::class, 'store']);

    Route::get('/stock-requests', [StockRequestController::class, 'index']);
    Route::get('/stock-requests/{stockRequest}', [StockRequestController::class, 'show'])->whereNumber('stockRequest');
    Route::post('/stock-requests', [StockRequestController::class, 'store']);
    Route::post('/stock-requests/{stockRequest}/cancel', [StockRequestController::class, 'cancel'])->whereNumber('stockRequest');
    Route::post('/stock-requests/{stockRequest}/reject', [StockRequestController::class, 'reject'])->whereNumber('stockRequest');

    Route::get('/stock-transfers', [StockTransferController::class, 'index']);
    Route::get('/stock-transfers/{transfer}', [StockTransferController::class, 'show'])->whereNumber('transfer');
    Route::post('/stock-transfers', [StockTransferController::class, 'store']);
    Route::post('/stock-transfers/{transfer}/receive', [StockTransferController::class, 'receive'])->whereNumber('transfer');
    Route::post('/stock-transfers/{transfer}/cancel', [StockTransferController::class, 'cancel'])->whereNumber('transfer');
    Route::post('/stock-transfers/{transfer}/lines/{line}/resolve', [StockTransferController::class, 'resolve'])->whereNumber(['transfer', 'line']);
});
