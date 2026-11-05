<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Izinkan hanya user yang memegang salah satu peran yang disebut.
 * Contoh: ->middleware('role:OWNER') atau 'role:OWNER,FINANCE'.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        foreach ($roles as $role) {
            if ($user?->hasActiveRole($role)) {
                return $next($request);
            }
        }

        return response()->json(['message' => 'Anda tidak memiliki izin untuk tindakan ini.'], 403);
    }
}
