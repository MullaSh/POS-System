<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pembelian (irisan 7; AB-14 s.d. AB-17; KP-08, KP-10, KP-11; keputusan Owner 6 Nov 2026).
     * Lima dokumen terpisah (KP-08): PR → (persetujuan Finance) → PO → penerimaan + QC Incoming →
     * tagihan → pembayaran. PO di atas batas Finance disetujui Owner (2E-Q1a, OD-10).
     */
    public function up(): void
    {
        // Pengaturan aplikasi yang diubah Owner (mis. batas persetujuan Finance, OD-10).
        Schema::create('app_settings', function (Blueprint $table) {
            $table->string('key', 60)->primary();
            $table->jsonb('value');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestampsTz();
        });

        Schema::create('purchase_requests', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('location_id')->constrained('locations');
            $table->string('status', 20);
            $table->foreignId('requested_by')->constrained('users');
            $table->date('needed_by')->nullable();
            $table->foreignId('approval_request_id')->nullable()->constrained('approval_requests');
            $table->timestampTz('approved_at')->nullable();
            $table->text('close_note')->nullable();
            $table->timestampsTz();
            $table->index(['status']);
        });
        DB::statement("ALTER TABLE purchase_requests ADD CONSTRAINT purchase_requests_status_check CHECK (status IN ('PENDING', 'APPROVED', 'ORDERED', 'REJECTED', 'CANCELLED', 'CLOSED'))");

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('purchase_request_id')->constrained('purchase_requests');
            $table->foreignId('supplier_id')->constrained('suppliers');
            $table->foreignId('location_id')->constrained('locations');
            $table->string('status', 20);
            $table->bigInteger('total');
            $table->date('expected_date')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('approval_request_id')->nullable()->constrained('approval_requests');
            $table->timestampTz('ordered_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users');
            $table->timestampTz('closed_at')->nullable();
            $table->text('close_note')->nullable();
            $table->timestampsTz();
            $table->index(['status']);
            $table->index(['supplier_id']);
        });
        DB::statement("ALTER TABLE purchase_orders ADD CONSTRAINT purchase_orders_status_check CHECK (status IN ('WAITING_OWNER', 'ORDERED', 'PARTIAL', 'RECEIVED', 'CLOSED', 'REJECTED', 'CANCELLED'))");
        DB::statement('ALTER TABLE purchase_orders ADD CONSTRAINT purchase_orders_total CHECK (total > 0)');
        // Satu PO yang berlaku per PR (Proposed); PO yang ditolak atau dibatalkan membebaskan PR-nya.
        DB::statement("CREATE UNIQUE INDEX purchase_orders_one_per_request ON purchase_orders (purchase_request_id) WHERE status NOT IN ('REJECTED', 'CANCELLED')");

        Schema::create('purchase_lines', function (Blueprint $table) {
            $table->id();
            $table->string('document_type', 20);
            $table->unsignedBigInteger('document_id');
            $table->foreignId('item_id')->constrained('items');
            $table->foreignId('unit_id')->constrained('units');
            $table->decimal('qty', 18, 4);
            $table->decimal('factor', 18, 6);
            $table->decimal('qty_base', 18, 4);
            // Hanya PO: harga per satuan beli dan jumlah baris (rupiah).
            $table->bigInteger('unit_price')->nullable();
            $table->bigInteger('line_total')->nullable();
            $table->unsignedSmallInteger('position');
            $table->index(['document_type', 'document_id']);
        });
        DB::statement("ALTER TABLE purchase_lines ADD CONSTRAINT purchase_lines_check CHECK (document_type IN ('PR', 'PO') AND qty > 0 AND (document_type = 'PR' OR (unit_price > 0 AND line_total > 0)))");

        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders');
            $table->foreignId('location_id')->constrained('locations');
            $table->foreignId('received_by')->constrained('users');
            $table->string('supplier_doc', 60)->nullable();
            $table->text('note')->nullable();
            $table->bigInteger('accepted_value');
            $table->timestampTz('received_at');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['purchase_order_id']);
        });

        Schema::create('goods_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained('goods_receipts');
            $table->foreignId('purchase_line_id')->constrained('purchase_lines');
            $table->foreignId('item_id')->constrained('items');
            // Dalam satuan PO; QC Incoming (C17): diterima baik dan ditolak.
            $table->decimal('qty_accepted', 18, 4);
            $table->decimal('qty_rejected', 18, 4);
            $table->string('reject_reason', 30)->nullable();
            $table->text('reject_note')->nullable();
            $table->string('batch_no', 60)->nullable();
            $table->date('expiry_date')->nullable();
            $table->bigInteger('accepted_value');
        });
        DB::statement("ALTER TABLE goods_receipt_lines ADD CONSTRAINT goods_receipt_lines_check CHECK (qty_accepted >= 0 AND qty_rejected >= 0 AND qty_accepted + qty_rejected > 0 AND (qty_rejected = 0 OR reject_reason IN ('DAMAGED', 'EXPIRED', 'WRONG_ITEM', 'OTHER')))");

        Schema::create('supplier_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('goods_receipt_id')->unique()->constrained('goods_receipts');
            $table->foreignId('supplier_id')->constrained('suppliers');
            $table->string('invoice_no', 60);
            $table->date('invoice_date');
            $table->date('due_date');
            $table->bigInteger('expected_amount');
            $table->bigInteger('amount');
            $table->text('difference_note')->nullable();
            $table->foreignId('recorded_by')->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['supplier_id', 'due_date']);
        });
        DB::statement('ALTER TABLE supplier_invoices ADD CONSTRAINT supplier_invoices_check CHECK (amount > 0 AND due_date >= invoice_date AND (amount = expected_amount OR difference_note IS NOT NULL))');

        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('supplier_invoice_id')->constrained('supplier_invoices');
            $table->bigInteger('amount');
            $table->date('paid_date');
            $table->string('method', 20);
            $table->string('reference', 60)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('paid_by')->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['supplier_invoice_id']);
        });
        DB::statement("ALTER TABLE supplier_payments ADD CONSTRAINT supplier_payments_check CHECK (amount > 0 AND method IN ('TRANSFER', 'CASH'))");

        DB::statement('ALTER TABLE approval_requests DROP CONSTRAINT approval_requests_type_check');
        DB::statement("ALTER TABLE approval_requests ADD CONSTRAINT approval_requests_type_check CHECK (type IN ('ITEM_PRICE', 'STOCK_COUNT', 'SALE_REFUND', 'PURCHASE_REQUEST', 'PURCHASE_ORDER'))");

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER purchase_lines_append_only BEFORE UPDATE OR DELETE ON purchase_lines FOR EACH ROW EXECUTE FUNCTION history_block_change();
            CREATE TRIGGER goods_receipts_append_only BEFORE UPDATE OR DELETE ON goods_receipts FOR EACH ROW EXECUTE FUNCTION history_block_change();
            CREATE TRIGGER goods_receipt_lines_append_only BEFORE UPDATE OR DELETE ON goods_receipt_lines FOR EACH ROW EXECUTE FUNCTION history_block_change();
            CREATE TRIGGER supplier_invoices_append_only BEFORE UPDATE OR DELETE ON supplier_invoices FOR EACH ROW EXECUTE FUNCTION history_block_change();
            CREATE TRIGGER supplier_payments_append_only BEFORE UPDATE OR DELETE ON supplier_payments FOR EACH ROW EXECUTE FUNCTION history_block_change();

            -- PR dan PO: tidak dihapus; isi tetap (baris di purchase_lines). Status berakhir tidak berubah lagi.
            CREATE OR REPLACE FUNCTION purchase_docs_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Dokumen pembelian tidak dapat dihapus; batalkan atau tutup dengan alasan'; END IF;
                IF OLD.status IN ('REJECTED', 'CANCELLED', 'CLOSED') THEN RAISE EXCEPTION 'Dokumen pembelian yang sudah selesai tidak dapat diubah'; END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql;
            -- Kolom PO diperiksa di fungsi terpisah: plpgsql menolak NEW.total pada tabel PR.
            CREATE OR REPLACE FUNCTION purchase_orders_content_guard() RETURNS trigger AS $$
            BEGIN
                IF NEW.total <> OLD.total OR NEW.supplier_id <> OLD.supplier_id OR NEW.purchase_request_id <> OLD.purchase_request_id THEN
                    RAISE EXCEPTION 'Isi PO tidak dapat diubah';
                END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER purchase_requests_guard BEFORE UPDATE OR DELETE ON purchase_requests FOR EACH ROW EXECUTE FUNCTION purchase_docs_guard();
            CREATE TRIGGER purchase_orders_guard BEFORE UPDATE OR DELETE ON purchase_orders FOR EACH ROW EXECUTE FUNCTION purchase_docs_guard();
            CREATE TRIGGER purchase_orders_content_guard BEFORE UPDATE ON purchase_orders FOR EACH ROW EXECUTE FUNCTION purchase_orders_content_guard();
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE approval_requests DROP CONSTRAINT approval_requests_type_check');
        DB::statement("ALTER TABLE approval_requests ADD CONSTRAINT approval_requests_type_check CHECK (type IN ('ITEM_PRICE', 'STOCK_COUNT', 'SALE_REFUND'))");
        foreach (['supplier_payments', 'supplier_invoices', 'goods_receipt_lines', 'goods_receipts', 'purchase_lines', 'purchase_orders', 'purchase_requests', 'app_settings'] as $t) {
            Schema::dropIfExists($t);
        }
        DB::unprepared('DROP FUNCTION IF EXISTS purchase_docs_guard(); DROP FUNCTION IF EXISTS purchase_orders_content_guard();');
    }
};
