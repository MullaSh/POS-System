<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Approval\Models\ApprovalRequest;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Riwayat harga jual menu (KP-16, AB-23). Append-only, dikunci trigger database.
 *
 * Harga yang berlaku pada waktu t = baris terbaru (id terbesar) yang effective_from <= t.
 * Artinya keputusan harga terbaru selalu menang: harga terjadwal yang lebih lama
 * tidak berlaku lagi bila sesudahnya ada harga baru yang berlaku lebih awal.
 *
 * @property int $id
 * @property int $item_id
 * @property int $price
 * @property \Illuminate\Support\Carbon $effective_from
 */
class ItemPrice extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['item_id', 'price', 'effective_from', 'approval_request_id', 'set_by', 'reason'];

    protected function casts(): array
    {
        return ['price' => 'integer', 'effective_from' => 'datetime', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Riwayat harga tidak dapat diubah.'));
        static::deleting(fn () => throw new LogicException('Riwayat harga tidak dapat dihapus.'));
    }

    public function setter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }

    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class);
    }

    /** Subquery harga yang berlaku saat ini untuk dipakai di daftar barang. */
    public static function currentPriceSubquery(): Builder
    {
        return static::query()
            ->select('price')
            ->whereColumn('item_prices.item_id', 'items.id')
            ->where('effective_from', '<=', now())
            ->orderByDesc('id')
            ->limit(1);
    }
}
