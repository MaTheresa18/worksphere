<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

class TeamMemberProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        cache()->flush();
        config(['audit.async' => false]);

        // Seed necessary permissions
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'user_manage', 'guard_name' => 'web']);
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'team_roles.assign', 'guard_name' => 'web']);
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'teams.update', 'guard_name' => 'web']);
    }

    public function test_user_can_view_teammate_profile()
    {
        $team = Team::factory()->create();
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $team->members()->syncWithoutDetaching([
            $user1->id => ['role' => \App\Enums\TeamRole::Operator->value],
            $user2->id => ['role' => \App\Enums\TeamRole::Operator->value],
        ]);

        $response = $this->actingAs($user1)
            ->getJson("/api/users/{$user2->public_id}/profile");

        $response->assertStatus(200)
            ->assertJsonPath('data.name', $user2->name);
    }

    public function test_user_cannot_view_non_teammate_profile()
    {
        $team1 = Team::factory()->create();
        $team2 = Team::factory()->create();
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $team1->members()->syncWithoutDetaching([$user1->id => ['role' => \App\Enums\TeamRole::Operator->value]]);
        $team2->members()->syncWithoutDetaching([$user2->id => ['role' => \App\Enums\TeamRole::Operator->value]]);

        $response = $this->actingAs($user1)
            ->getJson("/api/users/{$user2->public_id}/profile");

        $response->assertStatus(403);
    }

    public function test_team_admin_can_update_member_role()
    {
        $team = Team::factory()->create();
        $admin = User::factory()->create();
        $member = User::factory()->create();

        $team->members()->syncWithoutDetaching([
            $admin->id => ['role' => \App\Enums\TeamRole::SubjectMatterExpert->value],
            $member->id => ['role' => \App\Enums\TeamRole::Operator->value],
        ]);

        $response = $this->actingAs($admin)
            ->putJson("/api/teams/{$team->public_id}/members/{$member->public_id}/role", [
                'role' => \App\Enums\TeamRole::SubjectMatterExpert->value,
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('team_user', [
            'team_id' => $team->id,
            'user_id' => $member->id,
            'role' => \App\Enums\TeamRole::SubjectMatterExpert->value,
        ]);
    }

    public function test_team_member_cannot_update_role()
    {
        $team = Team::factory()->create();
        $member1 = User::factory()->create();
        $member2 = User::factory()->create();

        $team->members()->syncWithoutDetaching([
            $member1->id => ['role' => \App\Enums\TeamRole::Operator->value],
            $member2->id => ['role' => \App\Enums\TeamRole::Operator->value],
        ]);

        $response = $this->actingAs($member1)
            ->putJson("/api/teams/{$team->public_id}/members/{$member2->public_id}/role", [
                'role' => \App\Enums\TeamRole::SubjectMatterExpert->value,
            ]);

        $response->assertStatus(403);
    }

    public function test_cannot_change_owner_role()
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $admin = User::factory()->create();

        $team->members()->syncWithoutDetaching([
            $owner->id => ['role' => \App\Enums\TeamRole::TeamLead->value],
            $admin->id => ['role' => \App\Enums\TeamRole::SubjectMatterExpert->value],
        ]);

        $response = $this->actingAs($admin)
            ->putJson("/api/teams/{$team->public_id}/members/{$owner->public_id}/role", [
                'role' => \App\Enums\TeamRole::Operator->value,
            ]);

        $response->assertStatus(403);
    }
    public function test_team_owner_can_upload_avatar()
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        
        $team->members()->syncWithoutDetaching([
            $owner->id => ['role' => \App\Enums\TeamRole::TeamLead->value],
        ]);

        $file = \Illuminate\Http\UploadedFile::fake()->image('avatar.jpg');

        $response = $this->actingAs($owner)
            ->postJson("/api/teams/{$team->public_id}/avatar", [
                'avatar' => $file,
            ]);

        $response->assertStatus(200);
        
        // Assert file exists in storage/media and is on public disk
        $media = $team->fresh()->getMedia('avatars')->first();
        $this->assertNotNull($media);
        $this->assertEquals('public', $media->disk);
    }

    public function test_team_owner_can_remove_avatar()
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);

        $team->members()->syncWithoutDetaching([
            $owner->id => ['role' => \App\Enums\TeamRole::TeamLead->value],
        ]);

        // Upload first
        $file = UploadedFile::fake()->image('avatar.jpg');
        $this->actingAs($owner)
            ->postJson("/api/teams/{$team->public_id}/avatar", [
                'avatar' => $file,
            ]);

        $this->assertCount(1, $team->fresh()->getMedia('avatars'));

        // Delete
        $response = $this->actingAs($owner)
            ->deleteJson("/api/teams/{$team->public_id}/avatar");

        $response->assertStatus(200);
        $this->assertCount(0, $team->fresh()->getMedia('avatars'));
    }
}
