<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InvoiceTeamSelectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Setup necessary roles
        if (! Role::where('name', 'administrator')->exists()) {
            Role::create(['name' => 'administrator', 'guard_name' => 'web']);
        }
    }

    public function test_user_can_create_invoice_for_owned_team()
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->members()->attach($user);
        
        $client = Client::factory()->create(['team_id' => $team->id]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/teams/{$team->public_id}/invoices", [
            'client_id' => $client->public_id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'currency' => 'USD',
            'items' => [
                [
                    'description' => 'Test Item',
                    'quantity' => 1,
                    'unit_price' => 100,
                ],
            ],
            'notes' => 'Test Note',
            'terms' => 'Test Terms',
            'tax_rate' => 0,
            'discount_amount' => 0,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('invoices', [
             'team_id' => $team->id,
             'client_id' => $client->id,
             'notes' => 'Test Note',
        ]);
    }

    public function test_user_can_switch_teams_and_create_invoice()
    {
        $user = User::factory()->create();
        
        // Team A
        $teamA = Team::factory()->create(['owner_id' => $user->id]);
        $teamA->members()->attach($user);
        $clientA = Client::factory()->create(['team_id' => $teamA->id]);

        // Team B
        $teamB = Team::factory()->create(['owner_id' => $user->id]);
        $teamB->members()->attach($user);
        $clientB = Client::factory()->create(['team_id' => $teamB->id]);

        Sanctum::actingAs($user);

        // Create for Team B
        $response = $this->postJson("/api/teams/{$teamB->public_id}/invoices", [
            'client_id' => $clientB->public_id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'currency' => 'EUR',
            'items' => [
                [
                    'description' => 'Item for Team B',
                    'quantity' => 2,
                    'unit_price' => 50,
                ],
            ],
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('invoices', [
            'team_id' => $teamB->id,
            'client_id' => $clientB->id,
            'currency' => 'EUR',
        ]);

        // Verify it is NOT in Team A
        $this->assertDatabaseMissing('invoices', [
            'team_id' => $teamA->id,
            'currency' => 'EUR',
        ]);
    }

    public function test_user_cannot_create_invoice_for_non_member_team()
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(); // User not attached
        $client = Client::factory()->create(['team_id' => $team->id]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/teams/{$team->public_id}/invoices", [
            'client_id' => $client->public_id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'currency' => 'USD',
            'items' => [
                [
                    'description' => 'Test Item',
                    'quantity' => 1,
                    'unit_price' => 100,
                ],
            ],
        ]);

        // Should be forbidden or 404 (depending on middleware/policy order)
        // Usually Teams middleware throws 404 or 403
        $this->assertTrue(in_array($response->status(), [403, 404]));
    }
}
