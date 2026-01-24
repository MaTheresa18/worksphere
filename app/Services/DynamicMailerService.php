<?php

namespace App\Services;

use App\Models\EmailAccount;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransportFactory;

class DynamicMailerService
{
    /**
     * The name of the dynamic system connection.
     */
    const SYSTEM_MAILER_NAME = 'system_dynamic';

    /**
     * Register a dynamic mailer configuration for the given account.
     * This allows subsequent mail calls to use ->mailer(DynamicMailerService::SYSTEM_MAILER_NAME).
     */
    public function registerSystemMailer(EmailAccount $account): void
    {
        // 1. Decrypt password/token
        $password = $account->password;
        if ($account->isOAuth()) {
            // Check for token refresh
            if ($account->needsTokenRefresh()) {
                app(EmailAccountService::class)->refreshToken($account);
                $account->refresh();
            }
            $password = $account->access_token;
        }

        // 2. Build configuration array
        $config = [
            'transport' => 'smtp',
            'host' => $account->smtp_host,
            'port' => $account->smtp_port,
            'encryption' => $account->smtp_encryption === 'ssl' ? 'tls' : $account->smtp_encryption, // Laravel config expects 'tls' for STARTTLS
            'username' => $account->username ?? $account->email,
            'password' => $password,
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN'),
        ];
        
        // For 'ssl' (SMTPS, port 465), Laravel 10/Symfony Mailer handles DSN scheme automatically 
        // if we use the DSN constructor, but Config-based setup relies on 'scheme' or 'encryption'.
        // Let's set the config explicitly for the 'mail.mailers' array.

        // 3. Set the config
        Config::set('mail.mailers.' . self::SYSTEM_MAILER_NAME, $config);
        
        // 4. Purge any existing instance to force re-resolution
        Mail::purge(self::SYSTEM_MAILER_NAME);
    }

    /**
     * Create a standalone Mailer instance (for when we can't use the facade/config approach).
     * This logic is similar to SendEmailJob.
     */
    public function createMailerInstance(EmailAccount $account): Mailer
    {
        // (Reusing logic from SendEmailJob for consistency if needed specifically,
        // but the registerSystemMailer approach is better for Mailables)
        // ... omitted for now to keep it DRY, assuming registerSystemMailer works for Mailable::send()
        
        return app('mailer')->driver(self::SYSTEM_MAILER_NAME);
    }
}
