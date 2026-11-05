<?php

use App\Modules\Catalog\Http\Controllers\ExpenseCategoryController;
use App\Modules\Catalog\Http\Controllers\ItemController;
use App\Modules\Catalog\Http\Controllers\ItemPriceController;
use App\Modules\Catalog\Http\Controllers\PaymentMethodController;
use App\Modules\Catalog\Http\Controllers\SupplierController;
use App\Modules\Catalog\Http\Controllers\UnitController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'account.usable'])->group(function (): void {
    Route::get('/units', [UnitController::class, 'index']);
    Route::get('/items', [ItemController::class, 'index']);
    Route::get('/items/{item}', [ItemController::class, 'show']);
    Route::get('/payment-methods', [PaymentMethodController::class, 'index']);
    Route::get('/expense-categories', [ExpenseCategoryController::class, 'index']);

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

        // Owner mengubah harga jual langsung (2F-Q1, tercatat OWNER_DIRECT).
        Route::post('/items/{item}/prices', [ItemPriceController::class, 'setDirect']);

        foreach (['payment-methods' => PaymentMethodController::class, 'expense-categories' => ExpenseCategoryController::class] as $path => $controller) {
            Route::post("/{$path}", [$controller, 'store']);
            Route::patch("/{$path}/{id}", [$controller, 'update'])->whereNumber('id');
            Route::post("/{$path}/{id}/deactivate", [$controller, 'deactivate'])->whereNumber('id');
            Route::post("/{$path}/{id}/activate", [$controller, 'activate'])->whereNumber('id');
        }
    });

    // Finance mengajukan harga jual baru (G-05, keputusan Owner 5 Nov 2026).
    Route::post('/items/{item}/price-requests', [ItemPriceController::class, 'request'])->middleware('role:FINANCE');

    // Supplier: Owner dan Finance melihat; Finance mengelola (AB-17).
    Route::get('/suppliers', [SupplierController::class, 'index'])->middleware('role:OWNER,FINANCE');
    Route::middleware('role:FINANCE')->group(function (): void {
        Route::post('/suppliers', [SupplierController::class, 'store']);
        Route::patch('/suppliers/{supplier}', [SupplierController::class, 'update']);
        Route::post('/suppliers/{supplier}/deactivate', [SupplierController::class, 'deactivate']);
        Route::post('/suppliers/{supplier}/activate', [SupplierController::class, 'activate']);
    });
});
