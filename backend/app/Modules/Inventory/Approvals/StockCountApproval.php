<?php

namespace App\Modules\Inventory\Approvals;

use App\Modules\Approval\Contracts\ApprovalHandler;
use App\Modules\Approval\Models\ApprovalRequest;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Services\StockCountService;
use Illuminate\Database\Eloquent\Model;

/** Penyesuaian stok hasil stock opname, disetujui Owner (OD-21, keputusan Owner 5 Nov 2026). */
class StockCountApproval implements ApprovalHandler
{
    public function __construct(private readonly StockCountService $counts) {}

    public function approverRole(): string
    {
        return Role::OWNER;
    }

    public function subject(ApprovalRequest $request): Model
    {
        return StockCount::query()->findOrFail($request->subject_id);
    }

    public function rules(): array
    {
        return StockCountService::rules();
    }

    public function buildPayload(Model $subject, array $input): array
    {
        /** @var StockCount $subject */
        return $this->counts->buildPayload($subject, $input);
    }

    public function apply(ApprovalRequest $request, User $approver): void
    {
        $this->counts->apply($request, $approver);
    }

    public function describe(ApprovalRequest $request): array
    {
        $count = StockCount::query()->with('location')->find($request->subject_id);
        $lines = $request->payload['lines'] ?? [];
        $diff = count(array_filter($lines, fn ($l) => bccomp((string) $l['difference'], '0', 4) !== 0));

        return [
            'title' => 'Stock opname '.($count?->location?->name ?? '').' ('.($count?->number ?? '').')',
            'summary' => count($lines).' barang dihitung, '.($diff === 0 ? 'tidak ada selisih' : "{$diff} barang berselisih"),
        ];
    }
}
