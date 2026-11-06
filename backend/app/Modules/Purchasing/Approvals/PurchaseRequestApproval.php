<?php

namespace App\Modules\Purchasing\Approvals;

use App\Modules\Approval\Contracts\ApprovalHandler;
use App\Modules\Approval\Contracts\ReactsToClosing;
use App\Modules\Approval\Models\ApprovalRequest;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Services\PurchaseService;
use Illuminate\Database\Eloquent\Model;

/** Purchase Request dari gudang, disetujui Finance (C10, AB-14). */
class PurchaseRequestApproval implements ApprovalHandler, ReactsToClosing
{
    public function __construct(private readonly PurchaseService $purchases) {}

    public function approverRole(): string
    {
        return Role::FINANCE;
    }

    public function subject(ApprovalRequest $request): Model
    {
        return PurchaseRequest::query()->findOrFail($request->subject_id);
    }

    public function rules(): array
    {
        return PurchaseService::requestRules();
    }

    public function buildPayload(Model $subject, array $input): array
    {
        /** @var PurchaseRequest $subject */
        return $this->purchases->buildRequestPayload($subject, $input);
    }

    public function apply(ApprovalRequest $request, User $approver): void
    {
        $this->purchases->applyRequest($request);
    }

    public function closed(ApprovalRequest $request): void
    {
        $this->purchases->requestClosed($request);
    }

    public function describe(ApprovalRequest $request): array
    {
        $pr = PurchaseRequest::query()->find($request->subject_id);
        $lines = $request->payload['lines'] ?? [];
        $shown = array_map(fn ($l) => $l['item_name'].' '.PurchaseService::plain((string) $l['qty']).' '.($l['unit_code'] ?? ''), array_slice($lines, 0, 3));

        return [
            'title' => 'Purchase Request '.($pr?->number ?? ''),
            'summary' => count($lines).' barang: '.implode(', ', $shown).(count($lines) > 3 ? ', …' : ''),
        ];
    }
}
