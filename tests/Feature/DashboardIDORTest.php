<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardIDORTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_access_own_team_dashboard()
    {
        $user = User::factory()->create();
        $team = Team::factory()->create([
            'owner_id' => $user->id,
            'public_id' => 'team-a',
        ]);
        $user->teams()->attach($team, ['role' => 'admin']);

        $response = $this->actingAs($user)
            ->getJson('/api/dashboard?team_id='.$team->public_id);

        $response->assertStatus(200);
    }

    public function test_user_cannot_access_other_team_dashboard()
    {
        $user = User::factory()->create();
        $teamA = Team::factory()->create([
            'owner_id' => $user->id,
            'public_id' => 'team-a',
        ]);
        $user->teams()->attach($teamA, ['role' => 'admin']);

        $otherUser = User::factory()->create();
        $teamB = Team::factory()->create([
            'owner_id' => $otherUser->id,
            'public_id' => 'team-b',
        ]);

        // User tries to access Team B's dashboard
        $response = $this->actingAs($user)
            ->getJson('/api/dashboard?team_id='.$teamB->public_id);

        // Expect 403 Forbidden
        $response->assertStatus(403);
    }

    public function test_dashboard_defaults_to_users_team_if_no_id_provided()
    {
        $user = User::factory()->create();
        $team = Team::factory()->create([
            'owner_id' => $user->id,
            'public_id' => 'team-a',
        ]);
        $user->teams()->attach($team, ['role' => 'admin']);

        $response = $this->actingAs($user)
            ->getJson('/api/dashboard');

        $response->assertStatus(200);
    }
}
