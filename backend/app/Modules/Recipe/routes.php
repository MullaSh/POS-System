<?php

use App\Modules\Recipe\Http\Controllers\RecipeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'account.usable'])->group(function (): void {
    Route::get('/recipes', [RecipeController::class, 'index']);
    Route::get('/recipes/usage', [RecipeController::class, 'usage']);
    Route::get('/recipes/{recipe}', [RecipeController::class, 'show'])->whereNumber('recipe');

    // Resep dibuat dan diubah Owner (keputusan Owner 5 Nov 2026; KOD-06).
    Route::middleware('role:OWNER')->group(function (): void {
        Route::post('/recipes', [RecipeController::class, 'store']);
        Route::post('/recipes/{recipe}/versions', [RecipeController::class, 'storeVersion'])->whereNumber('recipe');
    });
});
