<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Enums\InvoiceStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class DashboardUpdatesTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        // Create permissions
        Permission::firstOrCreate(['name' => 'dashboard.view', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'projects.view', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'tasks.view', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'invoices.view', 'guard_name' => 'web']);

        $this->user = User::factory()->create();
        $this->user->givePermissionTo([
            'dashboard.view',
            'projects.view',
            'tasks.view',
            'invoices.view',
        ]);

        $this->team = Team::factory()->create(['owner_id' => $this->user->id]);
        $this->team->members()->attach($this->user->id, [
            'role' => 'owner',
            'joined_at' => now(),
        ]);
    }

    public function test_dashboard_returns_financial_stats_when_permitted(): void
    {
        // Create a paid invoice
        Invoice::factory()->create([
            'team_id' => $this->team->id,
            'status' => InvoiceStatus::Paid,
            'total' => 1000.00,
            'created_by' => $this->user->id,
        ]);

        // Create a sent invoice
        Invoice::factory()->create([
            'team_id' => $this->team->id,
            'status' => InvoiceStatus::Sent,
            'total' => 500.00,
            'created_by' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/dashboard?team_id=' . $this->team->public_id);

        $response->assertOk()
            ->assertJsonPath('data.financial.collected.raw', 1000)
            ->assertJsonPath('data.financial.pending.raw', 500);
    }

    public function test_dashboard_returns_detailed_task_stats(): void
    {
        $project = Project::factory()->create(['team_id' => $this->team->id]);

        // 1 Completed
        Task::factory()->create([
            'project_id' => $project->id,
            'status' => 'completed',
        ]);

        // 2 In Progress
        Task::factory()->create([
            'project_id' => $project->id,
            'status' => 'in_progress',
        ]);
        Task::factory()->create([
            'project_id' => $project->id,
            'status' => 'in_progress',
        ]);

        // 1 Past Due
        Task::factory()->create([
            'project_id' => $project->id,
            'status' => 'open',
            'due_date' => now()->subDays(1),
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/dashboard?team_id=' . $this->team->public_id);

        $response->assertOk()
            ->assertJsonPath('data.task_detail.completed.count', 1)
            ->assertJsonPath('data.task_detail.in_progress.count', 2)
            ->assertJsonPath('data.task_detail.past_due.count', 1)
            ->assertJsonPath('data.task_detail.total', 4);
    }

    public function test_financial_stats_not_returned_without_permission(): void
    {
        $limitedUser = User::factory()->create();
        $limitedUser->givePermissionTo(['dashboard.view']);
        $this->team->members()->attach($limitedUser->id, ['role' => 'member', 'joined_at' => now()]);

        $response = $this->actingAs($limitedUser)
            ->getJson('/api/dashboard?team_id=' . $this->team->public_id);

        $response->assertOk()
            ->assertJsonPath('data.financial', null);
    }

    public function test_ticket_stats_and_trends_only_for_support_capable_users(): void
    {
        Permission::firstOrCreate(['name' => 'tickets.view', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'tickets.view_own', 'guard_name' => 'web']);

        // User with ONLY view_own (regular user)
        $regularUser = User::factory()->create();
        $regularUser->givePermissionTo(['dashboard.view', 'tickets.view_own']);
        $this->team->members()->attach($regularUser->id, ['role' => 'member', 'joined_at' => now()]);

        // User with tickets.view (support user)
        $supportUser = User::factory()->create();
        $supportUser->givePermissionTo(['dashboard.view', 'tickets.view']);
        $this->team->members()->attach($supportUser->id, ['role' => 'member', 'joined_at' => now()]);

        // Verify Regular User
        $response = $this->actingAs($regularUser)
            ->getJson('/api/dashboard?team_id=' . $this->team->public_id);
        
        $response->assertOk()
            ->assertJsonPath('data.features.tickets_enabled', false)
            ->assertJsonMissing(['data.stats' => [['id' => 'tickets']]])
            ->assertJsonPath('data.charts.ticket_trends.datasets', [])
            ->assertJsonMissing(['data.charts.activity.datasets' => [['label' => 'Tickets']]]);

        // Verify Support User
        $response = $this->actingAs($supportUser)
            ->getJson('/api/dashboard?team_id=' . $this->team->public_id);
        
        $response->assertOk()
            ->assertJsonPath('data.features.tickets_enabled', true)
            ->assertJsonFragment(['id' => 'tickets'])
            ->assertJsonFragment(['label' => 'Tickets']);
    }
}
