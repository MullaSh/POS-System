<?php

namespace App\Modules\Cash\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Location\Models\Location;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Biaya operasional (C15). Outlet: dari Petty Cash; gudang dan pusat: dibayar Finance (G-02). */
class Expense extends Model
{
    public const PAID_PETTY = 'PETTY_CASH';
    public const PAID_FINANCE = 'FINANCE';
    public const STATUS_RECORDED = 'RECORDED';
    public const STATUS_VERIFIED = 'VERIFIED';
    public const STATUS_REJECTED = 'REJECTED';
    public const STATUS_CANCELLED = 'CANCELLED';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'business_date' => 'date', 'decided_at' => 'datetime'];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Catalog\Models\ExpenseCategory::class, 'expense_category_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
