<?php

namespace App\Modules\System\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Pengaturan aplikasi yang diubah Owner lewat layar (mis. batas persetujuan Finance, OD-10).
 * Setiap perubahan tercatat di jejak audit.
 */
class AppSetting extends Model
{
    public const PURCHASE_FINANCE_LIMIT = 'purchase.finance_limit';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    public static function int(string $key, int $default): int
    {
        $row = static::query()->find($key);

        return $row ? (int) ($row->value['v'] ?? $default) : $default;
    }
}
