<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\User;
use App\Services\TeamActivityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    /**
     * Test team creation limit is enforced.
     */
    public function test_team_creation_limit_is_enforced(): void
    {
        $user = User::factory()->create();
        $user->assignRole('administrator');

        // Set max teams owned to 2 for testing
        config(['teams.limits.max_teams_owned' => 2]);

        // Create 2 teams (at limit)
        Team::factory()->count(2)->create(['owner_id' => $user->id]);

        // Attempt to create a 3rd team should fail
        $response = $this->actingAs($user)->postJson('/api/teams', [
            'name' => 'Third Team',
            'description' => 'Should fail',
            'owner_id' => $user->public_id,
            'status' => 'active',
        ]);

        $response->assertStatus(403);
        $response->assertJsonFragment(['message' => 'Team creation limit reached. Maximum 2 teams allowed per user.']);
    }

    /**
     * Test team activity service can check creation limit.
     */
    public function test_team_activity_service_checks_creation_limit(): void
    {
        $user = User::factory()->create();
        config(['teams.limits.max_teams_owned' => 3]);

        $service = new TeamActivityService;

        $this->assertTrue($service->canUserCreateTeam($user));
        $this->assertEquals(3, $service->getRemainingTeamSlots($user));

        // Create 3 teams
        Team::factory()->count(3)->create(['owner_id' => $user->id]);

        $this->assertFalse($service->canUserCreateTeam($user));
        $this->assertEquals(0, $service->getRemainingTeamSlots($user));
    }

    /**
     * Test team lifecycle status methods.
     */
    public function test_team_lifecycle_status_methods(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);

        // Initial state should be active
        $this->assertEquals('active', $team->lifecycle_status);

        // Mark as dormant
        $team->markDormant();
        $team->refresh();
        $this->assertEquals('dormant', $team->lifecycle_status);
        $this->assertTrue($team->isDormant());
        $this->assertNotNull($team->dormant_notified_at);

        // Mark as pending deletion
        $team->markPendingDeletion();
        $team->refresh();
        $this->assertEquals('pending_deletion', $team->lifecycle_status);
        $this->assertTrue($team->isPendingDeletion());
        $this->assertNotNull($team->deletion_scheduled_at);

        // Keep active resets everything
        $team->keepActive();
        $team->refresh();
        $this->assertEquals('active', $team->lifecycle_status);
        $this->assertFalse($team->isDormant());
        $this->assertFalse($team->isPendingDeletion());
        $this->assertNull($team->dormant_notified_at);
        $this->assertNull($team->deletion_scheduled_at);
    }

    /**
     * Test keep active endpoint.
     */
    public function test_owner_can_keep_team_active(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole('administrator');

        $team = Team::factory()->create([
            'owner_id' => $owner->id,
            'lifecycle_status' => 'dormant',
        ]);
        $team->members()->attach($owner->id, ['role' => 'team_lead']);

        $response = $this->actingAs($owner)->postJson("/api/teams/{$team->public_id}/keep-active");

        $response->assertOk();
        $team->refresh();
        $this->assertEquals('active', $team->lifecycle_status);
    }

    /**
     * Test non-owner cannot keep team active.
     */
    public function test_non_owner_cannot_keep_team_active(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $member->assignRole('administrator');

        $team = Team::factory()->create([
            'owner_id' => $owner->id,
            'lifecycle_status' => 'dormant',
        ]);
        $team->members()->attach($member->id, ['role' => 'operator']);

        $response = $this->actingAs($member)->postJson("/api/teams/{$team->public_id}/keep-active");

        $response->assertForbidden();
    }

    /**
     * Test owner can self-delete team.
     */
    public function test_owner_can_self_delete_team(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole('administrator');

        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->members()->attach($owner->id, ['role' => 'team_lead']);
        $teamId = $team->id;

        $response = $this->actingAs($owner)->deleteJson("/api/teams/{$team->public_id}/self-delete");

        $response->assertOk();
        $this->assertDatabaseMissing('teams', ['id' => $teamId]);
    }

    /**
     * Test ownership summary endpoint.
     */
    public function test_ownership_summary_returns_correct_data(): void
    {
        $user = User::factory()->create();
        $user->assignRole('administrator');

        // Create 2 owned teams
        $ownedTeam1 = Team::factory()->create(['owner_id' => $user->id]);
        $ownedTeam2 = Team::factory()->create(['owner_id' => $user->id]);

        // Create a team where user is just a member
        $otherOwner = User::factory()->create();
        $memberTeam = Team::factory()->create(['owner_id' => $otherOwner->id]);
        $memberTeam->members()->attach($user->id, ['role' => 'operator']);

        $response = $this->actingAs($user)->getJson('/api/teams-ownership-summary');

        $response->assertOk();
        $response->assertJsonStructure([
            'owned_count',
            'member_count',
            'max_owned',
            'max_joined',
            'remaining_slots',
            'owned_teams',
            'member_teams',
        ]);
        $response->assertJsonFragment(['owned_count' => 2]);
    }
}
