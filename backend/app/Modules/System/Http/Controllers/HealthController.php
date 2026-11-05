<?php

namespace App\Modules\System\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Pemeriksaan kesehatan: aplikasi hidup dan database dapat dihubungi.
 */
class HealthController
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('select 1');
            $database = 'ok';
        } catch (Throwable) {
            $database = 'gagal';
        }

        return response()->json([
            'status' => $database === 'ok' ? 'ok' : 'gangguan',
            'database' => $database,
            'waktu_server' => now()->toIso8601String(),
            'zona_waktu' => config('app.timezone'),
        ], $database === 'ok' ? 200 : 503);
    }
}
