<?php

namespace App\Modules\Cash\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Location\Models\Location;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Pencocokan harian non-tunai per metode terhadap mutasi rekening pusat (KP-15). */
class NoncashReconciliation extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['business_date' => 'date', 'system_amount' => 'integer', 'bank_amount' => 'integer', 'difference' => 'integer'];
    }

    public function method(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Catalog\Models\PaymentMethod::class, 'payment_method_id');
    }

    public function reconciler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }
}
