<?php

namespace App\Modules\Sales\Approvals;

use App\Modules\Approval\Contracts\ApprovalHandler;
use App\Modules\Approval\Models\ApprovalRequest;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Sales\Models\SaleRefund;
use App\Modules\Sales\Services\RefundService;
use App\Modules\Sales\Services\ShiftService;
use Illuminate\Database\Eloquent\Model;

/** Void dan refund setelah bayar, disetujui Owner atau delegasinya (KP-17, AB-24). */
class SaleRefundApproval implements ApprovalHandler
{
    public function __construct(private readonly RefundService $refunds) {}

    public function approverRole(): string
    {
        return Role::OWNER;
    }

    public function subject(ApprovalRequest $request): Model
    {
        return SaleRefund::query()->findOrFail($request->subject_id);
    }

    public function rules(): array
    {
        return RefundService::rules();
    }

    public function buildPayload(Model $subject, array $input): array
    {
        /** @var SaleRefund $subject */
        return $this->refunds->buildPayload($subject, $input);
    }

    public function apply(ApprovalRequest $request, User $approver): void
    {
        $this->refunds->apply($request, $approver);
    }

    public function describe(ApprovalRequest $request): array
    {
        $refund = SaleRefund::query()->with('location')->find($request->subject_id);
        $p = $request->payload;
        $kind = ($p['kind'] ?? null) === SaleRefund::KIND_VOID ? 'Void' : 'Refund';
        $portions = array_sum(array_column($p['lines'] ?? [], 'qty'));

        return [
            'title' => "{$kind} ".($p['sale_number'] ?? '').' ('.($refund?->location?->name ?? '').')',
            'summary' => ShiftService::rupiah((int) ($p['amount'] ?? 0))." · {$portions} porsi",
        ];
    }
}
