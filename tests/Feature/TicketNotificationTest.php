<?php

namespace Tests\Feature;

use App\Events\TicketCreated;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TicketNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $reporter;

    protected User $assignee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('administrator');

        $this->reporter = User::factory()->create();
        $this->reporter->assignRole('user');

        $this->assignee = User::factory()->create();
        $this->assignee->assignRole('user');
    }

    public function test_ticket_created_event_is_broadcast()
    {
        Event::fake([TicketCreated::class]);

        $this->actingAs($this->reporter);

        $response = $this->postJson('/api/tickets', [
            'title' => 'Test Ticket',
            'description' => 'Test description',
            'priority' => 'medium',
            'type' => 'task',
        ]);

        $response->assertStatus(201);
        Event::assertDispatched(TicketCreated::class);
    }

    public function test_assignee_receives_notification_when_assigned()
    {
        Notification::fake();

        $ticket = Ticket::factory()->create([
            'reporter_id' => $this->reporter->id,
        ]);

        $this->actingAs($this->admin);

        $response = $this->postJson("/api/tickets/{$ticket->public_id}/assign", [
            'assigned_to' => $this->assignee->public_id,
        ]);

        $response->assertStatus(200);

        Notification::assertSentTo(
            $this->assignee,
            TicketNotification::class,
            function ($notification) {
                return $notification->type === TicketNotification::TYPE_ASSIGNED;
            }
        );
    }

    public function test_stakeholders_receive_notification_on_status_change()
    {
        Notification::fake();

        $ticket = Ticket::factory()->create([
            'reporter_id' => $this->reporter->id,
            'assigned_to' => $this->assignee->id,
        ]);

        $this->actingAs($this->admin);

        $response = $this->putJson("/api/tickets/{$ticket->public_id}/status", [
            'status' => 'resolved',
        ]);

        $response->assertStatus(200);

        // Both reporter and assignee should receive notification
        Notification::assertSentTo(
            $this->reporter,
            TicketNotification::class,
            function ($notification) {
                return $notification->type === TicketNotification::TYPE_UPDATED;
            }
        );

        Notification::assertSentTo(
            $this->assignee,
            TicketNotification::class,
            function ($notification) {
                return $notification->type === TicketNotification::TYPE_UPDATED;
            }
        );
    }

    public function test_stakeholders_receive_notification_on_comment()
    {
        Notification::fake();

        $ticket = Ticket::factory()->create([
            'reporter_id' => $this->reporter->id,
            'assigned_to' => $this->assignee->id,
        ]);

        // Comment as admin, reporter and assignee should get notified
        $this->actingAs($this->admin);

        $response = $this->postJson("/api/tickets/{$ticket->public_id}/comments", [
            'content' => 'Test comment from admin',
        ]);

        $response->assertStatus(201);

        Notification::assertSentTo(
            $this->reporter,
            TicketNotification::class,
            function ($notification) {
                return $notification->type === TicketNotification::TYPE_COMMENT;
            }
        );

        Notification::assertSentTo(
            $this->assignee,
            TicketNotification::class,
            function ($notification) {
                return $notification->type === TicketNotification::TYPE_COMMENT;
            }
        );
    }

    public function test_comment_author_does_not_receive_own_notification()
    {
        Notification::fake();

        $ticket = Ticket::factory()->create([
            'reporter_id' => $this->reporter->id,
            'assigned_to' => $this->assignee->id,
        ]);

        // Comment as reporter
        $this->actingAs($this->reporter);

        $response = $this->postJson("/api/tickets/{$ticket->public_id}/comments", [
            'content' => 'Comment from reporter',
        ]);

        $response->assertStatus(201);

        // Reporter should NOT receive notification for their own comment
        Notification::assertNotSentTo($this->reporter, TicketNotification::class);

        // But assignee should
        Notification::assertSentTo($this->assignee, TicketNotification::class);
    }

    public function test_user_can_opt_out_of_email_notifications()
    {
        $this->reporter->setNotificationPreference('ticket_comment', false);

        $this->assertFalse($this->reporter->wantsEmailFor('ticket_comment'));
        $this->assertTrue($this->reporter->wantsEmailFor('ticket_created')); // Default true
    }

    public function test_ticket_notification_has_correct_data()
    {
        $ticket = Ticket::factory()->create([
            'reporter_id' => $this->reporter->id,
        ]);

        $notification = new TicketNotification(
            $ticket,
            TicketNotification::TYPE_CREATED,
            $this->reporter
        );

        $data = $notification->toArray($this->assignee);

        $this->assertEquals('ticket_created', $data['type']);
        $this->assertArrayHasKey('action_url', $data);
        $this->assertArrayHasKey('metadata', $data);
        $this->assertEquals($ticket->public_id, $data['metadata']['ticket_id']);
    }
}
