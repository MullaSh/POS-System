<?php

namespace App\Modules\Catalog\Approvals;

use App\Modules\Approval\Contracts\ApprovalHandler;
use App\Modules\Approval\Models\ApprovalRequest;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Services\PriceService;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Perubahan harga jual menu, diputuskan Owner (2E-Q1b, AB-23). */
class ItemPriceApproval implements ApprovalHandler
{
    public function __construct(private readonly PriceService $prices) {}

    public function approverRole(): string
    {
        return Role::OWNER;
    }

    public function subject(ApprovalRequest $request): Model
    {
        return Item::query()->findOrFail($request->subject_id);
    }

    public function rules(): array
    {
        return PriceService::rules();
    }

    public function buildPayload(Model $subject, array $input): array
    {
        /** @var Item $subject */
        return $this->prices->buildProposal($subject, $input);
    }

    public function apply(ApprovalRequest $request, User $approver): void
    {
        $this->prices->applyApproved($request, $approver);
    }

    public function describe(ApprovalRequest $request): array
    {
        $item = Item::query()->find($request->subject_id);
        $p = $request->payload;
        $rp = fn (?int $v) => $v === null ? 'belum ada' : 'Rp'.number_format($v, 0, ',', '.');
        $when = $p['effective_date'] ? 'mulai '.date('d/m/Y', strtotime($p['effective_date'])) : 'langsung berlaku';

        return [
            'title' => 'Harga jual '.($item?->name ?? 'menu'),
            'summary' => "{$rp($p['previous_price'])} → {$rp($p['price'])}, {$when}",
        ];
    }
}
