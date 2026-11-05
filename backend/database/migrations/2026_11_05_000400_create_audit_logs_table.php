<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catatan audit dengan unsur U1–U12 (10 v1.0 §3.3, dokumen 12 §4.4).
     * Append-only: tidak bisa diubah atau dihapus siapa pun, termasuk Owner
     * (3B-Q2). Dikunci dengan trigger di database.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->timestampTz('recorded_at')->useCurrent();          // U4
            $table->timestampTz('occurred_at')->nullable();             // U5
            $table->foreignId('user_id')->nullable()->constrained('users');      // U1
            $table->foreignId('role_id')->nullable()->constrained('roles');      // U2
            $table->foreignId('location_id')->nullable()->constrained('locations'); // U3
            $table->string('action', 60);                               // U6
            $table->string('subject_type', 60)->nullable();             // U7
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_code', 100)->nullable();
            $table->jsonb('changes')->nullable();                       // U8
            $table->text('reason')->nullable();                         // U9
            $table->jsonb('limits')->nullable();                        // U10
            $table->jsonb('flags')->default('[]');                      // U11
            $table->jsonb('related')->nullable();                       // U12
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->index('recorded_at');
            $table->index('user_id');
            $table->index('action');
            $table->index(['subject_type', 'subject_id']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_logs_block_change() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Catatan audit tidak dapat diubah atau dihapus (append-only)';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER audit_logs_no_update_delete
                BEFORE UPDATE OR DELETE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION audit_logs_block_change();

            CREATE TRIGGER audit_logs_no_truncate
                BEFORE TRUNCATE ON audit_logs
                FOR EACH STATEMENT EXECUTE FUNCTION audit_logs_block_change();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        DB::unprepared('DROP FUNCTION IF EXISTS audit_logs_block_change()');
    }
};
