<?php

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;

/** Supplier, dikelola Finance (AB-17). Tidak dihapus, hanya dinonaktifkan. */
class Supplier extends Model
{
    public const FIELDS = ['name', 'contact_name', 'phone', 'address', 'payment_term_days', 'bank_account', 'notes'];

    protected $fillable = ['code', ...self::FIELDS, 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'payment_term_days' => 'integer'];
    }
}
