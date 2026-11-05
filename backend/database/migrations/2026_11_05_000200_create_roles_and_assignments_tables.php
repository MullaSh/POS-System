<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Peran tetap dan penugasan peran per user beserta cakupan lokasinya
     * (C3, C4, 2D-P1, 2F-Q3, dokumen 12 §4.1). Daftar peran tidak bisa diubah
     * lewat aplikasi (2G-P1), sehingga diisi di migrasi ini.
     */
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 50);
            $table->timestampsTz();
        });

        $now = now();
        DB::table('roles')->insert([
            ['code' => 'OWNER', 'name' => 'Owner', 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'FINANCE', 'name' => 'Finance', 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'WAREHOUSE', 'name' => 'Gudang', 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'CASHIER', 'name' => 'Kasir', 'created_at' => $now, 'updated_at' => $now],
        ]);

        Schema::create('role_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('role_id')->constrained('roles');
            $table->string('scope', 20);
            $table->string('status', 20);
            $table->foreignId('requested_by')->nullable()->constrained('users');
            $table->foreignId('confirmed_by')->nullable()->constrained('users');
            $table->timestampTz('confirmed_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users');
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE role_assignments ADD CONSTRAINT role_assignments_scope_check CHECK (scope IN ('ALL_LOCATIONS', 'SELECTED'))");
        DB::statement("ALTER TABLE role_assignments ADD CONSTRAINT role_assignments_status_check CHECK (status IN ('PENDING', 'ACTIVE', 'REVOKED'))");
        // Satu penugasan yang belum dicabut per user per peran.
        DB::statement("CREATE UNIQUE INDEX role_assignments_one_open ON role_assignments (user_id, role_id) WHERE status <> 'REVOKED'");

        Schema::create('role_assignment_locations', function (Blueprint $table) {
            $table->foreignId('role_assignment_id')->constrained('role_assignments');
            $table->foreignId('location_id')->constrained('locations');
            $table->primary(['role_assignment_id', 'location_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_assignment_locations');
        Schema::dropIfExists('role_assignments');
        Schema::dropIfExists('roles');
    }
};
