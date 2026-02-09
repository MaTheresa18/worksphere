<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\AppSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Clear settings cache to ensure clean state
        app(AppSettingsService::class)->clearCache();
    }

    public function test_demo_mode_blocks_delete_requests(): void
    {
        // Enable demo mode via setting
        Setting::setValue('app.is_demo_mode', true, ['type' => 'boolean']);
        app(AppSettingsService::class)->applyToConfig();

        $user = User::factory()->create();

        // Mock a delete request to a path that should be blocked
        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/user/avatar');

        $response->assertStatus(403);
        $response->assertJson([
            'message' => 'Action disabled in Demo Mode.',
            'is_demo' => true,
        ]);
    }

    public function test_demo_mode_blocks_settings_updates(): void
    {
        Setting::setValue('app.is_demo_mode', true, ['type' => 'boolean']);
        app(AppSettingsService::class)->applyToConfig();

        $user = User::factory()->create();
        $user->assignRole('administrator');

        $response = $this->actingAs($user, 'sanctum')
            ->putJson('/api/settings', [
                'app.name' => 'Hacked App',
            ]);

        $response->assertStatus(403);
    }

    public function test_demo_mode_allows_safe_requests(): void
    {
        Setting::setValue('app.is_demo_mode', true, ['type' => 'boolean']);
        app(AppSettingsService::class)->applyToConfig();

        $user = User::factory()->create();

        // GET requests should be allowed
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/user');

        $response->assertStatus(200);
        $this->assertTrue($response->json('data.is_demo_mode'));
    }

    public function test_demo_mode_is_off_by_default(): void
    {
        // Ensure no setting exists
        Setting::where('key', 'app.is_demo_mode')->delete();
        app(AppSettingsService::class)->clearCache();
        app(AppSettingsService::class)->applyToConfig();

        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/personal-tasks/some-mock-id');

        // Should NOT be 403 demo blocked. It might be 404 since the ID is mock, but not 403.
        $this->assertNotEquals(403, $response->status());
    }
}
