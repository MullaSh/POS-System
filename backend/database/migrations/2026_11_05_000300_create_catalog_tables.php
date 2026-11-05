<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Satuan, barang 4 kelompok + menu, konversi satuan, stok minimum
     * (KP-01, AB-01, AB-02, AB-07, C11, dokumen 12 §4.3).
     */
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 50);
            $table->timestampsTz();
        });

        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->string('item_type', 20);
            $table->foreignId('base_unit_id')->constrained('units');
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->index('item_type');
        });

        DB::statement("ALTER TABLE items ADD CONSTRAINT items_type_check CHECK (item_type IN ('RAW', 'SEMI_FINISHED', 'SUPPORTING', 'PACKAGING', 'MENU'))");

        Schema::create('item_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items');
            $table->foreignId('unit_id')->constrained('units');
            $table->decimal('factor_to_base', 18, 4);
            $table->boolean('is_purchase_default')->default(false);
            $table->boolean('is_usage_default')->default(false);
            $table->timestampsTz();
            $table->unique(['item_id', 'unit_id']);
        });

        DB::statement('ALTER TABLE item_units ADD CONSTRAINT item_units_factor_positive CHECK (factor_to_base > 0)');

        Schema::create('item_min_stocks', function (Blueprint $table) {
            $table->foreignId('item_id')->constrained('items');
            $table->foreignId('location_id')->constrained('locations');
            $table->decimal('min_qty', 18, 4);
            $table->timestampsTz();
            $table->primary(['item_id', 'location_id']);
        });

        DB::statement('ALTER TABLE item_min_stocks ADD CONSTRAINT item_min_stocks_non_negative CHECK (min_qty >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('item_min_stocks');
        Schema::dropIfExists('item_units');
        Schema::dropIfExists('items');
        Schema::dropIfExists('units');
    }
};
