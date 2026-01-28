<?php

namespace Tests\Feature;

use App\Events\ProjectCreated;
use App\Events\ProjectUpdated;
use App\Events\TaskCreated;
use App\Events\TaskUpdated;
use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class RealtimeNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_created_event_is_dispatched()
    {
        Event::fake([
            ProjectCreated::class,
            ProjectUpdated::class,
            TaskCreated::class,
            TaskUpdated::class,
        ]);

        $user = User::factory()->create();
        $team = Team::factory()->ownedBy($user)->create([
            'name' => 'Team ' . uniqid(),
            'slug' => 'team-' . uniqid(),
        ]);
        
        if (! $team->hasMember($user)) {
            $team->members()->attach($user, ['role' => 'team_lead']);
        } else {
             $team->members()->updateExistingPivot($user->id, ['role' => 'team_lead']);
        }

        $this->actingAs($user)
            ->postJson("/api/teams/{$team->public_id}/projects", [
                'name' => 'New Project',
                'status' => 'active',
            ])
            ->assertStatus(201);

        Event::assertDispatched(ProjectCreated::class);
    }

    public function test_project_updated_event_is_dispatched()
    {
        Event::fake([
            ProjectCreated::class,
            ProjectUpdated::class,
            TaskCreated::class,
            TaskUpdated::class,
        ]);

        $user = User::factory()->create();
        $team = Team::factory()->ownedBy($user)->create([
            'name' => 'Team ' . uniqid(),
            'slug' => 'team-' . uniqid(),
        ]);
        
        if (! $team->hasMember($user)) {
            $team->members()->attach($user, ['role' => 'team_lead']);
        } else {
             $team->members()->updateExistingPivot($user->id, ['role' => 'team_lead']);
        }
        
        $project = Project::factory()->create([
            'team_id' => $team->id,
            'created_by' => $user->id,
        ]);
        if (! $project->hasMember($user)) {
             $project->members()->attach($user, ['role' => 'manager']);
        } else {
             $project->members()->updateExistingPivot($user->id, ['role' => 'manager']);
        }

        $this->actingAs($user)
            ->putJson("/api/teams/{$team->public_id}/projects/{$project->public_id}", [
                'name' => 'Updated Name',
            ])
            ->assertStatus(200);

        Event::assertDispatched(ProjectUpdated::class);
    }

    public function test_task_created_event_is_dispatched()
    {
        Event::fake([
            ProjectCreated::class,
            ProjectUpdated::class,
            TaskCreated::class,
            TaskUpdated::class,
        ]);

        $user = User::factory()->create();
        $team = Team::factory()->ownedBy($user)->create([
            'name' => 'Team ' . uniqid(),
            'slug' => 'team-' . uniqid(),
        ]);
        
        if (! $team->hasMember($user)) {
            $team->members()->attach($user, ['role' => 'team_lead']);
        } else {
             $team->members()->updateExistingPivot($user->id, ['role' => 'team_lead']);
        }

        $project = Project::factory()->create([
            'team_id' => $team->id,
            'created_by' => $user->id,
        ]);
        if (! $project->hasMember($user)) {
             $project->members()->attach($user, ['role' => 'manager']);
        } else {
             $project->members()->updateExistingPivot($user->id, ['role' => 'manager']);
        }

        $this->actingAs($user)
            ->postJson("/api/teams/{$team->public_id}/projects/{$project->public_id}/tasks", [
                'title' => 'New Task',
                'status' => 'open',
            ])
            ->assertStatus(201);

        Event::assertDispatched(TaskCreated::class);
    }

    public function test_task_updated_event_is_dispatched()
    {
        Event::fake([
            ProjectCreated::class,
            ProjectUpdated::class,
            TaskCreated::class,
            TaskUpdated::class,
        ]);

        $user = User::factory()->create([
            'email' => 'task_tester_' . uniqid() . '@example.com',
            'username' => 'task_tester_' . uniqid()
        ]);
        $team = Team::factory()->ownedBy($user)->create([
            'name' => 'Team ' . uniqid(),
            'slug' => 'team-' . uniqid(),
        ]);
        
        if (! $team->hasMember($user)) {
            $team->members()->attach($user, ['role' => 'team_lead']);
        } else {
             $team->members()->updateExistingPivot($user->id, ['role' => 'team_lead']);
        }

        $project = Project::factory()->create([
            'team_id' => $team->id,
            'name' => 'Unique Project for Task Update ' . uniqid(),
            'created_by' => $user->id, // Use existing user
        ]);
        if (! $project->hasMember($user)) {
             $project->members()->attach($user, ['role' => 'manager']);
        } else {
             $project->members()->updateExistingPivot($user->id, ['role' => 'manager']);
        }

        $task = Task::factory()->create([
            'project_id' => $project->id,
            'created_by' => $user->id,
            'assigned_to' => $user->id,
            'title' => 'Unique Task Title ' . uniqid(),
        ]);

        $this->actingAs($user)
            ->putJson("/api/teams/{$team->public_id}/projects/{$project->public_id}/tasks/{$task->public_id}", [
                'title' => 'Updated Task Name',
            ])
            ->assertStatus(200);

        Event::assertDispatched(TaskUpdated::class);
    }
}
