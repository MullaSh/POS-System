<?php

namespace App\Modules\Approval\Http\Controllers;

use App\Modules\Approval\Models\ApprovalEvent;
use App\Modules\Approval\Models\ApprovalRequest;
use App\Modules\Approval\Services\ApprovalService;
use App\Modules\Approval\Services\DelegationService;
use App\Modules\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Daftar persetujuan (dokumen 11 §3.1 nomor 11).
 * Owner melihat semua pengajuan; peran lain melihat pengajuannya sendiri, ditambah pengajuan
 * yang jenisnya didelegasikan kepadanya (2E-Q2).
 */
class ApprovalController
{
    public function __construct(
        private readonly ApprovalService $approvals,
        private readonly DelegationService $delegations,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', Rule::in(['OPEN', 'DONE', ...ApprovalRequest::OPEN_STATUSES, 'APPROVED', 'REJECTED', 'CANCELLED'])],
        ]);
        /** @var User $user */
        $user = $request->user();
        $types = $this->delegations->decidableTypes($user);

        $query = ApprovalRequest::query()
            ->with(['requester:id,name,username', 'requesterRole:id,code,name', 'decider:id,name,username', 'delegation.delegator:id,name'])
            ->when(! $user->isOwner(), fn ($q) => $q->where(fn ($w) => $w->where('requested_by', $user->id)->orWhereIn('type', $types)))
            ->when($request->query('status'), fn ($q, $s) => match ($s) {
                'OPEN' => $q->whereIn('status', ApprovalRequest::OPEN_STATUSES),
                'DONE' => $q->whereNotIn('status', ApprovalRequest::OPEN_STATUSES),
                default => $q->where('status', $s),
            })
            ->orderByDesc('submitted_at')
            ->orderByDesc('id');

        $page = $query->paginate(50);
        $page->getCollection()->transform(fn (ApprovalRequest $r) => $this->present($r, $user));

        return response()->json($page);
    }

    /** Jumlah yang menunggu keputusan user ini (untuk penanda di menu). */
    public function summary(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $types = $this->delegations->decidableTypes($user);
        $waiting = $types === []
            ? 0
            : ApprovalRequest::query()->where('status', ApprovalRequest::STATUS_PENDING)->whereIn('type', $types)->where('requested_by', '<>', $user->id)->count();
        $returned = ApprovalRequest::query()->where('status', ApprovalRequest::STATUS_RETURNED)->where('requested_by', $user->id)->count();

        return response()->json(['data' => ['waiting_my_decision' => $waiting, 'returned_to_me' => $returned]]);
    }

    public function show(Request $request, ApprovalRequest $approval): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if (! $user->isOwner() && $approval->requested_by !== $user->id && ! in_array($approval->type, $this->delegations->decidableTypes($user), true)) {
            return response()->json(['message' => 'Anda tidak memiliki izin untuk melihat pengajuan ini.'], 403);
        }

        return response()->json(['data' => $this->present($approval, $user, withEvents: true)]);
    }

    public function approve(Request $request, ApprovalRequest $approval): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        return $this->respond($this->approvals->approve($approval, $request->user(), $data['note'] ?? null), $request);
    }

    public function reject(Request $request, ApprovalRequest $approval): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:500']], ['note.required' => 'Tuliskan alasan penolakan.']);

        return $this->respond($this->approvals->reject($approval, $request->user(), $data['note']), $request);
    }

    public function returnForRevision(Request $request, ApprovalRequest $approval): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:500']], ['note.required' => 'Tuliskan apa yang perlu diperbaiki.']);

        return $this->respond($this->approvals->returnForRevision($approval, $request->user(), $data['note']), $request);
    }

    public function resubmit(Request $request, ApprovalRequest $approval): JsonResponse
    {
        $data = $request->validate($this->approvals->handler($approval->type)->rules());

        return $this->respond($this->approvals->resubmit($approval, $request->user(), $data, $data['reason'] ?? null), $request);
    }

    public function cancel(Request $request, ApprovalRequest $approval): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        return $this->respond($this->approvals->cancel($approval, $request->user(), $data['note'] ?? null), $request);
    }

    private function respond(ApprovalRequest $approval, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->present($approval->fresh(), $request->user(), withEvents: true)]);
    }

    /** @return array<string, mixed> */
    public function present(ApprovalRequest $r, User $viewer, bool $withEvents = false): array
    {
        $r->loadMissing(['requester:id,name,username', 'requesterRole:id,code,name', 'decider:id,name,username', 'delegation.delegator:id,name']);
        $handler = $this->approvals->handler($r->type);
        $isRequester = $r->requested_by === $viewer->id;

        return [
            'id' => $r->id,
            'number' => $r->number,
            'type' => $r->type,
            'status' => $r->status,
            ...$handler->describe($r),
            'subject' => ['type' => $r->subject_type, 'id' => $r->subject_id],
            'payload' => $r->payload,
            'reason' => $r->reason,
            'requester' => $r->requester?->only(['id', 'name', 'username']),
            'requester_role' => $r->requesterRole?->only(['code', 'name']),
            'submitted_at' => $r->submitted_at,
            'decider' => $r->decider?->only(['id', 'name', 'username']),
            'decided_at' => $r->decided_at,
            'decision_note' => $r->decision_note,
            'delegation' => $r->delegation ? ['number' => $r->delegation->number, 'delegator' => $r->delegation->delegator?->only(['id', 'name'])] : null,
            'can' => [
                'decide' => $this->delegations->canDecide($viewer, $r),
                'resubmit' => $r->status === ApprovalRequest::STATUS_RETURNED && $isRequester,
                'cancel' => $r->isOpen() && $isRequester,
            ],
            'events' => $withEvents
                ? $r->events()->with('user:id,name,username')->get()->map(fn (ApprovalEvent $e) => [
                    'action' => $e->action,
                    'user' => $e->user?->only(['id', 'name', 'username']),
                    'note' => $e->note,
                    'payload' => $e->payload,
                    'created_at' => $e->created_at,
                ])->values()
                : null,
        ];
    }
}
