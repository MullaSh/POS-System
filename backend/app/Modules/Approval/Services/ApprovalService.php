<?php

namespace App\Modules\Approval\Services;

use App\Modules\Approval\Contracts\ApprovalHandler;
use App\Modules\Approval\Contracts\ReactsToClosing;
use App\Modules\Purchasing\Approvals\PurchaseOrderApproval;
use App\Modules\Purchasing\Approvals\PurchaseRequestApproval;
use App\Modules\Approval\Models\ApprovalEvent;
use App\Modules\Approval\Models\ApprovalRequest;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Approvals\ItemPriceApproval;
use App\Modules\Identity\Models\Role;
use App\Modules\Inventory\Approvals\StockCountApproval;
use App\Modules\Identity\Models\User;
use App\Modules\Sales\Approvals\SaleRefundApproval;
use App\Support\DocumentNumber;
use App\Support\ControlBlocked;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Alur persetujuan generik (06; dokumen 11 §3.1 nomor 11):
 *   ajukan → setujui | tolak | kembalikan untuk diperbaiki (2E-Q3)
 *   dikembalikan → ajukan ulang | batalkan
 *   menunggu → batalkan oleh pengaju
 *
 * Pemisahan tugas (2F): pengaju tidak memutuskan pengajuannya sendiri.
 * Delegasi sementara (2E-Q2): penerima delegasi yang berlaku dapat memutuskan jenis yang
 * didelegasikan; keputusannya diberi penanda DELEGATED (irisan 5).
 */
class ApprovalService
{
    /** @var array<string, class-string<ApprovalHandler>> */
    private const HANDLERS = [
        ApprovalRequest::TYPE_ITEM_PRICE => ItemPriceApproval::class,
        ApprovalRequest::TYPE_STOCK_COUNT => StockCountApproval::class,
        ApprovalRequest::TYPE_SALE_REFUND => SaleRefundApproval::class,
        ApprovalRequest::TYPE_PURCHASE_REQUEST => PurchaseRequestApproval::class,
        ApprovalRequest::TYPE_PURCHASE_ORDER => PurchaseOrderApproval::class,
    ];

    /** @var list<string> */
    public const TYPES = [ApprovalRequest::TYPE_ITEM_PRICE, ApprovalRequest::TYPE_STOCK_COUNT, ApprovalRequest::TYPE_SALE_REFUND, ApprovalRequest::TYPE_PURCHASE_REQUEST, ApprovalRequest::TYPE_PURCHASE_ORDER];

    public function __construct(private readonly AuditLogger $audit) {}

    public function handler(string $type): ApprovalHandler
    {
        return app(self::HANDLERS[$type]);
    }

    /** @param  array<string, mixed>  $input */
    public function submit(string $type, Model $subject, array $input, ?string $reason, User $requester, string $requesterRole): ApprovalRequest
    {
        $handler = $this->handler($type);

        return DB::transaction(function () use ($type, $subject, $input, $reason, $requester, $requesterRole, $handler) {
            $subjectType = AuditLogger::subjectType($subject);
            $open = ApprovalRequest::query()
                ->where(['type' => $type, 'subject_type' => $subjectType, 'subject_id' => $subject->getKey()])
                ->whereIn('status', ApprovalRequest::OPEN_STATUSES)
                ->lockForUpdate()
                ->first();
            if ($open) {
                throw ValidationException::withMessages([
                    'request' => "Masih ada pengajuan {$open->number} yang belum selesai untuk data ini.",
                ]);
            }

            $request = ApprovalRequest::query()->create([
                'number' => DocumentNumber::next('APV'),
                'type' => $type,
                'status' => ApprovalRequest::STATUS_PENDING,
                'subject_type' => $subjectType,
                'subject_id' => $subject->getKey(),
                'payload' => $handler->buildPayload($subject, $input),
                'reason' => $reason,
                'requested_by' => $requester->id,
                'requester_role_id' => Role::idFor($requesterRole),
                'submitted_at' => now(),
            ]);

            $this->event($request, ApprovalEvent::SUBMITTED, $requester, $reason, $request->payload);
            $this->audit->record('APPROVAL_SUBMITTED', $request, $this->auditChanges($request, $handler), $requesterRole,
                $reason, related: $this->related($request), user: $requester, subjectCode: $request->number);

            return $request;
        });
    }

    public function approve(ApprovalRequest $request, User $approver, ?string $note): ApprovalRequest
    {
        return $this->decide($request, $approver, ApprovalRequest::STATUS_APPROVED, ApprovalEvent::APPROVED, 'APPROVAL_APPROVED', $note);
    }

    public function reject(ApprovalRequest $request, User $approver, string $note): ApprovalRequest
    {
        return $this->decide($request, $approver, ApprovalRequest::STATUS_REJECTED, ApprovalEvent::REJECTED, 'APPROVAL_REJECTED', $note);
    }

    public function returnForRevision(ApprovalRequest $request, User $approver, string $note): ApprovalRequest
    {
        return $this->decide($request, $approver, ApprovalRequest::STATUS_RETURNED, ApprovalEvent::RETURNED, 'APPROVAL_RETURNED', $note);
    }

