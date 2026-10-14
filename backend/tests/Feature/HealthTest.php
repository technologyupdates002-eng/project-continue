<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\TestCase;

class HealthTest extends TestCase
{
    use RefreshDatabase;
    public function test_api_health_returns_ok_and_database_connected(): void
    {
        $response = $this->get('/api/health');

        $response->assertStatus(200)
            ->assertJson(fn (AssertableJson $json) => $json
                ->where('status', 'ok')
                ->where('database', 'connected')
                ->etc());
    }

    public function test_web_dashboard_loads(): void
    {
        $response = $this->get('/');
        $response->assertStatus(200);
        $response->assertSee('Kirinyaga');
    }

    public function test_system_info_reports_connected_services(): void
    {
        $response = $this->get('/api/system/info');

        $response->assertStatus(200)
            ->assertJsonStructure(['status', 'checks' => [
                'php_version', 'laravel_version', 'database',
                'cache_store', 'queue_connection', 'session_driver', 'redis',
            ]]);
    }
}
