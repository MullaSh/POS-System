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

/** Purchase Order dibuat Finance ke supplier (KP-10); di atas batas Finance disetujui Owner (2E-Q1a). */
class PurchaseOrder extends Model
{
    public const STATUS_WAITING_OWNER = 'WAITING_OWNER';
    public const STATUS_ORDERED = 'ORDERED';
    public const STATUS_PARTIAL = 'PARTIAL';
    public const STATUS_RECEIVED = 'RECEIVED';
    public const STATUS_CLOSED = 'CLOSED';
    public const STATUS_REJECTED = 'REJECTED';
    public const STATUS_CANCELLED = 'CANCELLED';

    public const RECEIVABLE = [self::STATUS_ORDERED, self::STATUS_PARTIAL];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['total' => 'integer', 'expected_date' => 'date', 'ordered_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class, 'purchase_request_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'approval_request_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseLine::class, 'document_id')->where('document_type', 'PO')->orderBy('position');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class)->orderBy('id');
    }
}