    /** @param  array<string, mixed>  $input */
    public function resubmit(ApprovalRequest $request, User $requester, array $input, ?string $reason): ApprovalRequest
    {
        return DB::transaction(function () use ($request, $requester, $input, $reason) {
            $request = $this->lock($request);
            $this->ensureRequester($request, $requester);
            $this->ensureStatus($request, [ApprovalRequest::STATUS_RETURNED], 'Hanya pengajuan yang dikembalikan yang dapat diajukan ulang.');

            $handler = $this->handler($request->type);
            $request->update([
                'status' => ApprovalRequest::STATUS_PENDING,
                'payload' => $handler->buildPayload($handler->subject($request), $input),
                'reason' => $reason,
                'submitted_at' => now(),
                'decided_by' => null,
                'decided_at' => null,
                'decision_note' => null,
                'delegation_id' => null,
            ]);

            $this->event($request, ApprovalEvent::RESUBMITTED, $requester, $reason, $request->payload);
            $this->audit->record('APPROVAL_RESUBMITTED', $request, $this->auditChanges($request, $handler),
                $request->requesterRole->code, $reason, related: $this->related($request), user: $requester, subjectCode: $request->number);

            return $request;
        });
    }

    public function cancel(ApprovalRequest $request, User $requester, ?string $note): ApprovalRequest
    {
        return DB::transaction(function () use ($request, $requester, $note) {
            $request = $this->lock($request);
            $this->ensureRequester($request, $requester);
            $this->ensureStatus($request, ApprovalRequest::OPEN_STATUSES, 'Pengajuan ini sudah selesai dan tidak dapat dibatalkan.');

            $request->update(['status' => ApprovalRequest::STATUS_CANCELLED]);
            $this->event($request, ApprovalEvent::CANCELLED, $requester, $note);
            $this->audit->record('APPROVAL_CANCELLED', $request, null, $request->requesterRole->code, $note,
                related: $this->related($request), user: $requester, subjectCode: $request->number);
            $handler = $this->handler($request->type);
            if ($handler instanceof ReactsToClosing) {
                $handler->closed($request);
            }

            return $request;
        });
    }

    private function decide(ApprovalRequest $request, User $approver, string $status, string $eventAction, string $auditAction, ?string $note): ApprovalRequest
    {
        return DB::transaction(function () use ($request, $approver, $status, $eventAction, $auditAction, $note) {
            $request = $this->lock($request);
            $handler = $this->handler($request->type);
            $role = $handler->approverRole();

            $delegation = null;
            if (! $approver->hasActiveRole($role)) {
                $delegation = app(DelegationService::class)->activeFor($approver, $request->type, $role);
                if (! $delegation) {
                    throw new AuthorizationException('Anda tidak berwenang memutuskan pengajuan ini.');
                }
            }
            if ($request->requested_by === $approver->id) {
                throw new ControlBlocked('Pengajuan tidak dapat diputuskan oleh pengajunya sendiri.', ControlBlocked::SELF_DECISION, $request);
            }
            $this->ensureStatus($request, [ApprovalRequest::STATUS_PENDING], 'Pengajuan ini tidak sedang menunggu keputusan.');

            $request->update([
                'status' => $status,
                'decided_by' => $approver->id,
                'decided_at' => now(),
                'decision_note' => $note,
                'delegation_id' => $delegation?->id,
            ]);

            $this->event($request, $eventAction, $approver, $note, $delegation ? ['delegasi' => $delegation->number] : null);
            $related = $this->related($request) + ($delegation ? ['delegation' => $delegation->number, 'delegator_id' => $delegation->delegator_id] : []);
            $this->audit->record($auditAction, $request, $this->auditChanges($request, $handler), $role,
                $note, $delegation ? [AuditLogger::FLAG_DELEGATED] : [], related: $related, user: $approver, subjectCode: $request->number);

            if ($status === ApprovalRequest::STATUS_APPROVED) {
                $handler->apply($request, $approver);
            } elseif ($status === ApprovalRequest::STATUS_REJECTED && $handler instanceof ReactsToClosing) {
                $handler->closed($request);
            }

            return $request;
        });
    }

    private function lock(ApprovalRequest $request): ApprovalRequest
    {
        return ApprovalRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
    }

    private function ensureRequester(ApprovalRequest $request, User $user): void
    {
        if ($request->requested_by !== $user->id) {
            throw new AuthorizationException('Hanya pengaju yang dapat melakukan tindakan ini.');
        }
    }

    /** @param  list<string>  $allowed */
    private function ensureStatus(ApprovalRequest $request, array $allowed, string $message): void
    {
        if (! in_array($request->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => $message]);
        }
    }

    /** @param  array<string, mixed>|null  $payload */
    private function event(ApprovalRequest $request, string $action, User $user, ?string $note, ?array $payload = null): void
    {
        ApprovalEvent::query()->create([
            'approval_request_id' => $request->id,
            'action' => $action,
            'user_id' => $user->id,
            'note' => $note,
            'payload' => $payload,
        ]);
    }

    /** @return array<string, mixed> */
    private function auditChanges(ApprovalRequest $request, ApprovalHandler $handler): array
    {
        return ['jenis' => $request->type, 'ringkasan' => $handler->describe($request)['summary'], 'isi' => $request->payload];
    }

    /** @return array<string, mixed> */
    private function related(ApprovalRequest $request): array
    {
        return ['subject_type' => $request->subject_type, 'subject_id' => $request->subject_id];
    }
}
