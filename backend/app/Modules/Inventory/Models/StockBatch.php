<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Batch di gudang dengan tanggal kedaluwarsa (KP-03, AB-05). Outlet tidak memakai batch.
 *
 * @property int $id
 * @property int $item_id
 * @property int $location_id
 * @property string $batch_no
 * @property \Illuminate\Support\Carbon|null $expiry_date
 * @property string $qty_remaining
 * @property string $source
 */
class StockBatch extends Model
{
    protected $fillable = ['item_id', 'location_id', 'batch_no', 'expiry_date', 'qty_remaining', 'source'];

    protected function casts(): array
    {
        return ['expiry_date' => 'date'];
    }
}
