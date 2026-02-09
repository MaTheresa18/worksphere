<?php

namespace Tests\Feature;

use App\Models\BlockedIp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SecurityDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'security.view', 'guard_name' => 'web']);
    }

    public function test_admin_can_access_security_dashboard_stats()
    {
        $admin = User::factory()->create();
        $admin->givePermissionTo('security.view');

        BlockedIp::create(['ip_address' => '10.0.0.1', 'reason' => 'Test Block']);
        BlockedIp::create(['ip_address' => '10.0.0.2', 'reason' => 'Test Block', 'expires_at' => now()->subDay()]); // Expired

        $response = $this->actingAs($admin)->getJson('/api/admin/security/stats');

        $response->assertStatus(200)
            ->assertJson([
                'blocked_ips' => 1,
            ]);
    }

    public function test_blocked_ip_middleware_blocks_access()
    {
        BlockedIp::create(['ip_address' => '127.0.0.1', 'reason' => 'Blocked for testing']);

        $response = $this->getJson('/api/user'); // Any route

        $response->assertStatus(403)
            ->assertSee('Your IP address has been blocked');
    }

    public function test_expired_blocked_ip_can_access()
    {
        BlockedIp::create([
            'ip_address' => '127.0.0.1',
            'reason' => 'Expired block',
            'expires_at' => now()->subMinute(),
        ]);

        $user = User::factory()->create();
        $response = $this->actingAs($user)->getJson('/api/user');

        $response->assertStatus(200);
    }

    public function test_admin_can_block_ip()
    {
        $admin = User::factory()->create();
        $admin->givePermissionTo('security.view'); // And permission to create if generalized, but controller checks 'create' policy which maps to what?
        // Note: Controller uses authorize('create', BlockedIp::class). We need a Policy or gate.
        // Assuming we didn't create a policy yet, Authorize might fail if not defined.
        // Looking at controller: $this->authorize('create', BlockedIp::class);
        // We probably need to register a policy or define the gate inside AuthServiceProvider.
        // Or if we relying on 'security.view' for all access in this MVP step?
        // Wait, I didn't create a Policy for BlockedIp! $this->authorize will look for one.
        // I should fix the controller to check permission directly or create the policy.

        // For now, let's assume I need to fix the controller authorization in the next step if this fails.
        // But let's write the test to surface that failure.

        $payload = [
            'ip_address' => '1.2.3.4',
            'reason' => 'Malicious',
        ];

        $response = $this->actingAs($admin)->postJson('/api/admin/security/blocked-ips', $payload);

        $response->assertStatus(201);

        $this->assertDatabaseHas('blocked_ips', [
            'ip_address' => '1.2.3.4',
            'reason' => 'Malicious',
            'blocked_by_user_id' => $admin->id,
        ]);
    }
}
