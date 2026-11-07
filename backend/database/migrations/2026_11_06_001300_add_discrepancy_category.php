<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Laporan (irisan 9; keputusan Owner 6 Nov 2026, 23.10 WIB, butir 4).
     * Keputusan selisih kas memakai kategori baku agar laporan laba tahu dampaknya:
     * STAFF = ditanggung petugas, BUSINESS = kerugian usaha (masuk laba bersih),
     * RECORDING_ERROR = salah hitung/catat, uang tidak hilang. Keputusan lama tanpa kategori.
     */
    public function up(): void
    {
        Schema::table('discrepancy_resolutions', function (Blueprint $table) {
            $table->string('category', 20)->nullable();
        });
        DB::statement("ALTER TABLE discrepancy_resolutions ADD CONSTRAINT discrepancy_resolutions_category_check CHECK (category IS NULL OR category IN ('STAFF', 'BUSINESS', 'RECORDING_ERROR'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE discrepancy_resolutions DROP CONSTRAINT IF EXISTS discrepancy_resolutions_category_check');
        Schema::table('discrepancy_resolutions', fn (Blueprint $table) => $table->dropColumn('category'));
    }
};
