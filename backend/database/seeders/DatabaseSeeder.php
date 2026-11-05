<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Data awal yang selalu dibutuhkan, ditambah data contoh bila SEED_DEMO=true.
     * Aman dijalankan berulang kali: data yang sudah ada tidak ditimpa.
     */
    public function run(): void
    {
        $this->call(BaseDataSeeder::class);

        if (filter_var(env('SEED_DEMO', false), FILTER_VALIDATE_BOOL)) {
            $this->call(DemoDataSeeder::class);
        }
    }
}
