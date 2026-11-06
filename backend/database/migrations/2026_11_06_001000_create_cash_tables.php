<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kas (irisan 6; C12, C14, C15; KP-12 s.d. KP-15; keputusan Owner 6 Nov 2026).
     *
     * - cash_handovers + cash_handover_items: serah terima Sales Cash Outlet → Gudang → Finance (C12, OD-17),
     *   dengan jumlah seharusnya, dilaporkan pengirim, dikonfirmasi penerima, dan selisih (PV-04).
     * - expenses: biaya operasional. Biaya outlet dibayar dari Petty Cash tanpa persetujuan di depan (R4/K-11);
     *   biaya gudang dan pusat dicatat dan dibayar Finance (G-02).
     * - petty_cash_movements: kartu Petty Cash per outlet (append-only).
     * - petty_cash_refills: isi ulang oleh Finance sebesar biaya yang terbukti (KP-14).
     * - noncash_reconciliations: pencocokan harian non-tunai per metode (KP-15, OD-16).
     * - discrepancy_resolutions: keputusan Finance atas setiap selisih (KP-12).
     */
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            // Saldo tetap Petty Cash outlet (KP-14); diatur Owner. Kosong = belum diatur.
            $table->bigInteger('petty_cash_amount')->nullable();
        });

        Schema::create('cash_handovers', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->string('stage', 30);
            $table->foreignId('from_location_id')->constrained('locations');
            $table->foreignId('to_location_id')->nullable()->constrained('locations');
            $table->string('status', 20);
            $table->bigInteger('expected_amount');
            $table->bigInteger('reported_amount');
            $table->foreignId('sender_id')->constrained('users');
            $table->foreignId('sender_role_id')->constrained('roles');
            $table->text('sender_note')->nullable();
            $table->timestampTz('sent_at');
            $table->bigInteger('received_amount')->nullable();
            $table->foreignId('receiver_id')->nullable()->constrained('users');
            $table->foreignId('receiver_role_id')->nullable()->constrained('roles');
            $table->text('receiver_note')->nullable();
            // Bukti wajib bila ada selisih (KOD-02, keputusan Owner 6 Nov 2026).
            $table->text('evidence_note')->nullable();
            $table->timestampTz('received_at')->nullable();
            $table->timestampsTz();
            $table->index(['stage', 'status']);
            $table->index(['from_location_id', 'sent_at']);
        });
        DB::statement("ALTER TABLE cash_handovers ADD CONSTRAINT cash_handovers_stage_check CHECK (stage IN ('OUTLET_TO_WAREHOUSE', 'WAREHOUSE_TO_FINANCE'))");
        DB::statement("ALTER TABLE cash_handovers ADD CONSTRAINT cash_handovers_status_check CHECK (status IN ('SENT', 'RECEIVED'))");
        DB::statement('ALTER TABLE cash_handovers ADD CONSTRAINT cash_handovers_amounts CHECK (expected_amount >= 0 AND reported_amount >= 0 AND (received_amount IS NULL OR received_amount >= 0))');
        DB::statement('ALTER TABLE cash_handovers ADD CONSTRAINT cash_handovers_two_people CHECK (receiver_id IS NULL OR receiver_id <> sender_id)');

        Schema::create('cash_handover_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_handover_id')->constrained('cash_handovers');
            $table->foreignId('shift_id')->nullable()->constrained('cashier_shifts');
            $table->foreignId('source_handover_id')->nullable()->constrained('cash_handovers');
            $table->bigInteger('amount');
        });
        DB::statement('ALTER TABLE cash_handover_items ADD CONSTRAINT cash_handover_items_one_source CHECK ((shift_id IS NULL) <> (source_handover_id IS NULL))');
        // Setiap shift dan setiap serah terima gudang hanya diserahkan sekali.
        DB::statement('CREATE UNIQUE INDEX cash_handover_items_shift ON cash_handover_items (shift_id) WHERE shift_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX cash_handover_items_source ON cash_handover_items (source_handover_id) WHERE source_handover_id IS NOT NULL');

        Schema::create('petty_cash_refills', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('location_id')->constrained('locations');
            $table->string('status', 20);
            $table->foreignId('requested_by')->nullable()->constrained('users');
            $table->text('request_note')->nullable();
            $table->timestampTz('requested_at')->nullable();
            $table->bigInteger('amount')->nullable();
            $table->bigInteger('verified_total')->nullable();
            $table->bigInteger('rejected_total')->nullable();
            $table->foreignId('refilled_by')->nullable()->constrained('users');
            $table->text('refill_note')->nullable();
            $table->timestampTz('refilled_at')->nullable();
            $table->timestampsTz();
            $table->index(['location_id', 'status']);
        });
        DB::statement("ALTER TABLE petty_cash_refills ADD CONSTRAINT petty_cash_refills_status_check CHECK (status IN ('REQUESTED', 'DONE'))");
        DB::statement("CREATE UNIQUE INDEX petty_cash_refills_one_request ON petty_cash_refills (location_id) WHERE status = 'REQUESTED'");

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('location_id')->constrained('locations');
            $table->foreignId('expense_category_id')->constrained('expense_categories');
            $table->bigInteger('amount');
            $table->text('description');
            $table->text('evidence_note')->nullable();
            $table->string('paid_from', 20);
            $table->string('status', 20);
            $table->foreignId('recorded_by')->constrained('users');
            $table->foreignId('role_id')->constrained('roles');
            $table->date('business_date');
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->foreignId('refill_id')->nullable()->constrained('petty_cash_refills');
            $table->timestampsTz();
            $table->index(['location_id', 'business_date']);
            $table->index(['location_id', 'status']);
        });
        DB::statement("ALTER TABLE expenses ADD CONSTRAINT expenses_paid_from_check CHECK (paid_from IN ('PETTY_CASH', 'FINANCE'))");
        DB::statement("ALTER TABLE expenses ADD CONSTRAINT expenses_status_check CHECK (status IN ('RECORDED', 'VERIFIED', 'REJECTED', 'CANCELLED'))");
        DB::statement('ALTER TABLE expenses ADD CONSTRAINT expenses_amount_positive CHECK (amount > 0)');

        Schema::create('petty_cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained('locations');
            $table->string('type', 20);
            $table->bigInteger('amount');
            $table->bigInteger('balance_after');
            $table->string('source_type', 40);
            $table->unsignedBigInteger('source_id');
            $table->string('source_number', 40);
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['location_id', 'id']);
        });
        DB::statement("ALTER TABLE petty_cash_movements ADD CONSTRAINT petty_cash_movements_type_check CHECK (type IN ('REFILL', 'EXPENSE', 'EXPENSE_CANCEL'))");
        DB::statement('ALTER TABLE petty_cash_movements ADD CONSTRAINT petty_cash_movements_amount CHECK (amount <> 0 AND balance_after >= 0)');

        Schema::create('noncash_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->date('business_date');
            $table->foreignId('payment_method_id')->constrained('payment_methods');
            $table->bigInteger('system_amount');
            $table->bigInteger('bank_amount');
            $table->bigInteger('difference');
            $table->text('note')->nullable();
            $table->foreignId('reconciled_by')->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['business_date', 'payment_method_id']);
        });
        DB::statement('ALTER TABLE noncash_reconciliations ADD CONSTRAINT noncash_reconciliations_check CHECK (bank_amount >= 0 AND difference = bank_amount - system_amount)');

        Schema::create('discrepancy_resolutions', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->text('decision');
            $table->foreignId('decided_by')->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['subject_type', 'subject_id']);
        });
        DB::statement("ALTER TABLE discrepancy_resolutions ADD CONSTRAINT discrepancy_resolutions_subject_check CHECK (subject_type IN ('cashier_shift', 'cash_handover', 'expense', 'noncash_reconciliation'))");

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER cash_handover_items_append_only BEFORE UPDATE OR DELETE ON cash_handover_items FOR EACH ROW EXECUTE FUNCTION history_block_change();
            CREATE TRIGGER petty_cash_movements_append_only BEFORE UPDATE OR DELETE ON petty_cash_movements FOR EACH ROW EXECUTE FUNCTION history_block_change();
            CREATE TRIGGER noncash_reconciliations_append_only BEFORE UPDATE OR DELETE ON noncash_reconciliations FOR EACH ROW EXECUTE FUNCTION history_block_change();
            CREATE TRIGGER discrepancy_resolutions_append_only BEFORE UPDATE OR DELETE ON discrepancy_resolutions FOR EACH ROW EXECUTE FUNCTION history_block_change();

            -- Serah terima: data kirim tetap; data terima diisi sekali (3C-Q1).
            CREATE OR REPLACE FUNCTION cash_handovers_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Serah terima kas tidak dapat dihapus'; END IF;
                IF NEW.stage <> OLD.stage OR NEW.from_location_id <> OLD.from_location_id OR NEW.to_location_id IS DISTINCT FROM OLD.to_location_id
                   OR NEW.expected_amount <> OLD.expected_amount OR NEW.reported_amount <> OLD.reported_amount
                   OR NEW.sender_id <> OLD.sender_id OR NEW.sent_at <> OLD.sent_at OR NEW.sender_note IS DISTINCT FROM OLD.sender_note THEN
                    RAISE EXCEPTION 'Data pengiriman kas tidak dapat diubah';
                END IF;
                IF OLD.status = 'RECEIVED' THEN RAISE EXCEPTION 'Jumlah yang sudah dikonfirmasi kedua pihak tidak dapat diubah'; END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER cash_handovers_guard BEFORE UPDATE OR DELETE ON cash_handovers FOR EACH ROW EXECUTE FUNCTION cash_handovers_guard();

            -- Biaya: isi tetap; status berubah sekali dari RECORDED.
            CREATE OR REPLACE FUNCTION expenses_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Biaya tidak dapat dihapus; batalkan dengan alasan'; END IF;
                IF NEW.location_id <> OLD.location_id OR NEW.expense_category_id <> OLD.expense_category_id OR NEW.amount <> OLD.amount
                   OR NEW.description <> OLD.description OR NEW.paid_from <> OLD.paid_from OR NEW.recorded_by <> OLD.recorded_by
                   OR NEW.business_date <> OLD.business_date OR NEW.evidence_note IS DISTINCT FROM OLD.evidence_note THEN
                    RAISE EXCEPTION 'Isi biaya tidak dapat diubah';
                END IF;
                IF OLD.status <> 'RECORDED' THEN RAISE EXCEPTION 'Biaya yang sudah diputuskan tidak dapat diubah'; END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER expenses_guard BEFORE UPDATE OR DELETE ON expenses FOR EACH ROW EXECUTE FUNCTION expenses_guard();

            -- Isi ulang Petty Cash: setelah selesai tidak berubah.
            CREATE OR REPLACE FUNCTION petty_cash_refills_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Isi ulang Petty Cash tidak dapat dihapus'; END IF;
                IF OLD.status = 'DONE' THEN RAISE EXCEPTION 'Isi ulang yang sudah selesai tidak dapat diubah'; END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER petty_cash_refills_guard BEFORE UPDATE OR DELETE ON petty_cash_refills FOR EACH ROW EXECUTE FUNCTION petty_cash_refills_guard();
        SQL);
    }

    public function down(): void
    {
        foreach (['discrepancy_resolutions', 'noncash_reconciliations', 'petty_cash_movements', 'expenses', 'petty_cash_refills', 'cash_handover_items', 'cash_handovers'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('locations', fn (Blueprint $t) => $t->dropColumn('petty_cash_amount'));
        DB::unprepared('DROP FUNCTION IF EXISTS cash_handovers_guard(); DROP FUNCTION IF EXISTS expenses_guard(); DROP FUNCTION IF EXISTS petty_cash_refills_guard();');
    }
};
