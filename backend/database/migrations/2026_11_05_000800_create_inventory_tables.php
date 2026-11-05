<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Inventory (irisan 4; dokumen 12 §5; AB-03 s.d. AB-07, AB-12, AB-13).
     *
     * - stock_movements: kartu stok, satu baris per pergerakan, append-only (3B-Q1).
     * - stock_balances: saldo dan harga pokok rata-rata per barang per lokasi (KP-04); boleh minus (KP-02).
     * - stock_batches: batch dan kedaluwarsa, hanya di gudang (KP-03).
     * - stock_entries: saldo awal dan waste (dokumen satu langkah).
     * - stock_counts: stock opname; penyesuaian berlaku setelah disetujui Owner (OD-21, 5 Nov 2026).
     * - stock_requests: permintaan stok dari outlet (KP-08, KP-09).
     * - stock_transfers: kirim gudang → outlet dan retur outlet → gudang (AB-13; G-03, 5 Nov 2026).
     */
    public function up(): void
    {
        Schema::create('stock_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items');
            $table->foreignId('location_id')->constrained('locations');
            $table->string('batch_no', 60);
            $table->date('expiry_date')->nullable();
            $table->decimal('qty_remaining', 18, 4)->default(0);
            $table->string('source', 30);
            $table->timestampsTz();
            $table->unique(['item_id', 'location_id', 'batch_no']);
            $table->index(['item_id', 'location_id', 'expiry_date']);
        });

        Schema::create('stock_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items');
            $table->foreignId('location_id')->constrained('locations');
            $table->decimal('qty', 18, 4)->default(0);
            // Harga pokok rata-rata per satuan dasar; kosong bila belum pernah ada barang masuk berharga.
            $table->decimal('avg_cost', 18, 4)->nullable();
            $table->timestampTz('last_movement_at')->nullable();
            $table->timestampsTz();
            $table->unique(['item_id', 'location_id']);
        });

        Schema::create('stock_entries', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->string('kind', 20);
            $table->foreignId('location_id')->constrained('locations');
            $table->string('reason_code', 30)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('role_id')->constrained('roles');
            $table->date('business_date');
            $table->timestampTz('created_at')->useCurrent();
        });
        DB::statement("ALTER TABLE stock_entries ADD CONSTRAINT stock_entries_kind_check CHECK (kind IN ('OPENING', 'WASTE'))");

        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('location_id')->constrained('locations');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('role_id')->constrained('roles');
            $table->foreignId('approval_request_id')->nullable()->constrained('approval_requests');
            $table->timestampTz('applied_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('stock_requests', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('location_id')->constrained('locations');
            $table->string('status', 20);
            $table->date('needed_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('closed_by')->nullable()->constrained('users');
            $table->timestampTz('closed_at')->nullable();
            $table->text('close_note')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'location_id']);
        });
        DB::statement("ALTER TABLE stock_requests ADD CONSTRAINT stock_requests_status_check CHECK (status IN ('SUBMITTED', 'IN_TRANSIT', 'COMPLETED', 'REJECTED', 'CANCELLED'))");

        Schema::create('stock_request_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_request_id')->constrained('stock_requests');
            $table->foreignId('item_id')->constrained('items');
            $table->decimal('qty', 18, 4);
            $table->foreignId('unit_id')->constrained('units');
            $table->decimal('factor', 18, 4);
            $table->unsignedSmallInteger('position');
            $table->unique(['stock_request_id', 'item_id']);
        });
        DB::statement('ALTER TABLE stock_request_lines ADD CONSTRAINT stock_request_lines_qty_positive CHECK (qty > 0)');

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->string('kind', 20);
            $table->foreignId('from_location_id')->constrained('locations');
            $table->foreignId('to_location_id')->constrained('locations');
            $table->foreignId('stock_request_id')->nullable()->constrained('stock_requests');
            $table->string('status', 20);
            $table->string('reason_code', 30)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('sent_by')->constrained('users');
            $table->timestampTz('sent_at');
            $table->foreignId('received_by')->nullable()->constrained('users');
            $table->timestampTz('received_at')->nullable();
            $table->text('receive_note')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users');
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancel_note')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'to_location_id']);
            $table->index(['status', 'from_location_id']);
        });
        DB::statement("ALTER TABLE stock_transfers ADD CONSTRAINT stock_transfers_kind_check CHECK (kind IN ('DISTRIBUTION', 'RETURN'))");
        DB::statement("ALTER TABLE stock_transfers ADD CONSTRAINT stock_transfers_status_check CHECK (status IN ('IN_TRANSIT', 'RECEIVED', 'CANCELLED'))");
        DB::statement('ALTER TABLE stock_transfers ADD CONSTRAINT stock_transfers_different_locations CHECK (from_location_id <> to_location_id)');
        // Satu Stock Request dipenuhi oleh satu pengiriman.
        DB::statement("CREATE UNIQUE INDEX stock_transfers_one_per_request ON stock_transfers (stock_request_id) WHERE stock_request_id IS NOT NULL AND status <> 'CANCELLED'");

        Schema::create('stock_transfer_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained('stock_transfers');
            $table->foreignId('item_id')->constrained('items');
            $table->foreignId('unit_id')->constrained('units');
            $table->decimal('factor', 18, 4);
            $table->decimal('sent_qty', 18, 4);
            // Harga pokok rata-rata lokasi pengirim saat dikirim (dokumen 12 §5 butir 3).
            $table->decimal('unit_cost', 18, 4)->nullable();
            $table->decimal('received_qty', 18, 4)->nullable();
            // Selisih = dikirim - diterima. Dijelaskan oleh pengirim (OD-18, 5 Nov 2026).
            $table->string('resolution', 30)->nullable();
            $table->text('resolution_note')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users');
            $table->timestampTz('resolved_at')->nullable();
            $table->unsignedSmallInteger('position');
            $table->unique(['stock_transfer_id', 'item_id']);
        });
        DB::statement('ALTER TABLE stock_transfer_lines ADD CONSTRAINT stock_transfer_lines_qty CHECK (sent_qty > 0 AND (received_qty IS NULL OR received_qty >= 0))');
        DB::statement("ALTER TABLE stock_transfer_lines ADD CONSTRAINT stock_transfer_lines_resolution_check CHECK (resolution IS NULL OR resolution IN ('SHORT_SHIPPED', 'LOST_IN_TRANSIT', 'OVER_SENT'))");

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items');
            $table->foreignId('location_id')->constrained('locations');
            $table->foreignId('batch_id')->nullable()->constrained('stock_batches');
            $table->string('type', 30);
            // Jumlah bertanda dalam satuan dasar: + masuk, - keluar.
            $table->decimal('qty', 18, 4);
            $table->decimal('unit_cost', 18, 4)->nullable();
            $table->decimal('value', 18, 4)->nullable();
            $table->decimal('balance_after', 18, 4);
            $table->decimal('avg_cost_after', 18, 4)->nullable();
            $table->string('source_type', 40);
            $table->unsignedBigInteger('source_id');
            $table->string('source_number', 40);
            $table->text('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->date('business_date');
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['item_id', 'location_id', 'id']);
            $table->index(['location_id', 'business_date']);
            $table->index(['source_type', 'source_id']);
        });
        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_type_check CHECK (type IN (
            'OPENING', 'PURCHASE_RECEIPT', 'PRODUCTION_CONSUME', 'PRODUCTION_OUTPUT',
            'TRANSFER_OUT', 'TRANSFER_IN', 'TRANSFER_CANCEL', 'TRANSFER_SHORT_RETURN', 'TRANSFER_OVER_SENT',
            'SALE', 'SALE_REVERSAL', 'WASTE', 'ADJUSTMENT', 'RETURN'))");
        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_qty_not_zero CHECK (qty <> 0)');

        // Kartu stok dan dokumen satu langkah tidak diubah atau dihapus (AB-03, 3B-Q1).
        // Baris transfer: jumlah kirim tetap; jumlah terima dan penjelasan selisih diisi sekali (3C-Q1).
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER stock_movements_append_only BEFORE UPDATE OR DELETE ON stock_movements FOR EACH ROW EXECUTE FUNCTION history_block_change();
            CREATE TRIGGER stock_movements_no_truncate BEFORE TRUNCATE ON stock_movements FOR EACH STATEMENT EXECUTE FUNCTION history_block_change();
            CREATE TRIGGER stock_entries_append_only BEFORE UPDATE OR DELETE ON stock_entries FOR EACH ROW EXECUTE FUNCTION history_block_change();
            CREATE TRIGGER stock_entries_no_truncate BEFORE TRUNCATE ON stock_entries FOR EACH STATEMENT EXECUTE FUNCTION history_block_change();
            CREATE TRIGGER stock_request_lines_append_only BEFORE UPDATE OR DELETE ON stock_request_lines FOR EACH ROW EXECUTE FUNCTION history_block_change();

            CREATE OR REPLACE FUNCTION stock_transfer_lines_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Baris pengiriman tidak dapat dihapus';
                END IF;
                IF NEW.item_id <> OLD.item_id OR NEW.sent_qty <> OLD.sent_qty OR NEW.unit_cost IS DISTINCT FROM OLD.unit_cost
                   OR NEW.factor <> OLD.factor OR NEW.unit_id <> OLD.unit_id THEN
                    RAISE EXCEPTION 'Jumlah dan harga kirim tidak dapat diubah';
                END IF;
                IF OLD.received_qty IS NOT NULL AND NEW.received_qty IS DISTINCT FROM OLD.received_qty THEN
                    RAISE EXCEPTION 'Jumlah terima yang sudah dikonfirmasi tidak dapat diubah';
                END IF;
                IF OLD.resolution IS NOT NULL AND (NEW.resolution IS DISTINCT FROM OLD.resolution OR NEW.resolution_note IS DISTINCT FROM OLD.resolution_note) THEN
                    RAISE EXCEPTION 'Penjelasan selisih yang sudah dicatat tidak dapat diubah';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER stock_transfer_lines_guard BEFORE UPDATE OR DELETE ON stock_transfer_lines FOR EACH ROW EXECUTE FUNCTION stock_transfer_lines_guard();
        SQL);

        // Jenis persetujuan baru: stock opname (OD-21).
        DB::statement('ALTER TABLE approval_requests DROP CONSTRAINT approval_requests_type_check');
        DB::statement("ALTER TABLE approval_requests ADD CONSTRAINT approval_requests_type_check CHECK (type IN ('ITEM_PRICE', 'STOCK_COUNT'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE approval_requests DROP CONSTRAINT approval_requests_type_check');
        DB::statement("ALTER TABLE approval_requests ADD CONSTRAINT approval_requests_type_check CHECK (type IN ('ITEM_PRICE'))");
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_transfer_lines');
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('stock_request_lines');
        Schema::dropIfExists('stock_requests');
        Schema::dropIfExists('stock_counts');
        Schema::dropIfExists('stock_entries');
        Schema::dropIfExists('stock_balances');
        Schema::dropIfExists('stock_batches');
        DB::unprepared('DROP FUNCTION IF EXISTS stock_transfer_lines_guard()');
    }
};
