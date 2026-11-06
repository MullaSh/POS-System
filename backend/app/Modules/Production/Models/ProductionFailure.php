<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Hasil produksi yang gagal QC Production (C17), per alasan baku. Tidak masuk stok; menjadi waste
 * produksi (keputusan Owner 6 Nov 2026). Append-only. qty dalam satuan dasar barang hasil.
 */
class ProductionFailure extends Model
{
    public const REASONS = ['MISSHAPEN', 'UNEVEN_COOK', 'PACKAGING_LEAK', 'OTHER'];

    public $timestamps = false;

    protected $guarded = ['id'];
}
