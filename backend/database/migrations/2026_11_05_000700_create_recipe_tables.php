<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Resep berversi (C2, KP-05, AB-08; dokumen 12 §5):
     * - Resep menu: barang setengah jadi + bahan pendukung + packaging → 1 porsi menu.
     * - Resep produksi (Production BOM): bahan → barang setengah jadi atau bahan pendukung buatan sendiri.
     * Satu resep per barang hasil. Versi dan isinya tidak diubah setelah dibuat (append-only).
     */
    public function up(): void
    {
        Schema::create('recipes', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20);
            $table->foreignId('output_item_id')->unique()->constrained('items');
            $table->foreignId('created_by')->constrained('users');
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE recipes ADD CONSTRAINT recipes_kind_check CHECK (kind IN ('MENU', 'PRODUCTION'))");

        Schema::create('recipe_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained('recipes');
            $table->unsignedInteger('version_no');
            $table->timestampTz('effective_from');
            // Hasil per resep dalam satuan dasar barang hasil (menu selalu 1 porsi).
            $table->decimal('output_qty', 18, 4);
            $table->foreignId('output_unit_id')->constrained('units');
            $table->decimal('output_entered_qty', 18, 4);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['recipe_id', 'version_no']);
            $table->index(['recipe_id', 'effective_from']);
        });

        DB::statement('ALTER TABLE recipe_versions ADD CONSTRAINT recipe_versions_output_positive CHECK (output_qty > 0 AND output_entered_qty > 0)');

        Schema::create('recipe_version_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_version_id')->constrained('recipe_versions');
            $table->foreignId('item_id')->constrained('items');
            // Jumlah dalam satuan dasar bahan; satuan dan angka yang diisi disimpan untuk tampilan.
            $table->decimal('qty', 18, 4);
            $table->foreignId('unit_id')->constrained('units');
            $table->decimal('entered_qty', 18, 4);
            $table->unsignedSmallInteger('position');
            $table->unique(['recipe_version_id', 'item_id']);
        });

        DB::statement('ALTER TABLE recipe_version_lines ADD CONSTRAINT recipe_lines_qty_positive CHECK (qty > 0 AND entered_qty > 0)');

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER recipe_versions_append_only BEFORE UPDATE OR DELETE ON recipe_versions FOR EACH ROW EXECUTE FUNCTION history_block_change();
            CREATE TRIGGER recipe_versions_no_truncate BEFORE TRUNCATE ON recipe_versions FOR EACH STATEMENT EXECUTE FUNCTION history_block_change();
            CREATE TRIGGER recipe_lines_append_only BEFORE UPDATE OR DELETE ON recipe_version_lines FOR EACH ROW EXECUTE FUNCTION history_block_change();
            CREATE TRIGGER recipe_lines_no_truncate BEFORE TRUNCATE ON recipe_version_lines FOR EACH STATEMENT EXECUTE FUNCTION history_block_change();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_version_lines');
        Schema::dropIfExists('recipe_versions');
        Schema::dropIfExists('recipes');
    }
};
