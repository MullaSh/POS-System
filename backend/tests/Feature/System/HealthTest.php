<?php

namespace Tests\Feature\System;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_endpoint_reports_database_ok(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJson([
                'status' => 'ok',
                'database' => 'ok',
                'zona_waktu' => 'Asia/Jakarta',
            ]);
    }

    public function test_root_points_to_api(): void
    {
        $this->getJson('/')
            ->assertOk()
            ->assertJsonStructure(['aplikasi', 'api']);
    }

    public function test_application_defaults_to_indonesian_locale(): void
    {
        $this->assertSame('id', config('app.locale'));
    }
}
