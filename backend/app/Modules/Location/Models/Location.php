<?php

namespace App\Modules\Location\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Gudang pusat atau outlet (C1). Kode dan jenis tidak diubah setelah dibuat
 * (03 §6.5). Lokasi tidak dihapus.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $type
 * @property string $status
 */
class Location extends Model
{
    public const TYPE_WAREHOUSE = 'WAREHOUSE';
    public const TYPE_OUTLET = 'OUTLET';

    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_INACTIVE = 'INACTIVE';
    public const STATUS_CLOSED = 'CLOSED';

    protected $fillable = ['code', 'name', 'type', 'status', 'opened_at', 'closed_at', 'petty_cash_amount'];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'petty_cash_amount' => 'integer',
        ];
    }

    public function isOutlet(): bool
    {
        return $this->type === self::TYPE_OUTLET;
    }
}
