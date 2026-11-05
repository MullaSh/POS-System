<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nomor dokumen (dokumen 12 P8), pusat persetujuan (06; dokumen 11 §3.1 nomor 11),
     * dan riwayat harga jual menu (KP-16, AB-23, 2E-Q1b).
     */
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->string('key', 60)->primary();
            $table->unsignedInteger('last_number')->default(0);
        });

        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->string('type', 40);
            $table->string('status', 20);
            $table->string('subject_type', 60);
            $table->unsignedBigInteger('subject_id');
            $table->jsonb('payload');
            $table->text('reason')->nullable();
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('requester_role_id')->constrained('roles');
            $table->timestampTz('submitted_at');
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'type']);
            $table->index(['subject_type', 'subject_id']);
        });

        DB::statement("ALTER TABLE approval_requests ADD CONSTRAINT approval_requests_status_check CHECK (status IN ('PENDING', 'RETURNED', 'APPROVED', 'REJECTED', 'CANCELLED'))");
        DB::statement("ALTER TABLE approval_requests ADD CONSTRAINT approval_requests_type_check CHECK (type IN ('ITEM_PRICE'))");
        // Satu pengajuan terbuka per catatan per jenis.
        DB::statement("CREATE UNIQUE INDEX approval_requests_one_open ON approval_requests (type, subject_type, subject_id) WHERE status IN ('PENDING', 'RETURNED')");

        Schema::create('approval_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_request_id')->constrained('approval_requests');
            $table->string('action', 20);
            $table->foreignId('user_id')->constrained('users');
            $table->text('note')->nullable();
            $table->jsonb('payload')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement("ALTER TABLE approval_events ADD CONSTRAINT approval_events_action_check CHECK (action IN ('SUBMITTED', 'RESUBMITTED', 'APPROVED', 'REJECTED', 'RETURNED', 'CANCELLED'))");

        Schema::create('item_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items');
            $table->bigInteger('price');
            $table->timestampTz('effective_from');
            $table->foreignId('approval_request_id')->nullable()->constrained('approval_requests');
            $table->foreignId('set_by')->constrained('users');
            $table->text('reason')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['item_id', 'effective_from']);
        });

        DB::statement('ALTER TABLE item_prices ADD CONSTRAINT item_prices_non_negative CHECK (price >= 0)');

        // Riwayat persetujuan dan harga tidak diubah atau dihapus (3B-Q1; Project Instructions §11).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION history_block_change() RETURNS trigger AS $$
            BEGIN RAISE EXCEPTION 'Riwayat tidak dapat diubah atau dihapus (append-only)'; END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER approval_events_append_only BEFORE UPDATE OR DELETE ON approval_events FOR EACH ROW EXECUTE FUNCTION history_block_change();
            CREATE TRIGGER approval_events_no_truncate BEFORE TRUNCATE ON approval_events FOR EACH STATEMENT EXECUTE FUNCTION history_block_change();
            CREATE TRIGGER item_prices_append_only BEFORE UPDATE OR DELETE ON item_prices FOR EACH ROW EXECUTE FUNCTION history_block_change();
            CREATE TRIGGER item_prices_no_truncate BEFORE TRUNCATE ON item_prices FOR EACH STATEMENT EXECUTE FUNCTION history_block_change();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('item_prices');
        Schema::dropIfExists('approval_events');
        Schema::dropIfExists('approval_requests');
        Schema::dropIfExists('document_sequences');
        DB::unprepared('DROP FUNCTION IF EXISTS history_block_change()');
    }
};
