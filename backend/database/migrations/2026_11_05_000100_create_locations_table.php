<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lokasi fisik: tepat satu gudang pusat dan banyak outlet (C1, dokumen 12 §4.2).
     */
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 100);
            $table->string('type', 20);
            $table->string('status', 20)->default('ACTIVE');
            $table->timestampTz('opened_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE locations ADD CONSTRAINT locations_type_check CHECK (type IN ('WAREHOUSE', 'OUTLET'))");
        DB::statement("ALTER TABLE locations ADD CONSTRAINT locations_status_check CHECK (status IN ('ACTIVE', 'INACTIVE', 'CLOSED'))");
        // Tepat satu gudang pusat (C1).
        DB::statement("CREATE UNIQUE INDEX locations_single_warehouse ON locations (type) WHERE type = 'WAREHOUSE'");
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
