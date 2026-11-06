<?php

namespace App\Modules\Sales\Models;

use App\Modules\Catalog\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $payment_method_id
 * @property bool $is_cash
 * @property int $amount
 */
class SaleRefundPayment extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_cash' => 'boolean', 'amount' => 'integer'];
    }

    public function method(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function refund(): BelongsTo
    {
        return $this->belongsTo(SaleRefund::class, 'sale_refund_id');
    }
}
