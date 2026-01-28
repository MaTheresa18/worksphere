<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Notifications\TaskNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PersistentNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_is_sent_when_task_is_assigned_on_create()
    {
        Notification::fake();
        Event::fake([
             'App\Events\TaskCreated', // Fake this so we focus on Notifications
        ]);

        $assignee = User::factory()->create();
        $creator = User::factory()->create();
        $team = Team::factory()->create();
        $project = Project::factory()->create(['team_id' => $team->id]);

        $this->actingAs($creator);

        Task::create([
            'project_id' => $project->id,
            'title' => 'New Task',
            'created_by' => $creator->id,
            'assigned_to' => $assignee->id,
        ]);

        Notification::assertSentTo(
            [$assignee],
            TaskNotification::class,
            function ($notification, $channels) {
                return in_array('database', $channels) && in_array('broadcast', $channels) &&
                       $notification->type === TaskNotification::TYPE_ASSIGNED;
            }
        );
    }

    public function test_notification_is_sent_when_task_assignment_changes()
    {
        Notification::fake();
        Event::fake([
            'App\Events\TaskUpdated',
        ]);

        $assignee = User::factory()->create();
        $creator = User::factory()->create();
        $team = Team::factory()->create();
        $project = Project::factory()->create(['team_id' => $team->id]);

        $task = Task::factory()->create([
            'project_id' => $project->id,
            'created_by' => $creator->id,
            'assigned_to' => $creator->id, // Assigned to self initially
        ]);

        $this->actingAs($creator);
        
        // Update assignment
        $task->update(['assigned_to' => $assignee->id]);

        Notification::assertSentTo(
            [$assignee],
            TaskNotification::class,
            function ($notification, $channels) {
                return $notification->type === TaskNotification::TYPE_ASSIGNED;
            }
        );
    }
    
    public function test_notification_is_NOT_sent_when_assigned_to_self()
    {
        Notification::fake();
        
        $user = User::factory()->create();
        $team = Team::factory()->create();
        $project = Project::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user);

        // Assign to self
        Task::create([
            'project_id' => $project->id,
            'title' => 'Self Task',
            'created_by' => $user->id,
            'assigned_to' => $user->id,
        ]);

        Notification::assertNotSentTo(
            [$user],
            TaskNotification::class
        );
    }
}
