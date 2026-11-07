<?php

namespace App\Modules\Reporting\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Rentang tanggal laporan dalam WIB (dokumen 12 P7). Bawaan: awal bulan ini s.d. hari ini.
 * Tanggal transaksi memakai tanggal usahanya; tindakan berwaktu (refund, keputusan selisih)
 * memakai tanggal terjadinya, sehingga laporan hari yang sudah lewat tidak berubah.
 */
final class Period
{
    public const MAX_DAYS = 366;

    public function __construct(public readonly string $from, public readonly string $to) {}

    public static function fromRequest(Request $request): self
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $today = self::today();
        $from = $data['from'] ?? Carbon::parse($today)->startOfMonth()->toDateString();
        $to = $data['to'] ?? $today;
        if ($to < $from) {
            throw ValidationException::withMessages(['to' => 'Tanggal akhir tidak boleh sebelum tanggal awal.']);
        }
        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1 > self::MAX_DAYS) {
            throw ValidationException::withMessages(['from' => 'Rentang laporan paling lama satu tahun.']);
        }

        return new self($from, $to);
    }

    public static function month(?string $today = null): self
    {
        $today ??= self::today();

        return new self(Carbon::parse($today)->startOfMonth()->toDateString(), $today);
    }

    public static function today(): string
    {
        return now()->setTimezone(self::tz())->toDateString();
    }

    public static function tz(): string
    {
        return (string) config('app.timezone');
    }

    /** Ekspresi SQL tanggal WIB dari kolom waktu. */
    public static function dateOf(string $column): string
    {
        return "({$column} AT TIME ZONE '".str_replace("'", '', self::tz())."')::date";
    }

    /** @return array{from: string, to: string} */
    public function toArray(): array
    {
        return ['from' => $this->from, 'to' => $this->to];
    }
}
