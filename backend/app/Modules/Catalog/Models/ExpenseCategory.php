<?php

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;

/** Kategori biaya operasional (C15). */
class ExpenseCategory extends Model
{
    protected $fillable = ['code', 'name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
