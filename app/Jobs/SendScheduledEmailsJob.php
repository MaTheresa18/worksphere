<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendScheduledEmailsJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $emails = \App\Models\Email::where('is_draft', true)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->get();

        foreach ($emails as $email) {
            // Mark as no longer a draft (it's now "sending" or "sent")
            $email->update(['is_draft' => false]);

            SendEmailJob::dispatch($email->id, $email->email_account_id);

            \Illuminate\Support\Facades\Log::info('[SendScheduledEmailsJob] Dispatched scheduled email', [
                'email_id' => $email->id,
                'scheduled_at' => $email->scheduled_at,
            ]);
        }
    }
}
