<?php

namespace App\Support;

/**
 * Hitungan desimal tepat untuk jumlah barang dan harga pokok (dokumen 12 P6),
 * memakai bcmath dengan pembulatan setengah ke atas.
 */
class Decimal
{
    public const WORK = 8;

    public static function round(string $value, int $scale = 4): string
    {
        $factor = bcpow('10', (string) $scale);
        $shifted = bcmul($value, $factor, self::WORK);
        $rounded = bccomp($shifted, '0', self::WORK) >= 0 ? bcadd($shifted, '0.5', 0) : bcsub($shifted, '0.5', 0);

        return bcdiv($rounded, $factor, $scale);
    }

    public static function cmp(string $a, string $b): int
    {
        return bccomp($a, $b, self::WORK);
    }

    public static function isZero(string $a): bool
    {
        return bccomp($a, '0', self::WORK) === 0;
    }

    public static function mul(string $a, string $b, int $scale = 4): string
    {
        return self::round(bcmul($a, $b, self::WORK), $scale);
    }

    public static function min(string $a, string $b): string
    {
        return self::cmp($a, $b) <= 0 ? $a : $b;
    }
}
