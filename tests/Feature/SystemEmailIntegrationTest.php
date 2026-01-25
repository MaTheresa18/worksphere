<?php

namespace Tests\Feature;

use App\Models\EmailAccount;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\SystemResetPassword;
use App\Notifications\TicketNotification;
use App\Services\DynamicMailerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SystemEmailIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_ticket_notification_uses_support_account_if_available()
    {
        // 1. Create Support Account
        $supportAccount = EmailAccount::create([
            'name' => 'Support',
            'email' => 'support@example.com',
            'is_system' => true,
            'system_usage' => 'support',
            'is_active' => true,
            'provider' => 'custom',
            'auth_type' => 'password',
            'smtp_host' => 'smtp.support.com',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'username' => 'support',
            'password' => 'secret',
        ]);

        // 2. Create Ticket
        $ticket = Ticket::factory()->create();

        // 3. Trigger Notification
        $notification = new TicketNotification($ticket, TicketNotification::TYPE_CREATED);

        // Mock the DynamicMailerService to verify it gets called
        $this->mock(DynamicMailerService::class, function ($mock) use ($supportAccount) {
            $mock->shouldReceive('registerSystemMailer')
                ->once()
                ->withArgs(function ($arg) use ($supportAccount) {
                    return $arg->id === $supportAccount->id;
                });
        });

        // Resolve notification toMail
        $mailable = $notification->toMail($this->user);

        // Verify it returns a Mailable with the correct mailer set
        $this->assertInstanceOf(\App\Mail\TicketNotificationMail::class, $mailable);
        // Note: We can't easily check the ->mailer property as it's protected or handled via Mail::send()
        // But the mock verification ensures the service was called.
    }

    public function test_ticket_notification_falls_back_if_no_support_account()
    {
        // No support account created

        $ticket = Ticket::factory()->create();
        $notification = new TicketNotification($ticket, TicketNotification::TYPE_CREATED);

        // Mock DynamicMailerService - should NOT be called
        $this->mock(DynamicMailerService::class, function ($mock) {
            $mock->shouldNotReceive('registerSystemMailer');
        });

        $mailable = $notification->toMail($this->user);

        // Should still return the Mailable, but without dynamic config
        $this->assertInstanceOf(\App\Mail\TicketNotificationMail::class, $mailable);
    }

    public function test_reset_password_uses_noreply_account()
    {
        // 1. Create Noreply Account
        $noreplyAccount = EmailAccount::create([
            'name' => 'Noreply',
            'email' => 'noreply@example.com',
            'is_system' => true,
            'system_usage' => 'noreply',
            'is_active' => true,
            'provider' => 'custom',
            'auth_type' => 'password',
            'smtp_host' => 'smtp.noreply.com',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'username' => 'noreply',
            'password' => 'secret',
        ]);

        // 2. Trigger Password Reset Notification (manual instantiation)
        $notification = new SystemResetPassword('token123');

        // Mock DynamicMailerService
        $this->mock(DynamicMailerService::class, function ($mock) use ($noreplyAccount) {
            $mock->shouldReceive('registerSystemMailer')
                ->once()
                ->withArgs(function ($arg) use ($noreplyAccount) {
                    return $arg->id === $noreplyAccount->id;
                });
        });

        $mailMessage = $notification->toMail($this->user);

        // Verify it returned a MailMessage (or configured object)
        $this->assertNotNull($mailMessage);

        // Check if the mailer property was set on the message instance
        // MailMessage has public mailer property in recent Laravel versions?
        // Actually MailMessage stores it in $mailer property, accessor ->mailer
        $this->assertEquals(DynamicMailerService::SYSTEM_MAILER_NAME, $mailMessage->mailer);
    }
}
