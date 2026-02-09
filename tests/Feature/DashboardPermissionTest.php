<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\User;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class DashboardPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_activity_overview_hides_tickets_for_regular_users(): void
    {
        Permission::firstOrCreate(['name' => 'dashboard.view', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'tickets.view', 'guard_name' => 'web']);

        $user = User::factory()->create();
        $user->givePermissionTo(['dashboard.view']); // No tickets.view

        $team = Team::factory()->create(['owner_id' => $user->id]);

        $service = app(DashboardService::class);
        $chartData = $service->getChartData($user, $team);

        $activityDatasets = $chartData['activity']['datasets'];

        $hasTickets = collect($activityDatasets)->contains('label', 'Tickets');
        $this->assertFalse($hasTickets, 'Activity chart should not contain Tickets dataset for regular users');
    }

    public function test_activity_overview_shows_tickets_for_support_users(): void
    {
        Permission::firstOrCreate(['name' => 'dashboard.view', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'tickets.view', 'guard_name' => 'web']);

        $user = User::factory()->create();
        $user->givePermissionTo(['dashboard.view', 'tickets.view']);

        $team = Team::factory()->create(['owner_id' => $user->id]);

        $service = app(DashboardService::class);
        $chartData = $service->getChartData($user, $team);

        $activityDatasets = $chartData['activity']['datasets'];

        $hasTickets = collect($activityDatasets)->contains('label', 'Tickets');
        $this->assertTrue($hasTickets, 'Activity chart should contain Tickets dataset for support users');
    }
}
