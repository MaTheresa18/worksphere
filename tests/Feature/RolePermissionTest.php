<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RolePermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_editing_restricted_role_requires_approval(): void
    {
        // administrator is a restricted role in config/roles.php
        $adminRole = Role::findByName('administrator');
        $user = User::factory()->create();
        $user->assignRole('administrator');

        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/roles/{$adminRole->id}", [
                'name' => 'Renamed Admin',
            ]);

        // Should return 202 Accepted (Request Created)
        $response->assertStatus(202);
        $this->assertDatabaseHas('role_change_requests', [
            'type' => 'role_title_change',
            'status' => 'pending',
        ]);
    }

    public function test_editing_regular_role_now_requires_approval(): void
    {
        $userRole = Role::findByName('user');
        $user = User::factory()->create();
        $user->assignRole('administrator');

        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/roles/{$userRole->id}", [
                'name' => 'Renamed User',
            ]);

        // Should now return 202 Accepted (Request Created)
        $response->assertStatus(202);
    }
}
