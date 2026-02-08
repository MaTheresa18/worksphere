<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class DevToolsSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_dev_routes_accessible_in_local_environment()
    {
        // Simulate local environment
        $this->app['env'] = 'local';

        $response = $this->getJson('/api/dev/users');
        
        // Should be 200 OK
        $response->assertStatus(200);
    }

    public function test_dev_routes_forbidden_in_production_environment()
    {
        // Simulate production environment
        // Note: app()->environment() checks the config, so we might need to mock it effectively
        // or rely on how the middleware checks it. 
        // Force the check in middleware to fail.
        
        $this->app['env'] = 'production';

        $response = $this->getJson('/api/dev/users');
        
        // Should be 403 Forbidden or 404 Not Found (if route is not registered)
        // Since the route registration itself is conditional in api.php, 
        // it might return 404 in a real production app.
        // The middleware should block it with 403.
        $response->assertStatus(403); 
    }
}
