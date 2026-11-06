<?php

namespace App\Modules\Sales\Models;

use App\Modules\Approval\Models\ApprovalRequest;
use App\Modules\Identity\Models\User;
use App\Modules\Location\Models\Location;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Void atau refund setelah bayar (KP-17, AB-24): diajukan kasir dengan alasan, berlaku setelah
 * disetujui Owner atau penerima delegasinya. Isinya (barang, jumlah, kondisi) ada di pengajuan;
 * setelah berlaku ditulis ke sale_refund_lines dan sale_refund_payments.
 *
 * @property int $id
 * @property string $number
 * @property int $sale_id
 * @property int $location_id
 * @property int $shift_id
 * @property string $reason_code
 * @property int $requested_by
 * @property int|null $approval_request_id
 * @property string|null $kind
 * @property int|null $amount
 */
class SaleRefund extends Model
{
    public const KIND_VOID = 'VOID';
    public const KIND_REFUND = 'REFUND';

    /** Alasan baku (Proposed; dapat diubah Owner). */
    public const REASONS = ['WRONG_INPUT', 'CUSTOMER_CANCEL', 'PRODUCT_ISSUE', 'OTHER'];

    public const CONDITION_NOT_MADE = 'NOT_MADE';
    public const CONDITION_MADE = 'MADE';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['applied_at' => 'datetime', 'amount' => 'integer'];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(CashierShift::class, 'shift_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'approval_request_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SaleRefundLine::class)->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SaleRefundPayment::class)->orderBy('id');
    }
}
