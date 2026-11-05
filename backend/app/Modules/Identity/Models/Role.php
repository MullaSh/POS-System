<?php

namespace App\Modules\Identity\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Empat peran tetap (C3). Tidak dibuat atau diubah lewat aplikasi (2G-P1).
 *
 * @property int $id
 * @property string $code
 * @property string $name
 */
class Role extends Model
{
    public const OWNER = 'OWNER';
    public const FINANCE = 'FINANCE';
    public const WAREHOUSE = 'WAREHOUSE';
    public const CASHIER = 'CASHIER';

    public const ALL = [self::OWNER, self::FINANCE, self::WAREHOUSE, self::CASHIER];

    protected $guarded = ['*'];

    public static function idFor(string $code): int
    {
        return static::query()->where('code', $code)->valueOrFail('id');
    }
}
