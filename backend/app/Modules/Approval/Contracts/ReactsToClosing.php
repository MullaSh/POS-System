<?php

namespace App\Modules\Approval\Contracts;

use App\Modules\Approval\Models\ApprovalRequest;

/** Handler yang perlu memperbarui datanya saat pengajuan ditolak atau dibatalkan (mis. PR, PO). */
interface ReactsToClosing
{
    public function closed(ApprovalRequest $request): void;
}
