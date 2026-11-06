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
 * @property int|null $received
 * @property int|null $change
 */
class SalePayment extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_cash' => 'boolean', 'amount' => 'integer', 'received' => 'integer', 'change' => 'integer'];
    }

    public function method(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }
}
