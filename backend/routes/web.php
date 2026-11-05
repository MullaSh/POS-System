<?php

use Illuminate\Support\Facades\Route;

// Layar aplikasi disajikan oleh frontend React. Backend hanya melayani /api.
Route::get('/', fn () => response()->json([
    'aplikasi' => config('app.name'),
    'api' => url('/api/v1'),
]));
