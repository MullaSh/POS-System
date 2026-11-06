<?php

namespace App\Modules\Cash\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Location\Models\Location;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Isi serah terima: shift (Outlet → Gudang) atau serah terima outlet (Gudang → Finance). */
class CashHandoverItem extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    public function handover(): BelongsTo
    {
        return $this->belongsTo(CashHandover::class, 'cash_handover_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Sales\Models\CashierShift::class, 'shift_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(CashHandover::class, 'source_handover_id');
    }
}
