<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Model;

/** Metode bayar (C13). Metode yang ditambahkan Owner selalu non-tunai (KP-15). */
class PaymentMethodController extends SimpleMasterController
{
    protected string $model = PaymentMethod::class;

    protected string $auditPrefix = 'PAYMENT_METHOD';

    protected function blockDeactivation(Model $record): ?string
    {
        return $record->getAttribute('is_cash') ? 'Metode Tunai selalu aktif karena dipakai untuk kas laci kasir.' : null;
    }
}
