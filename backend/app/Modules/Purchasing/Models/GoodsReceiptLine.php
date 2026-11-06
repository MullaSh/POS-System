<?php

namespace App\Modules\Purchasing\Models;

use App\Modules\Approval\Models\ApprovalRequest;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Supplier;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Identity\Models\User;
use App\Modules\Location\Models\Location;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Baris penerimaan: diterima baik dan ditolak (QC Incoming). */
class GoodsReceiptLine extends Model
{
    public const REASONS = ['DAMAGED', 'EXPIRED', 'WRONG_ITEM', 'OTHER'];

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['accepted_value' => 'integer', 'expiry_date' => 'date'];
    }

    public function purchaseLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseLine::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
