<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class DemoModeVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Setup permissions
        Permission::firstOrCreate(['name' => 'settings.update']);
        $role = Role::firstOrCreate(['name' => 'administrator']);
        $role->givePermissionTo('settings.update');
    }

    public function test_verify_demo_access_with_correct_password()
    {
        // Mock the config hash
        $secret = 'I AM GROOT, open demo mode';
        $hash = Hash::make($secret);
        Config::set('app.demo_mode_secret_hash', $hash);
        
        $user = User::factory()->create();
        $user->assignRole('administrator');
        
        // Authenticate
        $this->actingAs($user, 'sanctum');
        
        // Test correct password
        $response = $this->postJson('/api/settings/verify-demo', [
            'password' => $secret
        ]);
        
        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_verify_demo_access_with_incorrect_password()
    {
        // Mock the config hash
        $secret = 'I AM GROOT, open demo mode';
        $hash = Hash::make($secret);
        Config::set('app.demo_mode_secret_hash', $hash);
        
        $user = User::factory()->create();
        $user->assignRole('administrator');
        
        // Authenticate
        $this->actingAs($user, 'sanctum');
        
        // Test incorrect password
        $response = $this->postJson('/api/settings/verify-demo', [
            'password' => 'wrong password'
        ]);
        
        $response->assertStatus(403)
            ->assertJson(['success' => false]);
    }
    
    public function test_verify_demo_access_fails_if_password_missing()
    {
         $user = User::factory()->create();
         $user->assignRole('administrator');
         
         $this->actingAs($user, 'sanctum');
         
         $response = $this->postJson('/api/settings/verify-demo', []);
         
         $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_verify_demo_access_requires_authentication()
    {
        $secret = 'I AM GROOT, open demo mode';
        
        $response = $this->postJson('/api/settings/verify-demo', [
            'password' => $secret
        ]);
        
        $response->assertStatus(401);
    }

    public function test_verify_demo_access_requires_permissions()
    {
        $user = User::factory()->create();
        // No role/permission assigned
        
        $this->actingAs($user, 'sanctum');
        
        $response = $this->postJson('/api/settings/verify-demo', [
            'password' => 'any'
        ]);
        
        $response->assertStatus(403);
    }
}
