<?php

use App\Modules\Approval\Http\Controllers\ApprovalController;
use App\Modules\Approval\Http\Controllers\DelegationController;
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

    // Delegasi sementara (2E-Q2): dibuat Owner atau Finance; dicabut pemberinya atau Owner.
    Route::get('/delegations', [DelegationController::class, 'index']);
    Route::get('/delegations/options', [DelegationController::class, 'options']);
    Route::post('/delegations', [DelegationController::class, 'store'])->middleware('role:OWNER,FINANCE');
    Route::post('/delegations/{delegation}/revoke', [DelegationController::class, 'revoke'])->whereNumber('delegation');
});
