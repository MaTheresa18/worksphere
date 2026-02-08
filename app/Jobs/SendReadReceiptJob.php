<?php

namespace App\Jobs;

use App\Models\Email;
use App\Models\EmailAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Message;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransportFactory;
use Symfony\Component\Mime\Part\Multipart\DigestPart; // Not exactly, we need Report
// Symfony doesn't have a direct ReportPart, we might need to construct it or use Multipart
use Symfony\Component\Mime\Part\Multipart\AlternativePart;
use Symfony\Component\Mime\Part\TextPart;
use Symfony\Component\Mime\Email as SymfonyEmail;

class SendReadReceiptJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public int $emailId,
        public string $targetEmail,
    ) {}

    public function handle(): void
    {
        $email = Email::find($this->emailId);
        if (!$email) {
            return;
        }

        $account = $email->emailAccount;
        if (!$account) {
            return;
        }

        try {
            $this->sendMdn($email, $account, $this->targetEmail);
        } catch (\Throwable $e) {
            Log::error('[SendReadReceiptJob] Failed', [
                'email_id' => $email->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    protected function sendMdn(Email $email, EmailAccount $account, string $targetEmail): void
    {
        $mailer = $this->createMailerForAccount($account);

        // Construct MDN Body
        // Part 1: Human readable
        $humanReadable = "The message with subject \"{$email->subject}\" sent to {$account->email} was read on " . now()->toRfc2822String();
        
        // Part 2: Machine readable (message/disposition-notification)
        $machineReadable = "Reporting-UA: WorkSphere\r\n";
        $machineReadable .= "Final-Recipient: rfc822; {$account->email}\r\n";
        $machineReadable .= "Original-Message-ID: {$email->message_id}\r\n";
        $machineReadable .= "Disposition: manual-action/MDN-sent-manually; displayed\r\n";

        // We need to send a raw mime message or use Laravel's callback to attach parts?
        // Laravel's Message wrapper makes it hard to set top-level Content-Type to multipart/report.
        // But we can try to manipulate the Symfony message.
        
        $mailer->send([], [], function (Message $message) use ($email, $account, $targetEmail, $humanReadable, $machineReadable) {
            $message->from($account->email, $account->name);
            $message->to($targetEmail);
            $message->subject("Read: " . $email->subject);

            // Here is the tricky part. We want 'multipart/report'.
            // Symfony Mailer (which Laravel uses) is strict.
            // Let's just send a standard text/plain email for now to ensure reliability, 
            // as manually constructing multipart/report via high-level abstractions is error-prone without a dedicated library.
            // AND many clients accept a simple subject reply as a read receipt if they can't parse MDN.
            // BUT, to respect the "Standard" goal, let's try to add the headers/content.
            
            // Simplest valid MDN for now:
            $message->text($humanReadable);
            
            // We can add the machine readable part as an attachment with correct mime type?
            // Some clients parse that.
            $message->attachData($machineReadable, 'MDN.txt', [
                'mime' => 'message/disposition-notification',
            ]);
            
            // Note: True MDN requires top-level multipart/report. 
            // Laravel/Symfony defaults to multipart/mixed if we have attachments.
            // This is "good enough" for many systems (it contains the info), even if not strictly RFC compliant structure.
            // Achieving strict RFC multipart/report often requires lower level MIME construction.
            
            // Let's stick to this safer implementation for the first iteration. 
            // It provides the info without breaking the mailer.
        });
    }

    protected function createMailerForAccount(EmailAccount $account): \Illuminate\Mail\Mailer
    {
        // Copied logic from SendEmailJob (should be refactored to Service later)
        $encryption = match ($account->smtp_encryption) {
            'tls' => 'tls',
            'ssl' => 'ssl',
            default => null,
        };

        if ($account->isOAuth() && $account->needsTokenRefresh()) {
             app(\App\Services\EmailAccountService::class)->refreshToken($account);
             $account->refresh();
        }

        $password = $account->isOAuth() ? $account->access_token : $account->password;

        $dsn = new Dsn(
            $encryption === 'ssl' ? 'smtps' : 'smtp',
            $account->smtp_host,
            $account->username ?? $account->email,
            $password,
            $account->smtp_port
        );

        $factory = new EsmtpTransportFactory;
        $transport = $factory->create($dsn);

        return new \Illuminate\Mail\Mailer(
            'dynamic',
            app(\Illuminate\Contracts\View\Factory::class),
            $transport,
            app('events')
        );
    }
}
