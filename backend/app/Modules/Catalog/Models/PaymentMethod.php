<?php

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Metode bayar (C13). Tepat satu metode tunai (CASH); metode tambahan selalu non-tunai
 * dan masuk ke satu rekening usaha pusat (KP-15).
 */
class PaymentMethod extends Model
{
    public const CASH = 'CASH';

    protected $fillable = ['code', 'name', 'is_cash', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_cash' => 'boolean'];
    }
}
