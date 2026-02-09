<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScopingPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Seed roles and permissions
        $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);
    }

    public function test_lead_can_create_project_in_their_team()
    {
        $team = Team::factory()->create();
        $user = User::factory()->create();
        $team->addMember($user, 'team_lead');

        $response = $this->actingAs($user)
            ->postJson("/api/teams/{$team->public_id}/projects", [
                'name' => 'Project by Lead',
                'status' => 'active',
                'priority' => 'medium',
            ]);

        $response->assertStatus(201);
    }

    public function test_sme_cannot_create_project_in_their_team()
    {
        $team = Team::factory()->create();
        $user = User::factory()->create();
        $team->addMember($user, 'subject_matter_expert');

        $response = $this->actingAs($user)
            ->postJson("/api/teams/{$team->public_id}/projects", [
                'name' => 'Project by SME',
                'status' => 'active',
                'priority' => 'medium',
            ]);

        $response->assertStatus(403);
    }

    public function test_qa_can_create_task_in_their_team()
    {
        $team = Team::factory()->create();
        $project = Project::factory()->create(['team_id' => $team->id]);
        $user = User::factory()->create();
        $team->addMember($user, 'quality_assessor');

        $response = $this->actingAs($user)
            ->postJson("/api/teams/{$team->public_id}/projects/{$project->public_id}/tasks", [
                'title' => 'Task by QA',
                'status' => 'open',
                'priority' => 3,
            ]);

        $response->assertStatus(201);
    }

    public function test_operator_cannot_create_task()
    {
        $team = Team::factory()->create();
        $project = Project::factory()->create(['team_id' => $team->id]);
        $user = User::factory()->create();
        $team->addMember($user, 'operator');

        $response = $this->actingAs($user)
            ->postJson("/api/teams/{$team->public_id}/projects/{$project->public_id}/tasks", [
                'title' => 'Task by Operator',
                'status' => 'open',
                'priority' => 3,
            ]);

        $response->assertStatus(403);
    }

    public function test_lead_cannot_create_project_in_another_team()
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        $user = User::factory()->create();

        $teamA->addMember($user, 'team_lead');

        $response = $this->actingAs($user)
            ->postJson("/api/teams/{$teamB->public_id}/projects", [
                'name' => 'Cross-team Project',
                'status' => 'active',
                'priority' => 'medium',
            ]);

        $response->assertStatus(403);
    }

    public function test_qa_can_edit_tasks()
    {
        $team = Team::factory()->create();
        $project = Project::factory()->create(['team_id' => $team->id]);
        $user = User::factory()->create();
        $team->addMember($user, 'quality_assessor');

        $task = \App\Models\Task::factory()->create([
            'project_id' => $project->id,
            'status' => \App\Enums\TaskStatus::InProgress,
        ]);

        $response = $this->actingAs($user)
            ->putJson("/api/teams/{$team->public_id}/projects/{$project->public_id}/tasks/{$task->public_id}", [
                'title' => 'Updated by QA',
            ]);

        $response->assertStatus(200);
    }

    public function test_navigation_scopes_projects_by_team_role()
    {
        $teamA = Team::factory()->create(['name' => 'Team Alpha']);
        $teamB = Team::factory()->create(['name' => 'Team Beta']);
        $user = User::factory()->create();

        // Lead in Alpha (sees all)
        $teamA->addMember($user, 'team_lead');
        Project::factory()->active()->count(3)->create(['team_id' => $teamA->id]);

        // Operator in Beta (sees only assigned)
        $teamB->addMember($user, 'operator');
        $projectB1 = Project::factory()->active()->create(['team_id' => $teamB->id, 'name' => 'Assigned Project']);
        $projectB2 = Project::factory()->active()->create(['team_id' => $teamB->id, 'name' => 'Unassigned Project']);
        $projectB1->members()->attach($user->id, ['role' => 'viewer']);

        $response = $this->actingAs($user)->getJson('/api/navigation');

        $response->assertStatus(200);
        $sidebar = collect($response->json('sidebar'));
        $projectsItem = $sidebar->firstWhere('id', 'projects');
        $children = collect($projectsItem['children']);

        // Verify Team Alpha branching
        $this->assertTrue($children->contains('label', 'Team Alpha'));
        // Verify Team Beta branching
        $this->assertTrue($children->contains('label', 'Team Beta'));
        $this->assertTrue($children->contains('label', 'Assigned Project'));
        $this->assertFalse($children->contains('label', 'Unassigned Project'));

        // Count actual projects (excluding View All, New, Headers, Dividers)
        $projects = $children->filter(fn ($c) => ! isset($c['type']) && ! in_array($c['label'], ['View All Projects', 'New Project']));
        $this->assertGreaterThanOrEqual(4, $projects->count()); // 3 from Alpha + 1 from Beta
    }

    public function test_navigation_scopes_clients_by_team_role()
    {
        $teamA = Team::factory()->create(['name' => 'Team Alpha']);
        $teamB = Team::factory()->create(['name' => 'Team Beta']);
        $user = User::factory()->create();

        // Lead in Alpha (sees clients)
        $teamA->addMember($user, 'team_lead');
        \App\Models\Client::factory()->active()->create(['team_id' => $teamA->id, 'name' => 'Alpha Client']);

        // Operator in Beta (no clients.view)
        $teamB->addMember($user, 'operator');
        \App\Models\Client::factory()->active()->create(['team_id' => $teamB->id, 'name' => 'Beta Client']);

        $response = $this->actingAs($user)->getJson('/api/navigation');

        $sidebar = collect($response->json('sidebar'));
        $clientsItem = $sidebar->firstWhere('id', 'clients');
        $children = collect($clientsItem['children']);

        $this->assertTrue($children->contains('label', 'Team Alpha'));
        $this->assertTrue($children->contains('label', 'Alpha Client'));
        $this->assertFalse($children->contains('label', 'Team Beta'));
        $this->assertFalse($children->contains('label', 'Beta Client'));
    }

    public function test_cannot_access_project_detail_unauthorized()
    {
        $team = Team::factory()->create();
        $user = User::factory()->create();
        $project = Project::factory()->create(['team_id' => $team->id]);

        $response = $this->actingAs($user)->getJson("/api/projects/{$project->public_id}");
        $response->assertStatus(403);
    }

    public function test_can_access_project_detail_as_team_lead()
    {
        $team = Team::factory()->create();
        $user = User::factory()->create();
        $project = Project::factory()->create(['team_id' => $team->id]);

        $team->addMember($user, 'team_lead');
        $response = $this->actingAs($user)->getJson("/api/projects/{$project->public_id}");

        $response->assertStatus(200);
        $response->assertJsonPath('public_id', $project->public_id);
    }

    public function test_cannot_access_project_detail_as_operator_if_not_member()
    {
        $team = Team::factory()->create();
        $user = User::factory()->create();
        $project = Project::factory()->create(['team_id' => $team->id]);

        $team->addMember($user, 'operator');

        $response = $this->actingAs($user)->getJson("/api/projects/{$project->public_id}");
        $response->assertStatus(403);
    }

    public function test_can_access_project_detail_as_operator_if_member()
    {
        $team = Team::factory()->create();
        $user = User::factory()->create();
        $project = Project::factory()->create(['team_id' => $team->id]);

        $team->addMember($user, 'operator');
        $project->addMember($user);

        $response = $this->actingAs($user)->getJson("/api/projects/{$project->public_id}");
        $response->assertStatus(200);
        $response->assertJsonPath('public_id', $project->public_id);
    }
}
