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

/** Purchase Request dari gudang, disetujui Finance (C10, AB-14). */
class PurchaseRequest extends Model
{
    public const STATUS_PENDING = 'PENDING';
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_ORDERED = 'ORDERED';
    public const STATUS_REJECTED = 'REJECTED';
    public const STATUS_CANCELLED = 'CANCELLED';
    public const STATUS_CLOSED = 'CLOSED';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['needed_by' => 'date', 'approved_at' => 'datetime'];
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
        return $this->hasMany(PurchaseLine::class, 'document_id')->where('document_type', 'PR')->orderBy('position');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class)->orderBy('id');
    }
}
