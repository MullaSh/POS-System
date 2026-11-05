<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Nomor dokumen yang mudah dibaca dan tidak berubah (dokumen 12 P8),
 * mis. APV-251105-0001. Urutan per awalan per hari, aman untuk pemakaian bersamaan.
 * Harus dipanggil di dalam transaksi database.
 */
class DocumentNumber
{
    public static function next(string $prefix): string
    {
        $day = now()->setTimezone(config('app.timezone'))->format('ymd');
        $key = "{$prefix}-{$day}";

        DB::statement('INSERT INTO document_sequences (key, last_number) VALUES (?, 0) ON CONFLICT (key) DO NOTHING', [$key]);
        $number = DB::selectOne('UPDATE document_sequences SET last_number = last_number + 1 WHERE key = ? RETURNING last_number', [$key])->last_number;

        return sprintf('%s-%04d', $key, $number);
    }
}
