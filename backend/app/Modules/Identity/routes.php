<?php

use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Identity\Http\Controllers\RoleAssignmentController;
use App\Modules\Identity\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/change-password', [AuthController::class, 'changePassword']);

    Route::middleware(['account.usable', 'role:OWNER'])->group(function (): void {
        Route::get('/roles', [RoleAssignmentController::class, 'roles']);

        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::patch('/users/{user}', [UserController::class, 'update']);
        Route::post('/users/{user}/deactivate', [UserController::class, 'deactivate']);
        Route::post('/users/{user}/activate', [UserController::class, 'activate']);
        Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword']);

        Route::post('/users/{user}/role-assignments', [RoleAssignmentController::class, 'store']);
        Route::post('/role-assignments/{assignment}/revoke', [RoleAssignmentController::class, 'revoke']);
    });
});
