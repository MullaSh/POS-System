<?php

namespace App\Modules\Approval\Contracts;

use App\Modules\Approval\Models\ApprovalRequest;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Aturan satu jenis persetujuan. Modul pemilik data (mis. Catalog untuk harga jual)
 * menulis handler-nya; modul Approval hanya mengatur alur dan riwayat.
 */
interface ApprovalHandler
{
    /** Peran yang berwenang memutuskan (06; mis. 2E-Q1b: harga jual oleh Owner). */
    public function approverRole(): string;

    /** Catatan yang diajukan, dari subject_type + subject_id. */
    public function subject(ApprovalRequest $request): Model;

    /**
     * Aturan validasi isi pengajuan (dipakai saat mengajukan dan mengajukan ulang).
     *
     * @return array<string, mixed>
     */
    public function rules(): array;

    /**
     * Periksa dan bentuk isi pengajuan yang disimpan. Lempar ValidationException bila tidak sah.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function buildPayload(Model $subject, array $input): array;

    /** Jalankan perubahan setelah disetujui, di dalam transaksi yang sama. */
    public function apply(ApprovalRequest $request, User $approver): void;

    /**
     * Ringkasan untuk daftar persetujuan.
     *
     * @return array{title: string, summary: string}
     */
    public function describe(ApprovalRequest $request): array;
}
