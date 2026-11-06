<?php

namespace App\Modules\Approval\Http\Controllers;

use App\Modules\Approval\Models\ApprovalDelegation;
use App\Modules\Approval\Services\DelegationService;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Delegasi persetujuan sementara (2E-Q2). Owner melihat semua; lainnya yang diberikan atau diterimanya. */
class DelegationController
{
    public function __construct(private readonly DelegationService $delegations) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $list = ApprovalDelegation::query()
            ->with(['delegator:id,name,username', 'delegate:id,name,username', 'delegatorRole:id,code,name', 'revoker:id,name,username'])
            ->when(! $user->isOwner(), fn ($q) => $q->where(fn ($w) => $w->where('delegator_id', $user->id)->orWhere('delegate_id', $user->id)))
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return response()->json(['data' => $list->map(fn (ApprovalDelegation $d) => $this->present($d, $user))->values()]);
    }

    /** Pilihan untuk membuat delegasi: peran yang dapat didelegasikan beserta jenisnya, dan calon penerima. */
    public function options(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $roles = [];
        foreach ([Role::OWNER, Role::FINANCE] as $role) {
            if ($user->hasActiveRole($role)) {
                $roles[] = ['code' => $role, 'types' => $this->delegations->typesForRole($role)];
            }
        }
        if ($roles === []) {
            throw new AuthorizationException('Delegasi persetujuan diberikan oleh Owner atau Finance.');
        }
        $users = User::query()->where('is_active', true)->where('id', '<>', $user->id)
            ->whereHas('roleAssignments', fn ($q) => $q->where('status', 'ACTIVE'))
            ->orderBy('name')->get(['id', 'name', 'username']);

        return response()->json(['data' => [
            'roles' => $roles,
            'users' => $users,
            'max_days' => (int) config('pos.max_delegation_days'),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(DelegationService::rules(), [
            'ends_at.required' => 'Isi kapan delegasi berakhir.',
            'types.required' => 'Pilih minimal satu jenis persetujuan.',
        ]);
        $delegation = $this->delegations->create($data, $request->user());

        return response()->json(['data' => $this->present($delegation->fresh(), $request->user())], 201);
    }

    public function revoke(Request $request, ApprovalDelegation $delegation): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $delegation = $this->delegations->revoke($delegation, $request->user(), $data['note'] ?? null);

        return response()->json(['data' => $this->present($delegation->fresh(), $request->user())]);
    }

    /** @return array<string, mixed> */
    private function present(ApprovalDelegation $d, User $viewer): array
    {
        $d->loadMissing(['delegator:id,name,username', 'delegate:id,name,username', 'delegatorRole:id,code,name', 'revoker:id,name,username']);
        $status = $d->status();

        return [
            'id' => $d->id,
            'number' => $d->number,
            'delegator' => $d->delegator?->only(['id', 'name', 'username']),
            'delegator_role' => $d->delegatorRole?->code,
            'delegate' => $d->delegate?->only(['id', 'name', 'username']),
            'types' => $d->types,
            'starts_at' => $d->starts_at,
            'ends_at' => $d->ends_at,
            'reason' => $d->reason,
            'status' => $status,
            'revoked_at' => $d->revoked_at,
            'revoked_by' => $d->revoker?->only(['id', 'name', 'username']),
            'revoke_note' => $d->revoke_note,
            'created_at' => $d->created_at,
            'can' => [
                'revoke' => in_array($status, ['ACTIVE', 'SCHEDULED'], true) && ($d->delegator_id === $viewer->id || $viewer->isOwner()),
            ],
        ];
    }
}
