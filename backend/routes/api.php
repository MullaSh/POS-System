<?php

use Illuminate\Support\Facades\Route;

/*
| API versi 1. Setiap modul mendaftarkan rutenya sendiri di
| app/Modules/<Modul>/routes.php (dokumen 12 §2.2, §6).
*/
Route::prefix('v1')->group(function (): void {
    foreach (glob(app_path('Modules/*/routes.php')) as $moduleRoutes) {
        require $moduleRoutes;
    }
});
