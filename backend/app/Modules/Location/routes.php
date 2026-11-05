<?php

use App\Modules\Location\Http\Controllers\LocationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'account.usable'])->group(function (): void {
    Route::get('/locations', [LocationController::class, 'index']);

    Route::middleware('role:OWNER')->group(function (): void {
        Route::post('/locations', [LocationController::class, 'store']);
        Route::patch('/locations/{location}', [LocationController::class, 'update']);
        Route::post('/locations/{location}/deactivate', [LocationController::class, 'deactivate']);
        Route::post('/locations/{location}/activate', [LocationController::class, 'activate']);
        Route::post('/locations/{location}/close', [LocationController::class, 'close']);
    });
});
