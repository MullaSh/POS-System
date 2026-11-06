<?php

namespace App\Modules\Purchasing\Approvals;

use App\Modules\Approval\Contracts\ApprovalHandler;
use App\Modules\Approval\Contracts\ReactsToClosing;
use App\Modules\Approval\Models\ApprovalRequest;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Services\PayableService;
use App\Modules\Purchasing\Services\PurchaseService;
use Illuminate\Database\Eloquent\Model;

/** PO di atas batas persetujuan Finance, disetujui Owner sebelum dipesan (2E-Q1a, OD-10). */
class PurchaseOrderApproval implements ApprovalHandler, ReactsToClosing
{
    public function __construct(private readonly PurchaseService $purchases) {}

    public function approverRole(): string
    {
        return Role::OWNER;
    }

    public function subject(ApprovalRequest $request): Model
    {
        return PurchaseOrder::query()->findOrFail($request->subject_id);
    }

    public function rules(): array
    {
        return [];
    }

    public function buildPayload(Model $subject, array $input): array
    {
        /** @var PurchaseOrder $subject */
        return $this->purchases->buildOrderPayload($subject);
    }

    public function apply(ApprovalRequest $request, User $approver): void
    {
        $this->purchases->applyOrder($request);
    }

    public function closed(ApprovalRequest $request): void
    {
        $this->purchases->orderClosed($request);
    }

    public function describe(ApprovalRequest $request): array
    {
        $po = PurchaseOrder::query()->find($request->subject_id);
        $p = $request->payload;

        return [
            'title' => 'Purchase Order '.($po?->number ?? '').' · '.($p['supplier'] ?? ''),
            'summary' => PayableService::rupiah((int) ($p['total'] ?? 0)).' (di atas batas Finance '.PayableService::rupiah((int) ($p['limit'] ?? 0)).')',
        ];
    }
}
