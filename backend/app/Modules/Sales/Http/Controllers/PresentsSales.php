<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Modules\Approval\Models\ApprovalRequest;
use App\Modules\Identity\Models\User;
use App\Modules\Sales\Models\CashierShift;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleLine;
use App\Modules\Sales\Models\SalePayment;
use App\Modules\Sales\Models\SaleRefund;

/** Bentuk data penjualan untuk layar. HPP hanya untuk Owner dan Finance (2G-Q2). */
trait PresentsSales
{
    /** @return array<string, mixed>|null */
    protected function personRef(?User $user): ?array
    {
        return $user?->only(['id', 'name', 'username']);
    }

    /** @return array<string, mixed> */
    protected function saleSummary(Sale $s, bool $seeCost): array
    {
        $s->loadMissing(['location', 'cashier:id,name,username']);
        $refunded = (int) $s->refunds->whereNotNull('applied_at')->sum('amount');
        $pending = $s->refunds->contains(fn (SaleRefund $r) => $r->applied_at === null && $r->approval && $r->approval->isOpen());

        return [
            'id' => $s->id,
            'number' => $s->number,
            'location' => $s->location?->only(['id', 'code', 'name']),
            'shift_id' => $s->shift_id,
            'cashier' => $this->personRef($s->cashier),
            'channel' => $s->channel,
            'total' => $s->total,
            'paid_at' => $s->paid_at,
            'business_date' => $s->business_date?->format('Y-m-d'),
            'customer_note' => $s->customer_note,
            'refunded_amount' => $refunded,
            'status' => match (true) {
                $refunded === 0 => 'PAID',
                $refunded >= $s->total => 'VOIDED',
                default => 'PARTLY_REFUNDED',
            },
            'has_pending_refund' => $pending,
            'methods' => $s->payments->map(fn (SalePayment $p) => $p->method?->name)->filter()->values(),
            'hpp_total' => $seeCost ? $s->hpp_total : null,
        ];
    }

    /** @return array<string, mixed> */
    protected function refundRow(SaleRefund $r, ?User $viewer = null): array
    {
        $r->loadMissing(['requester:id,name,username', 'approval.decider:id,name,username', 'approval.delegation.delegator:id,name']);
        $a = $r->approval;
        $payload = $a?->payload ?? [];

        return [
            'id' => $r->id,
            'number' => $r->number,
            'kind' => $r->kind ?? ($payload['kind'] ?? null),
            'amount' => $r->amount ?? ($payload['amount'] ?? null),
            'reason_code' => $r->reason_code,
            'reason' => $a?->reason,
            'requested_by' => $this->personRef($r->requester),
            'created_at' => $r->created_at,
            'applied_at' => $r->applied_at,
            'approval' => $a ? [
                'id' => $a->id,
                'number' => $a->number,
                'status' => $a->status,
                'decider' => $this->personRef($a->decider),
                'decided_at' => $a->decided_at,
                'decision_note' => $a->decision_note,
                'delegation' => $a->delegation ? ['number' => $a->delegation->number, 'delegator' => $a->delegation->delegator?->only(['id', 'name'])] : null,
            ] : null,
            'lines' => $payload['lines'] ?? [],
            'payments' => $payload['payments'] ?? [],
            // Pengaju dapat membatalkan pengajuannya selama belum diputuskan (mis. agar shift bisa ditutup).
            'can_cancel' => $viewer !== null && $a !== null && $a->isOpen() && $a->requested_by === $viewer->id,
        ];
    }

    /** @return array<string, mixed> */
    protected function lineRow(SaleLine $l, array $refunded, bool $seeCost): array
    {
        return [
            'id' => $l->id,
            'item' => $l->item?->only(['id', 'code', 'name']),
            'qty' => $l->qty,
            'unit_price' => $l->unit_price,
            'line_total' => $l->line_total,
            'refunded_qty' => $refunded[$l->id] ?? 0,
            'recipe_version' => $l->recipeVersion?->only(['id', 'version_no']),
            'unit_hpp' => $seeCost ? $l->unit_hpp : null,
            'hpp_total' => $seeCost ? $l->hpp_total : null,
            'components' => $l->components->map(fn ($c) => [
                'item' => $c->item?->only(['id', 'code', 'name']),
                'base_unit' => $c->item?->baseUnit?->code,
                'qty' => $c->qty,
                'unit_cost' => $seeCost ? $c->unit_cost : null,
                'cost_source' => $seeCost ? $c->cost_source : null,
            ])->values(),
        ];
    }

    /** @return array<string, mixed> */
    protected function shiftSummary(CashierShift $s, bool $showCash): array
    {
        $s->loadMissing(['location', 'cashier:id,name,username']);
        $done = $s->status !== CashierShift::STATUS_OPEN;

        return [
            'id' => $s->id,
            'number' => $s->number,
            'location' => $s->location?->only(['id', 'code', 'name']),
            'cashier' => $this->personRef($s->cashier),
            'status' => $s->status,
            'opened_at' => $s->opened_at,
            'float_expected' => $s->float_expected,
            'float_counted' => $s->float_counted,
            'open_note' => $s->open_note,
            'counted_cash' => $s->counted_cash,
            // Hitung buta (OD-12): angka sistem untuk kasir baru tampil setelah hasil hitung dikirim.
            'expected_cash' => ($showCash || $done) ? $s->expected_cash : null,
            'cash_difference' => ($showCash || $done) ? $s->cash_difference : null,
            'counted_at' => $s->counted_at,
            'close_note' => $s->close_note,
            'float_kept' => $s->float_kept,
            'sales_cash' => $s->sales_cash,
            'closed_at' => $s->closed_at,
        ];
    }

    protected static function isOpenApproval(?ApprovalRequest $a): bool
    {
        return $a !== null && $a->isOpen();
    }
}
