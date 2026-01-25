<?php

namespace Tests\Feature;

use App\Models\EmailAccount;
use App\Models\User;
use App\Services\SystemEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemEmailAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Assume we have a user factory
        $this->user = User::factory()->create();

        // Mock EmailSyncService to avoid real connection attempts
        $this->mock(\App\Services\EmailSyncService::class, function ($mock) {
            $mock->shouldReceive('startSeed')->andReturn(true);
        });
    }

    public function test_can_create_system_email_account_with_usage()
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/email-accounts', [
                'name' => 'System Support',
                'email' => 'support@example.com',
                'provider' => 'custom',
                'auth_type' => 'password',
                'imap_host' => 'imap.example.com',
                'smtp_host' => 'smtp.example.com',
                'system_usage' => 'support',
                'password' => 'secret123',
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('email_accounts', [
            'email' => 'support@example.com',
            'is_system' => true,
            'system_usage' => 'support',
        ]);
    }

    public function test_system_usage_uniqueness_deactivates_others()
    {
        // Create first account
        $account1 = EmailAccount::create([
            'name' => 'Support 1',
            'email' => 'support1@example.com',
            'is_system' => true,
            'system_usage' => 'support',
            'is_active' => true,
            'provider' => 'custom',
            'auth_type' => 'password',
        ]);

        // Create second account via API
        $response = $this->actingAs($this->user)
            ->postJson('/api/email-accounts', [
                'name' => 'Support 2',
                'email' => 'support2@example.com',
                'provider' => 'custom',
                'auth_type' => 'password',
                'imap_host' => 'imap.example.com',
                'smtp_host' => 'smtp.example.com',
                'system_usage' => 'support',
                'password' => 'secret123',
            ]);

        $response->assertStatus(201);

        // Account 1 should be inactive
        $this->assertDatabaseHas('email_accounts', [
            'id' => $account1->id,
            'is_active' => false,
        ]);

        // Account 2 should be active
        $this->assertDatabaseHas('email_accounts', [
            'email' => 'support2@example.com',
            'is_active' => true,
            'system_usage' => 'support',
        ]);
    }

    public function test_can_retrieve_account_via_service()
    {
        $account = EmailAccount::create([
            'name' => 'Noreply',
            'email' => 'noreply@example.com',
            'is_system' => true,
            'system_usage' => 'noreply',
            'is_active' => true,
            'provider' => 'custom',
            'auth_type' => 'password',
        ]);

        $service = app(SystemEmailService::class);
        $retrieved = $service->getAccountForUsage('noreply');

        $this->assertNotNull($retrieved);
        $this->assertEquals($account->id, $retrieved->id);
    }
}
