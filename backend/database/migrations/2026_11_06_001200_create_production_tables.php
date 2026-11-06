<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Produksi (irisan 8; C2, C17, KP-06, KP-07, AB-10, AB-11; keputusan Owner 6 Nov 2026, 22.15 WIB).
     * Production Order dua langkah: rencana → selesai (hasil, QC Production, pemakaian bahan sebenarnya).
     * Stok baru berubah saat selesai. Rencana dapat dibatalkan dengan alasan.
     */
    public function up(): void
    {
        // Umur simpan opsional untuk barang setengah jadi (diatur Owner), dipakai mengisi kedaluwarsa hasil produksi.
        Schema::table('items', function (Blueprint $table) {
            $table->unsignedSmallInteger('shelf_life_days')->nullable();
        });
        DB::statement("ALTER TABLE items ADD CONSTRAINT items_shelf_life_check CHECK (shelf_life_days IS NULL OR (shelf_life_days > 0 AND item_type = 'SEMI_FINISHED'))");

        Schema::create('production_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('location_id')->constrained('locations');
            $table->foreignId('output_item_id')->constrained('items');
            $table->foreignId('recipe_version_id')->constrained('recipe_versions');
            $table->foreignId('stock_request_id')->nullable()->constrained('stock_requests');
            $table->string('status', 20);
            // Target dalam satuan yang dipilih saat merencanakan; planned_qty dalam satuan dasar.
            $table->foreignId('unit_id')->constrained('units');
            $table->decimal('factor', 18, 4);
            $table->decimal('planned_qty', 18, 4);
            $table->date('planned_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            // Diisi saat selesai.
            $table->decimal('good_qty', 18, 4)->nullable();
            $table->decimal('failed_qty', 18, 4)->nullable();
            $table->decimal('total_cost', 20, 4)->nullable();
            $table->decimal('unit_cost', 20, 4)->nullable();
            $table->boolean('cost_complete')->nullable();
            $table->decimal('failed_value', 20, 4)->nullable();
            $table->string('batch_no', 60)->nullable();
            $table->date('expiry_date')->nullable();
            $table->decimal('tolerance_pct', 6, 2)->nullable();
            $table->decimal('output_variance_pct', 10, 2)->nullable();
            $table->boolean('over_tolerance')->nullable();
            $table->text('variance_note')->nullable();
            $table->text('completion_note')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users');
            $table->timestampTz('completed_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users');
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestampsTz();
            $table->index(['status']);
            $table->index(['output_item_id']);
            $table->index(['stock_request_id']);
        });
        DB::statement("ALTER TABLE production_orders ADD CONSTRAINT production_orders_status_check CHECK (status IN ('PLANNED', 'COMPLETED', 'CANCELLED'))");
        DB::statement('ALTER TABLE production_orders ADD CONSTRAINT production_orders_planned_qty CHECK (planned_qty > 0 AND factor > 0)');
        DB::statement("ALTER TABLE production_orders ADD CONSTRAINT production_orders_completed_check CHECK (status <> 'COMPLETED' OR (good_qty >= 0 AND failed_qty >= 0 AND good_qty + failed_qty > 0 AND completed_at IS NOT NULL AND (over_tolerance = false OR variance_note IS NOT NULL)))");
        DB::statement("ALTER TABLE production_orders ADD CONSTRAINT production_orders_cancel_check CHECK (status <> 'CANCELLED' OR cancel_reason IS NOT NULL)");

        Schema::create('production_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_order_id')->constrained('production_orders');
            $table->foreignId('item_id')->constrained('items');
            // Satuan tampilan mengikuti resep; jumlah disimpan juga dalam satuan dasar bahan.
            $table->foreignId('unit_id')->constrained('units');
            $table->decimal('factor', 18, 4);
            $table->decimal('standard_qty', 18, 4);
            $table->decimal('actual_qty', 18, 4)->nullable();
            $table->decimal('cost', 20, 4)->nullable();
            $table->decimal('variance_pct', 10, 2)->nullable();
            $table->unsignedSmallInteger('position');
            $table->unique(['production_order_id', 'item_id']);
        });
        DB::statement('ALTER TABLE production_order_lines ADD CONSTRAINT production_order_lines_check CHECK (standard_qty > 0 AND factor > 0 AND (actual_qty IS NULL OR actual_qty >= 0))');

        // QC Production: hasil gagal per alasan baku. Tidak pernah masuk stok; dilaporkan sebagai waste produksi.
        Schema::create('production_failures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_order_id')->constrained('production_orders');
            $table->string('reason', 30);
            $table->decimal('qty', 18, 4);
            $table->text('note')->nullable();
        });
        DB::statement("ALTER TABLE production_failures ADD CONSTRAINT production_failures_check CHECK (qty > 0 AND reason IN ('MISSHAPEN', 'UNEVEN_COOK', 'PACKAGING_LEAK', 'OTHER') AND (reason <> 'OTHER' OR note IS NOT NULL))");

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER production_failures_append_only BEFORE UPDATE OR DELETE ON production_failures FOR EACH ROW EXECUTE FUNCTION history_block_change();

            -- Production Order: tidak dihapus; isi rencana tetap; setelah selesai atau batal tidak berubah lagi.
            CREATE OR REPLACE FUNCTION production_orders_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Production Order tidak dapat dihapus; batalkan dengan alasan'; END IF;
                IF OLD.status IN ('COMPLETED', 'CANCELLED') THEN RAISE EXCEPTION 'Production Order yang sudah selesai atau dibatalkan tidak dapat diubah'; END IF;
                IF NEW.planned_qty <> OLD.planned_qty OR NEW.output_item_id <> OLD.output_item_id OR NEW.recipe_version_id <> OLD.recipe_version_id
                    OR NEW.location_id <> OLD.location_id THEN
                    RAISE EXCEPTION 'Isi rencana produksi tidak dapat diubah; batalkan dan buat rencana baru';
                END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER production_orders_guard BEFORE UPDATE OR DELETE ON production_orders FOR EACH ROW EXECUTE FUNCTION production_orders_guard();

            -- Baris bahan: standar tetap; pemakaian sebenarnya diisi satu kali saat selesai.
            CREATE OR REPLACE FUNCTION production_order_lines_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Baris bahan produksi tidak dapat dihapus'; END IF;
                IF OLD.actual_qty IS NOT NULL OR NEW.standard_qty <> OLD.standard_qty OR NEW.item_id <> OLD.item_id
                    OR NEW.production_order_id <> OLD.production_order_id OR NEW.factor <> OLD.factor THEN
                    RAISE EXCEPTION 'Baris bahan produksi tidak dapat diubah';
                END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER production_order_lines_guard BEFORE UPDATE OR DELETE ON production_order_lines FOR EACH ROW EXECUTE FUNCTION production_order_lines_guard();
        SQL);
    }

    public function down(): void
    {
        foreach (['production_failures', 'production_order_lines', 'production_orders'] as $t) {
            Schema::dropIfExists($t);
        }
        DB::unprepared('DROP FUNCTION IF EXISTS production_orders_guard(); DROP FUNCTION IF EXISTS production_order_lines_guard();');
        DB::statement('ALTER TABLE items DROP CONSTRAINT IF EXISTS items_shelf_life_check');
        Schema::table('items', fn (Blueprint $table) => $table->dropColumn('shelf_life_days'));
    }
};
