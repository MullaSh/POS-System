<?php

use App\Modules\Approval\Http\Controllers\ApprovalController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'account.usable'])->group(function (): void {
    Route::get('/approvals', [ApprovalController::class, 'index']);
    Route::get('/approvals/summary', [ApprovalController::class, 'summary']);
    Route::get('/approvals/{approval}', [ApprovalController::class, 'show']);

    // Wewenang memutuskan diperiksa per jenis pengajuan di ApprovalService.
    Route::post('/approvals/{approval}/approve', [ApprovalController::class, 'approve']);
    Route::post('/approvals/{approval}/reject', [ApprovalController::class, 'reject']);
    Route::post('/approvals/{approval}/return', [ApprovalController::class, 'returnForRevision']);

    // Hanya pengaju (diperiksa di ApprovalService).
    Route::post('/approvals/{approval}/resubmit', [ApprovalController::class, 'resubmit']);
    Route::post('/approvals/{approval}/cancel', [ApprovalController::class, 'cancel']);
});
