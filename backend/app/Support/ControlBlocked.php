<?php

namespace App\Support;

use App\Modules\Audit\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Tindakan yang ditolak kontrol wajib pemisahan tugas (07 2F-KW01, KW03; AB-15).
 * Percobaannya dicatat di jejak audit sebagai CONTROL_BLOCKED (3A-Q1: pencatatan Confirmed (Owner))
 * dan tampil di daftar pengawasan beranda Owner (irisan 9, butir 7).
 *
 * Pencatatan terjadi saat respons dibuat (bootstrap/app.php), setelah transaksi tindakan yang
 * ditolak dibatalkan, sehingga catatannya tidak ikut terhapus.
 */
class ControlBlocked extends AuthorizationException
{
    public const SELF_DECISION = 'SELF_DECISION';

    public const SAME_SENDER_RECEIVER = 'SAME_SENDER_RECEIVER';

    public const BUYER_RECEIVES = 'BUYER_RECEIVES';

    public function __construct(
        string $message,
        public readonly string $control,
        public readonly ?Model $subject = null,
        public readonly ?int $locationId = null,
    ) {
        parent::__construct($message);
    }

    public function record(): void
    {
        if (Auth::user() === null) {
            return;
        }
        try {
            app(AuditLogger::class)->record('CONTROL_BLOCKED', $this->subject, ['kontrol' => $this->control, 'pesan' => $this->getMessage()],
                locationId: $this->locationId, subjectCode: $this->subject?->getAttribute('number'));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
