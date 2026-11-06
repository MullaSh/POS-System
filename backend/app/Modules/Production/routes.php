<?php

use App\Modules\Production\Http\Controllers\ProductionController;
use Illuminate\Support\Facades\Route;

/*
| Produksi (irisan 8). Gudang merencanakan dan menyelesaikan; Owner dan Finance melihat dengan biaya;
| Owner mengatur toleransi varians. Wewenang per peran diperiksa di service/controller.
*/
Route::middleware(['auth:sanctum', 'account.usable'])->prefix('production')->group(function (): void {
    Route::get('/orders', [ProductionController::class, 'index']);
    Route::post('/orders', [ProductionController::class, 'store']);
    Route::get('/orders/{order}', [ProductionController::class, 'show'])->whereNumber('order');
    Route::post('/orders/{order}/complete', [ProductionController::class, 'complete'])->whereNumber('order');
    Route::post('/orders/{order}/cancel', [ProductionController::class, 'cancel'])->whereNumber('order');
    Route::get('/products', [ProductionController::class, 'products']);
    Route::get('/preview', [ProductionController::class, 'preview']);
    Route::get('/settings', [ProductionController::class, 'settings']);
    Route::put('/settings', [ProductionController::class, 'updateSettings'])->middleware('role:OWNER');
});
