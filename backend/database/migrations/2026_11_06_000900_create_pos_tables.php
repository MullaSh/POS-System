<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * POS (irisan 5; dokumen 11 §3.1 nomor 5; keputusan Owner 6 Nov 2026).
     *
     * - cashier_shifts: shift kasir. Satu shift terbuka per outlet (OD-11). Tutup shift dengan hitung
     *   buta (OD-12): jumlah hitung dikunci begitu dikirim. Cash Float tetap di laci (KOD-01).
     * - sales: transaksi yang sudah dibayar. Harga jual dan HPP dikunci saat transaksi (KP-05, AB-09).
     * - sale_line_components: bahan yang dipotong per baris (C7), dasar HPP dan pembalikan stok.
     * - sale_payments: pembayaran per metode, boleh campur (C13; keputusan Owner 6 Nov 2026).
     * - sale_refunds: void/refund setelah bayar, berlaku setelah disetujui Owner atau delegasi (KP-17, AB-24).
     * - approval_delegations: delegasi sementara yang tercatat (2E-Q2; keputusan Owner 6 Nov 2026).
     *
     * Semua jumlah uang dalam rupiah bulat (dokumen 12 §2.3). Catatan berlaku tidak diedit atau
     * dihapus (3B-Q1): dikunci trigger database.
     */
    public function up(): void
    {
        Schema::create('cashier_shifts', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('location_id')->constrained('locations');
            $table->foreignId('cashier_id')->constrained('users');
            $table->string('status', 20);
            $table->timestampTz('opened_at');
            // Cash Float yang seharusnya ada di laci saat dibuka, dan hasil hitung kasir.
            $table->bigInteger('float_expected');
            $table->bigInteger('float_counted');
            $table->text('open_note')->nullable();
            // Hitung buta saat tutup (OD-12).
            $table->bigInteger('counted_cash')->nullable();
            $table->bigInteger('expected_cash')->nullable();
            $table->bigInteger('cash_difference')->nullable();
            $table->timestampTz('counted_at')->nullable();
            $table->text('close_note')->nullable();
            // Cash Float yang tetap di laci untuk shift berikutnya (KOD-01) dan Sales Cash siap diserahkan.
            $table->bigInteger('float_kept')->nullable();
            $table->bigInteger('sales_cash')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();
            $table->index(['location_id', 'opened_at']);
            $table->index(['cashier_id', 'opened_at']);
        });
        DB::statement("ALTER TABLE cashier_shifts ADD CONSTRAINT cashier_shifts_status_check CHECK (status IN ('OPEN', 'COUNTED', 'CLOSED'))");
        DB::statement('ALTER TABLE cashier_shifts ADD CONSTRAINT cashier_shifts_amounts CHECK (float_expected >= 0 AND float_counted >= 0 AND (counted_cash IS NULL OR counted_cash >= 0))');
        // Satu shift berjalan per outlet (satu laci; OD-11).
        DB::statement("CREATE UNIQUE INDEX cashier_shifts_one_running ON cashier_shifts (location_id) WHERE status IN ('OPEN', 'COUNTED')");

        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('location_id')->constrained('locations');
            $table->foreignId('shift_id')->constrained('cashier_shifts');
            $table->foreignId('cashier_id')->constrained('users');
            $table->string('channel', 20);
            $table->bigInteger('subtotal');
            // Tempat diskon dan pajak disiapkan, belum dipakai di MVP (OD-20, KP-16).
            $table->bigInteger('discount_total')->default(0);
            $table->bigInteger('tax_total')->default(0);
            $table->bigInteger('total');
            $table->bigInteger('paid_total');
            $table->bigInteger('change_total');
            // HPP dikunci saat transaksi (KP-05); kosong bila ada bahan tanpa harga pokok.
            $table->decimal('hpp_total', 18, 4)->nullable();
            $table->boolean('hpp_complete');
            $table->string('customer_note', 100)->nullable();
            $table->date('business_date');
            $table->timestampTz('paid_at');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['location_id', 'business_date']);
            $table->index(['shift_id']);
        });
        DB::statement("ALTER TABLE sales ADD CONSTRAINT sales_channel_check CHECK (channel IN ('DINE_IN', 'TAKEAWAY'))");
        DB::statement('ALTER TABLE sales ADD CONSTRAINT sales_amounts CHECK (subtotal > 0 AND discount_total = 0 AND tax_total = 0 AND total = subtotal - discount_total + tax_total AND paid_total = total AND change_total >= 0)');

        Schema::create('sale_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('sales');
            $table->foreignId('item_id')->constrained('items');
            $table->integer('qty');
            $table->bigInteger('unit_price');
            $table->bigInteger('line_total');
            $table->foreignId('item_price_id')->constrained('item_prices');
            $table->foreignId('recipe_version_id')->constrained('recipe_versions');
            $table->decimal('unit_hpp', 18, 4)->nullable();
            $table->decimal('hpp_total', 18, 4)->nullable();
            $table->unsignedSmallInteger('position');
            $table->unique(['sale_id', 'item_id']);
        });
        DB::statement('ALTER TABLE sale_lines ADD CONSTRAINT sale_lines_amounts CHECK (qty > 0 AND unit_price > 0 AND line_total = qty * unit_price)');

        Schema::create('sale_line_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_line_id')->constrained('sale_lines');
            $table->foreignId('item_id')->constrained('items');
            // Jumlah total untuk seluruh baris (porsi × takaran), dalam satuan dasar.
            $table->decimal('qty', 18, 4);
            // Harga pokok per satuan dasar saat transaksi (rata-rata outlet; KP-04, AB-09).
            $table->decimal('unit_cost', 18, 4)->nullable();
            $table->string('cost_source', 20)->nullable();
            $table->unique(['sale_line_id', 'item_id']);
        });
        DB::statement('ALTER TABLE sale_line_components ADD CONSTRAINT sale_line_components_qty CHECK (qty > 0)');

        Schema::create('sale_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('sales');
            $table->foreignId('payment_method_id')->constrained('payment_methods');
            $table->boolean('is_cash');
            // Bagian tagihan yang dibayar dengan metode ini.
            $table->bigInteger('amount');
            // Tunai: uang yang diterima dan kembaliannya.
            $table->bigInteger('received')->nullable();
            $table->bigInteger('change')->nullable();
            $table->string('reference', 60)->nullable();
            $table->unsignedSmallInteger('position');
        });
        DB::statement('ALTER TABLE sale_payments ADD CONSTRAINT sale_payments_amounts CHECK (amount > 0 AND (is_cash = false OR (received IS NOT NULL AND change IS NOT NULL AND received = amount + change AND change >= 0)) AND (is_cash = true OR (received IS NULL AND change IS NULL)))');
        DB::statement('CREATE UNIQUE INDEX sale_payments_one_per_method ON sale_payments (sale_id, payment_method_id)');

        Schema::create('sale_refunds', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('sale_id')->constrained('sales');
            $table->foreignId('location_id')->constrained('locations');
            // Shift tempat pengajuan dibuat; uang tunai dikembalikan dari laci shift ini.
            $table->foreignId('shift_id')->constrained('cashier_shifts');
            $table->string('reason_code', 30);
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('approval_request_id')->nullable()->constrained('approval_requests');
            $table->string('kind', 20)->nullable();
            $table->bigInteger('amount')->nullable();
            $table->timestampTz('applied_at')->nullable();
            $table->timestampsTz();
            $table->index(['sale_id']);
            $table->index(['shift_id']);
        });
        DB::statement("ALTER TABLE sale_refunds ADD CONSTRAINT sale_refunds_reason_check CHECK (reason_code IN ('WRONG_INPUT', 'CUSTOMER_CANCEL', 'PRODUCT_ISSUE', 'OTHER'))");
        DB::statement("ALTER TABLE sale_refunds ADD CONSTRAINT sale_refunds_kind_check CHECK (kind IS NULL OR kind IN ('VOID', 'REFUND'))");

        Schema::create('sale_refund_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_refund_id')->constrained('sale_refunds');
            $table->foreignId('sale_line_id')->constrained('sale_lines');
            $table->integer('qty');
            $table->bigInteger('amount');
            // NOT_MADE: stok kembali; MADE: makanan sudah dibuat/dibuang, stok tidak kembali (dilaporkan sebagai waste).
            $table->string('condition', 20);
            $table->decimal('hpp_total', 18, 4)->nullable();
            $table->unique(['sale_refund_id', 'sale_line_id']);
        });
        DB::statement("ALTER TABLE sale_refund_lines ADD CONSTRAINT sale_refund_lines_check CHECK (qty > 0 AND amount >= 0 AND condition IN ('NOT_MADE', 'MADE'))");

        Schema::create('sale_refund_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_refund_id')->constrained('sale_refunds');
            $table->foreignId('payment_method_id')->constrained('payment_methods');
            $table->boolean('is_cash');
            $table->bigInteger('amount');
        });
        DB::statement('ALTER TABLE sale_refund_payments ADD CONSTRAINT sale_refund_payments_amount CHECK (amount > 0)');

        Schema::create('approval_delegations', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('delegator_id')->constrained('users');
            $table->foreignId('delegator_role_id')->constrained('roles');
            $table->foreignId('delegate_id')->constrained('users');
            $table->jsonb('types');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->text('reason')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users');
            $table->timestampTz('revoked_at')->nullable();
            $table->text('revoke_note')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['delegate_id', 'ends_at']);
        });
        DB::statement('ALTER TABLE approval_delegations ADD CONSTRAINT approval_delegations_period CHECK (ends_at > starts_at AND delegator_id <> delegate_id)');

        Schema::table('approval_requests', function (Blueprint $table) {
            // Delegasi yang dipakai saat memutuskan (bila diputuskan oleh penerima delegasi).
            $table->foreignId('delegation_id')->nullable()->constrained('approval_delegations');
        });
        DB::statement('ALTER TABLE approval_requests DROP CONSTRAINT approval_requests_type_check');
        DB::statement("ALTER TABLE approval_requests ADD CONSTRAINT approval_requests_type_check CHECK (type IN ('ITEM_PRICE', 'STOCK_COUNT', 'SALE_REFUND'))");

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER sales_append_only BEFORE UPDATE OR DELETE ON sales FOR EACH ROW EXECUTE FUNCTION history_block_change();
            CREATE TRIGGER sales_no_truncate BEFORE TRUNCATE ON sales FOR EACH STATEMENT EXECUTE FUNCTION history_block_change();
            CREATE TRIGGER sale_lines_append_only BEFORE UPDATE OR DELETE ON sale_lines FOR EACH ROW EXECUTE FUNCTION history_block_change();
            CREATE TRIGGER sale_line_components_append_only BEFORE UPDATE OR DELETE ON sale_line_components FOR EACH ROW EXECUTE FUNCTION history_block_change();
            CREATE TRIGGER sale_payments_append_only BEFORE UPDATE OR DELETE ON sale_payments FOR EACH ROW EXECUTE FUNCTION history_block_change();
            CREATE TRIGGER sale_refund_lines_append_only BEFORE UPDATE OR DELETE ON sale_refund_lines FOR EACH ROW EXECUTE FUNCTION history_block_change();
            CREATE TRIGGER sale_refund_payments_append_only BEFORE UPDATE OR DELETE ON sale_refund_payments FOR EACH ROW EXECUTE FUNCTION history_block_change();

            -- Shift: hasil hitung dikunci begitu dikirim; shift yang sudah ditutup tidak berubah lagi.
            CREATE OR REPLACE FUNCTION cashier_shifts_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Shift tidak dapat dihapus'; END IF;
                IF OLD.status = 'CLOSED' THEN RAISE EXCEPTION 'Shift yang sudah ditutup tidak dapat diubah'; END IF;
                IF NEW.location_id <> OLD.location_id OR NEW.cashier_id <> OLD.cashier_id OR NEW.opened_at <> OLD.opened_at
                   OR NEW.float_expected <> OLD.float_expected OR NEW.float_counted <> OLD.float_counted THEN
                    RAISE EXCEPTION 'Data pembukaan shift tidak dapat diubah';
                END IF;
                IF OLD.counted_cash IS NOT NULL AND (NEW.counted_cash IS DISTINCT FROM OLD.counted_cash
                   OR NEW.expected_cash IS DISTINCT FROM OLD.expected_cash) THEN
                    RAISE EXCEPTION 'Hasil hitung uang yang sudah dikirim tidak dapat diubah';
                END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER cashier_shifts_guard BEFORE UPDATE OR DELETE ON cashier_shifts FOR EACH ROW EXECUTE FUNCTION cashier_shifts_guard();

            -- Pengajuan pembatalan: isi tetap; hasil (jenis, jumlah, waktu berlaku) diisi sekali.
            CREATE OR REPLACE FUNCTION sale_refunds_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Pengajuan pembatalan tidak dapat dihapus'; END IF;
                IF NEW.sale_id <> OLD.sale_id OR NEW.shift_id <> OLD.shift_id OR NEW.requested_by <> OLD.requested_by
                   OR NEW.location_id <> OLD.location_id THEN
                    RAISE EXCEPTION 'Data pengajuan pembatalan tidak dapat diubah';
                END IF;
                IF OLD.applied_at IS NOT NULL THEN RAISE EXCEPTION 'Pembatalan yang sudah berlaku tidak dapat diubah'; END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER sale_refunds_guard BEFORE UPDATE OR DELETE ON sale_refunds FOR EACH ROW EXECUTE FUNCTION sale_refunds_guard();

            -- Delegasi: isi tetap; hanya pencabutan yang boleh diisi, satu kali.
            CREATE OR REPLACE FUNCTION approval_delegations_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Delegasi tidak dapat dihapus; cabut delegasinya'; END IF;
                IF NEW.delegator_id <> OLD.delegator_id OR NEW.delegate_id <> OLD.delegate_id OR NEW.types <> OLD.types
                   OR NEW.starts_at <> OLD.starts_at OR NEW.ends_at <> OLD.ends_at OR NEW.delegator_role_id <> OLD.delegator_role_id THEN
                    RAISE EXCEPTION 'Isi delegasi tidak dapat diubah; cabut lalu buat delegasi baru';
                END IF;
                IF OLD.revoked_at IS NOT NULL THEN RAISE EXCEPTION 'Delegasi yang sudah dicabut tidak dapat diubah'; END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER approval_delegations_guard BEFORE UPDATE OR DELETE ON approval_delegations FOR EACH ROW EXECUTE FUNCTION approval_delegations_guard();
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE approval_requests DROP CONSTRAINT approval_requests_type_check');
        DB::statement("ALTER TABLE approval_requests ADD CONSTRAINT approval_requests_type_check CHECK (type IN ('ITEM_PRICE', 'STOCK_COUNT'))");
        Schema::table('approval_requests', fn (Blueprint $t) => $t->dropConstrainedForeignId('delegation_id'));
        foreach (['approval_delegations', 'sale_refund_payments', 'sale_refund_lines', 'sale_refunds', 'sale_payments', 'sale_line_components', 'sale_lines', 'sales', 'cashier_shifts'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::unprepared('DROP FUNCTION IF EXISTS cashier_shifts_guard(); DROP FUNCTION IF EXISTS sale_refunds_guard(); DROP FUNCTION IF EXISTS approval_delegations_guard();');
    }
};
