<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

class SystemResetPassword extends ResetPassword implements ShouldQueue
{
    use Queueable;

    /**
     * Get the mail representation of the notification.
     */
    public function toMail($notifiable): MailMessage
    {
        // 1. Resolve 'noreply' system account
        $noreplyAccount = app(\App\Services\SystemEmailService::class)->getAccountForUsage('noreply');

        // 2. Register dynamic mailer if account exists
        $mailer = null;
        if ($noreplyAccount) {
            app(\App\Services\DynamicMailerService::class)->registerSystemMailer($noreplyAccount);
            $mailer = \App\Services\DynamicMailerService::SYSTEM_MAILER_NAME;
        }

        $link = $this->resetUrl($notifiable);

        // 3. Build the MailMessage
        $message = (new MailMessage)
            ->subject('Reset Password Notification')
            ->line('You are receiving this email because we received a password reset request for your account.')
            ->action('Reset Password', $link)
            ->line('This password reset link will expire in '.config('auth.passwords.'.config('auth.defaults.passwords').'.expire').' minutes.')
            ->line('If you did not request a password reset, no further action is required.');

        // 4. Attach the mailer if defined
        if ($mailer) {
            $message->mailer($mailer);
        }

        return $message;
    }
}
