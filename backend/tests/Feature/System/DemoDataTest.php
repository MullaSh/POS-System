<?php

namespace Tests\Feature\System;

use App\Modules\Production\Models\ProductionOrder;
use Database\Seeders\BaseDataSeeder;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Data contoh dapat diisi lengkap dan aman dijalankan ulang (uji jalan Docker memakai data ini). */
class DemoDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_data_seeds_and_is_idempotent(): void
    {
        $this->seed(BaseDataSeeder::class);
        $this->seed(DemoDataSeeder::class);
        $this->seed(DemoDataSeeder::class);

        $this->assertSame(1, ProductionOrder::query()->where('status', ProductionOrder::STATUS_COMPLETED)->count());
        $this->assertSame(1, ProductionOrder::query()->where('status', ProductionOrder::STATUS_PLANNED)->whereNotNull('stock_request_id')->count());
    }
}
