<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\UserPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string'],
        ]);

        $username = strtolower(trim($data['username']));
        $user = User::query()->where('username', $username)->first();
        $passwordOk = $user && Hash::check($data['password'], $user->password);

        if (! $passwordOk) {
            // Pelaku tidak diketahui; akun yang dicoba dicatat sebagai objek.
            $this->audit->record('LOGIN_FAILED', $user, subjectCode: $username, withClient: true);
            throw ValidationException::withMessages(['username' => 'Username atau kata sandi salah.']);
        }

        if (! $user->is_active) {
            $this->audit->record('LOGIN_REJECTED_INACTIVE', $user, withClient: true);
            throw ValidationException::withMessages(['username' => 'Akun Anda sedang dinonaktifkan. Hubungi pemilik usaha.']);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        DB::transaction(function () use ($user) {
            $user->forceFill(['last_login_at' => now()])->save();
            $this->audit->record('LOGIN', $user, user: $user, withClient: true);
        });

        return response()->json(['user' => UserPresenter::present($user)]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user) {
            $this->audit->record('LOGOUT', $user, user: $user, withClient: true);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Anda sudah keluar.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => UserPresenter::present($request->user())]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::min(8), 'different:current_password'],
        ]);

        /** @var User $user */
        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Kata sandi lama tidak sesuai.']);
        }

        DB::transaction(function () use ($user, $data) {
            $user->forceFill([
                'password' => $data['password'],
                'must_change_password' => false,
            ])->save();
            $this->audit->record('PASSWORD_CHANGED', $user, user: $user, withClient: true);
        });

        return response()->json(['user' => UserPresenter::present($user->refresh())]);
    }
}
