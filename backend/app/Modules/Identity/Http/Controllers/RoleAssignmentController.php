<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\RoleAssignment;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\RoleAssignmentService;
use App\Modules\Identity\UserPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RoleAssignmentController
{
    public function __construct(private readonly RoleAssignmentService $service) {}

    public function roles(): JsonResponse
    {
        return response()->json([
            'data' => Role::query()->orderBy('id')->get(['id', 'code', 'name']),
        ]);
    }

    public function store(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'role' => ['required', 'string', Rule::in(Role::ALL)],
            'location_ids' => ['array'],
            'location_ids.*' => ['integer', 'distinct', 'exists:locations,id'],
        ]);

        $this->service->assign($user, $data['role'], $data['location_ids'] ?? [], $request->user());

        return response()->json(['data' => UserPresenter::present($user)], 201);
    }

    public function revoke(Request $request, RoleAssignment $assignment): JsonResponse
    {
        $this->service->revoke($assignment, $request->user());

        return response()->json(['data' => UserPresenter::present($assignment->user)]);
    }
}
