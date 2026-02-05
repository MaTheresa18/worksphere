<?php

namespace App\Jobs;

use App\Contracts\EmailProviderAdapter;
use App\Enums\EmailFolderType;
use App\Enums\EmailSyncStatus;
use App\Models\EmailAccount;
use App\Models\EmailSyncLog;
use App\Services\EmailAdapters\AdapterFactory;
use App\Services\EmailSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job to perform initial seed (Phase 1) - fetch N emails from priority folders.
 *
 * Uses provider-specific adapters to handle differences between Gmail, Outlook,
 * and custom IMAP servers.
 */
class SeedEmailAccountJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 300; // 5 minutes

    protected ?EmailProviderAdapter $adapter = null;

    public function __construct(
        public int $accountId
    ) {
        $this->onQueue(config('email.jobs.sync.queue', 'emails'));
    }

    public function retryUntil(): \DateTime
    {
        return now()->addHours(24);
    }

    public function handle(EmailSyncService $syncService): void
    {
        // Increase memory limit for initial seed of potentially large folders
        ini_set('memory_limit', '1024M');

        $account = EmailAccount::find($this->accountId);

        if (! $account) {
            Log::warning('[SeedEmailAccountJob] Account not found', ['account_id' => $this->accountId]);

            return;
        }

        if ($account->sync_status !== EmailSyncStatus::Seeding) {
            Log::info('[SeedEmailAccountJob] Account not in seeding status, skipping', [
                'account_id' => $this->accountId,
                'status' => $account->sync_status->value,
            ]);

            return;
        }

        // Get provider-specific adapter
        $this->adapter = AdapterFactory::make($account);

        $startTime = microtime(true);
        $seedCount = config('email.seed_count', 50);

        try {
            // Refresh token if needed (for OAuth providers)
            if (! $this->adapter->refreshTokenIfNeeded($account)) {
                throw new \RuntimeException('Failed to refresh OAuth token');
            }

            $priorityFolders = EmailFolderType::priorityFolders();
            $totalSeeded = 0;

            foreach ($priorityFolders as $folderType) {
                $seededInFolder = $this->seedFolder(
                    $account,
                    $folderType,
                    $seedCount,
                    $syncService
                );
                $totalSeeded += $seededInFolder;
            }

            // Log completion
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            EmailSyncLog::create([
                'email_account_id' => $account->id,
                'action' => EmailSyncLog::ACTION_SEED_COMPLETED,
                'details' => [
                    'seeded_count' => $totalSeeded,
                    'duration_ms' => $durationMs,
                ],
            ]);

            // Transition to full sync phase
            $syncService->transitionToFullSync($account);

            // Dispatch next phase
            $syncService->continueSync($account);

            Log::info('[SeedEmailAccountJob] Seed completed', [
                'account_id' => $this->accountId,
                'total_seeded' => $totalSeeded,
                'duration_ms' => $durationMs,
            ]);
        } catch (\Throwable $e) {
            Log::error('[SeedEmailAccountJob] Seed failed', [
                'account_id' => $this->accountId,
                'error' => $e->getMessage(),
            ]);

            $syncService->markSyncFailed($account, $e->getMessage());

            throw $e;
        }
    }

    /**
     * Seed a single folder using the adapter.
     */
    protected function seedFolder(
        EmailAccount $account,
        EmailFolderType $folderType,
        int $seedCount,
        EmailSyncService $syncService
    ): int {
        try {
            // Get folder status using agnostic method
            $status = $this->adapter->getFolderStatus($account, $folderType->value);
            $totalMessages = $status['exists'] ?? 0;

            if ($totalMessages === 0) {
                $syncService->updateSyncCursor($account, $folderType->value, 0, 0);
                Log::info('[SeedEmailAccountJob] Folder is empty', [
                    'account_id' => $this->accountId,
                    'folder' => $folderType->value,
                ]);

                return 0;
            }

            // Use adapter to fetch latest messages directly (parsed to array)
            $messages = $this->adapter->fetchLatestMessagesForAccount($account, $folderType->value, $seedCount);

            if ($messages->isEmpty()) {
                Log::warning('[SeedEmailAccountJob] No messages fetched', [
                    'account_id' => $this->accountId,
                    'folder' => $folderType->value,
                    'total_messages' => $totalMessages,
                ]);

                $syncService->updateSyncCursor($account, $folderType->value, 0, $totalMessages);

                return 0;
            }

            // Store each message
            $seededCount = 0;
            foreach ($messages as $emailData) {
                try {
                    $syncService->storeEmail($account, $emailData, $folderType->value, false);
                    $seededCount++;
                } catch (\Throwable $e) {
                    Log::warning('[SeedEmailAccountJob] Failed to store email', [
                        'account_id' => $this->accountId,
                        'folder' => $folderType->value,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Update cursor
            $syncService->updateSyncCursor($account, $folderType->value, $seededCount, $totalMessages);

            Log::info('[SeedEmailAccountJob] Seeded folder', [
                'account_id' => $this->accountId,
                'folder' => $folderType->value,
                'seeded' => $seededCount,
                'total' => $totalMessages,
            ]);

            return $seededCount;
        } catch (\Throwable $e) {
            Log::warning('[SeedEmailAccountJob] Failed to seed folder', [
                'account_id' => $this->accountId,
                'folder' => $folderType->value,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('[SeedEmailAccountJob] Job failed', [
            'account_id' => $this->accountId,
            'error' => $exception->getMessage(),
        ]);
    }

    public function tags(): array
    {
        return ['email', 'sync', 'seed', 'account:'.$this->accountId];
    }
}
