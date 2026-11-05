<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Approval\Models\ApprovalRequest;
use App\Modules\Identity\Models\User;
use App\Modules\Location\Models\Location;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Stock opname. Hasil hitung dan saldo sistem saat dihitung disimpan di pengajuan persetujuannya;
 * penyesuaian stok baru berlaku setelah disetujui Owner (OD-21, keputusan Owner 5 Nov 2026).
 *
 * @property int $id
 * @property string $number
 * @property int $location_id
 * @property int $created_by
 * @property int|null $approval_request_id
 */
class StockCount extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['applied_at' => 'datetime'];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'approval_request_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'source_id')->where('source_type', 'stock_count')->orderBy('id');
    }
}
