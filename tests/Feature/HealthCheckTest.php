<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_liveness_endpoint_returns_success(): void
    {
        $response = $this->getJson('/health/live');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'ok',
            ]);
    }

    public function test_readiness_endpoint_checks_dependencies(): void
    {
        $response = $this->getJson('/health/ready');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'services' => [
                    'database',
                    'redis',
                ],
                'timestamp',
            ]);
    }
}
