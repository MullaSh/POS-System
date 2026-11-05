<?php

use App\Modules\Audit\Http\Controllers\AuditLogController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'account.usable', 'role:OWNER'])->group(function (): void {
    Route::get('/audit-logs', [AuditLogController::class, 'index']);
});
