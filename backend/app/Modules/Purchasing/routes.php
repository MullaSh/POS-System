<?php

use App\Modules\Purchasing\Http\Controllers\PurchaseController;
use Illuminate\Support\Facades\Route;

/*
| Pembelian (irisan 7). Wewenang per peran diperiksa di service/controller:
| gudang mengajukan PR dan menerima barang, Finance memesan dan membayar, Owner menyetujui PO besar.
*/
Route::middleware(['auth:sanctum', 'account.usable'])->prefix('purchasing')->group(function (): void {
    Route::get('/requests', [PurchaseController::class, 'requests']);
    Route::post('/requests', [PurchaseController::class, 'storeRequest']);
    Route::get('/requests/{pr}', [PurchaseController::class, 'showRequest'])->whereNumber('pr');
    Route::post('/requests/{pr}/close', [PurchaseController::class, 'closeRequest'])->whereNumber('pr')->middleware('role:FINANCE');

    Route::get('/orders', [PurchaseController::class, 'orders']);
    Route::post('/orders', [PurchaseController::class, 'storeOrder'])->middleware('role:FINANCE');
    Route::get('/orders/{po}', [PurchaseController::class, 'showOrder'])->whereNumber('po');
    Route::post('/orders/{po}/cancel', [PurchaseController::class, 'cancelOrder'])->whereNumber('po')->middleware('role:FINANCE');
    Route::post('/orders/{po}/close', [PurchaseController::class, 'closeOrder'])->whereNumber('po')->middleware('role:FINANCE');
    Route::post('/orders/{po}/receive', [PurchaseController::class, 'receive'])->whereNumber('po');

    Route::get('/payables', [PurchaseController::class, 'payables']);
    Route::post('/receipts/{receipt}/invoice', [PurchaseController::class, 'storeInvoice'])->whereNumber('receipt')->middleware('role:FINANCE');
    Route::post('/invoices/{invoice}/pay', [PurchaseController::class, 'pay'])->whereNumber('invoice')->middleware('role:FINANCE');

    Route::get('/settings', [PurchaseController::class, 'settings']);
    Route::put('/settings', [PurchaseController::class, 'updateSettings'])->middleware('role:OWNER');
});
