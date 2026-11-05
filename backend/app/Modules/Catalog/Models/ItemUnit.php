<?php

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Konversi satuan: 1 [unit] = factor_to_base × satuan dasar (C11, AB-02).
 */
class ItemUnit extends Model
{
    protected $fillable = ['item_id', 'unit_id', 'factor_to_base', 'is_purchase_default', 'is_usage_default'];

    protected function casts(): array
    {
        return [
            'factor_to_base' => 'decimal:4',
            'is_purchase_default' => 'boolean',
            'is_usage_default' => 'boolean',
        ];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
