<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class HandleGoogleWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $channelId
    ) {}

    /**
     * Execute the job.
     */
    public function handle(\App\Contracts\GoogleCalendarContract $service): void
    {
        try {
            \Illuminate\Support\Facades\Log::info("DEBUG: HandleGoogleWebhookJob Started for Channel: {$this->channelId}");
            
            $service->syncFromGoogle($this->channelId);
            
            \Illuminate\Support\Facades\Log::info("DEBUG: HandleGoogleWebhookJob Completed for Channel: {$this->channelId}");
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("DEBUG: HandleGoogleWebhookJob Failed for Channel: {$this->channelId}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            // Re-throw to ensure job fails and specific queue handling (retry/failed_jobs) takes over
            throw $e;
        }
    }
}
