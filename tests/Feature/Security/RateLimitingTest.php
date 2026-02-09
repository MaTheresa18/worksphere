<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RateLimitingTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_is_strictly_rate_limited()
    {
        // Should be limited to 5 attempts per minute (throttle:password-reset)

        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/api/forgot-password', [
                'email' => 'test@example.com',
            ]);
            $this->assertNotEquals(429, $response->status(), "Request $i was rate limited prematurely.");
        }

        // The 6th attempt should fail with 429
        $response = $this->postJson('/api/forgot-password', [
            'email' => 'test@example.com',
        ]);

        $response->assertStatus(429);
    }
}
