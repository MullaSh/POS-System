<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Akun yang dinonaktifkan langsung keluar; akun yang wajib mengganti kata
 * sandi hanya boleh mengakses profil, ganti kata sandi, dan keluar.
 */
class EnsureAccountUsable
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_active) {
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
            }

            return response()->json(['message' => 'Akun Anda sedang dinonaktifkan. Hubungi pemilik usaha.'], 401);
        }

        if ($user && $user->must_change_password) {
            return response()->json([
                'message' => 'Anda harus mengganti kata sandi terlebih dahulu.',
                'code' => 'PASSWORD_CHANGE_REQUIRED',
            ], 403);
        }

        return $next($request);
    }
}
