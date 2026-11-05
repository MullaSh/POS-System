<?php

use App\Modules\Catalog\Http\Controllers\ItemController;
use App\Modules\Catalog\Http\Controllers\UnitController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'account.usable'])->group(function (): void {
    Route::get('/units', [UnitController::class, 'index']);
    Route::get('/items', [ItemController::class, 'index']);
    Route::get('/items/{item}', [ItemController::class, 'show']);

    // Data master dikelola Owner (dokumen 12 §9 Q4; G-07).
    Route::middleware('role:OWNER')->group(function (): void {
        Route::post('/units', [UnitController::class, 'store']);
        Route::patch('/units/{unit}', [UnitController::class, 'update']);

        Route::post('/items', [ItemController::class, 'store']);
        Route::patch('/items/{item}', [ItemController::class, 'update']);
        Route::post('/items/{item}/deactivate', [ItemController::class, 'deactivate']);
        Route::post('/items/{item}/activate', [ItemController::class, 'activate']);
        Route::put('/items/{item}/units', [ItemController::class, 'updateUnits']);
        Route::put('/items/{item}/min-stocks', [ItemController::class, 'updateMinStocks']);
    });
});
