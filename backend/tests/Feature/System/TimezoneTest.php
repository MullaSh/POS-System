<?php

namespace Tests\Feature\System;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Waktu dari database dan dari aplikasi harus menunjuk saat yang sama (dokumen 12 P7). */
class TimezoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_and_application_times_agree(): void
    {
        $this->assertSame('Asia/Jakarta', DB::selectOne('SHOW TIME ZONE')->TimeZone);

        $log = app(AuditLogger::class)->record('TEST', system: true);
        $this->assertLessThan(60, abs($log->fresh()->recorded_at->diffInSeconds(now())));

        $user = User::factory()->create();
        $user->forceFill(['last_login_at' => now()])->save();
        $this->assertLessThan(60, abs($user->fresh()->last_login_at->diffInSeconds(now())));
    }
}
