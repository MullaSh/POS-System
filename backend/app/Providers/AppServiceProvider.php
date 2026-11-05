<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Batasi percobaan login: 5 kali per menit per username dan alamat.
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)
                ->by(strtolower((string) $request->input('username')).'|'.$request->ip())
                ->response(fn () => response()->json([
                    'message' => 'Terlalu banyak percobaan masuk. Tunggu satu menit lalu coba lagi.',
                ], 429));
        });
    }
}
