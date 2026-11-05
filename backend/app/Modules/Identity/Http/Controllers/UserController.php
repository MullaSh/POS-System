<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\UserPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Kelola user. Hanya Owner (G-06 belum ada admin teknis; Q4).
 * User tidak dihapus, hanya dinonaktifkan (dokumen 12 §4.1).
 */
class UserController
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $users = User::query()
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('name', 'ilike', "%{$s}%")->orWhere('username', 'ilike', "%{$s}%")))
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $users->map(fn (User $u) => UserPresenter::present($u))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'username' => ['required', 'string', 'min:3', 'max:50', 'regex:/^[a-zA-Z0-9._-]+$/', Rule::unique('users', 'username')],
            'password' => ['required', 'string', Password::min(8)],
        ]);
        $data['username'] = strtolower($data['username']);

        $user = DB::transaction(function () use ($data) {
            $user = User::query()->create([
                'name' => $data['name'],
                'username' => $data['username'],
                'password' => $data['password'],
                'must_change_password' => true,
            ]);
            $this->audit->record('USER_CREATED', $user, ['name' => $user->name, 'username' => $user->username], Role::OWNER);

            return $user;
        });

        return response()->json(['data' => UserPresenter::present($user)], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);

        DB::transaction(function () use ($user, $data) {
            $before = $user->name;
            $user->update(['name' => $data['name']]);
            $this->audit->record('USER_UPDATED', $user, ['name' => ['dari' => $before, 'menjadi' => $user->name]], Role::OWNER);
        });

        return response()->json(['data' => UserPresenter::present($user)]);
    }

    public function deactivate(Request $request, User $user): JsonResponse
    {
        if ($user->is($request->user())) {
            throw ValidationException::withMessages(['user' => 'Anda tidak dapat menonaktifkan akun Anda sendiri.']);
        }
        if ($user->isOwner()) {
            throw ValidationException::withMessages(['user' => 'Akun Owner tidak dapat dinonaktifkan dari aplikasi.']);
        }

        return $this->setActive($user, false, 'USER_DEACTIVATED');
    }

    public function activate(User $user): JsonResponse
    {
        return $this->setActive($user, true, 'USER_ACTIVATED');
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string', Password::min(8)]]);

        if ($user->is($request->user())) {
            throw ValidationException::withMessages(['user' => 'Untuk akun sendiri, gunakan menu Ganti kata sandi.']);
        }

        DB::transaction(function () use ($user, $data) {
            $user->forceFill(['password' => $data['password'], 'must_change_password' => true])->save();
            $this->audit->record('USER_PASSWORD_RESET', $user, actingRole: Role::OWNER);
        });

        return response()->json(['data' => UserPresenter::present($user)]);
    }

    private function setActive(User $user, bool $active, string $action): JsonResponse
    {
        DB::transaction(function () use ($user, $active, $action) {
            $user->update(['is_active' => $active]);
            $this->audit->record($action, $user, actingRole: Role::OWNER);
        });

        return response()->json(['data' => UserPresenter::present($user)]);
    }
}
