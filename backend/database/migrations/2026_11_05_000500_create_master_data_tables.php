<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Supplier (AB-17), metode bayar (C13, KP-15), kategori biaya (C15).
     * Data master tidak dihapus, hanya dinonaktifkan (dokumen 12 P9).
     */
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->string('contact_name', 100)->nullable();
            $table->string('phone', 30)->nullable();
            $table->text('address')->nullable();
            $table->unsignedSmallInteger('payment_term_days')->default(0);
            $table->string('bank_account', 200)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE suppliers ADD CONSTRAINT suppliers_term_check CHECK (payment_term_days BETWEEN 0 AND 365)');

        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            $table->boolean('is_cash')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        // Tepat satu metode tunai: dana tunai dihitung di laci kasir (C14).
        DB::statement('CREATE UNIQUE INDEX payment_methods_one_cash ON payment_methods (is_cash) WHERE is_cash');

        $now = now();
        DB::table('payment_methods')->insert([
            ['code' => 'CASH', 'name' => 'Tunai', 'is_cash' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'QRIS', 'name' => 'QRIS', 'is_cash' => false, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'TRANSFER', 'name' => 'Transfer bank', 'is_cash' => false, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'EWALLET', 'name' => 'E-wallet', 'is_cash' => false, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_categories');
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('suppliers');
    }
};
