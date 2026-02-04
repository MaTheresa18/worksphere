<?php

namespace App\Jobs;

use App\Enums\EmailFolderType;
use App\Enums\EmailSyncStatus;
use App\Models\Email;
use App\Models\EmailAccount;
use App\Models\EmailSyncLog;
use App\Services\EmailAdapters\AdapterFactory;
use App\Services\EmailSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Support\EmailSyncLogger as Log;

/**
 * Backfill Crawler - Fetches historical emails (UID < backfill_uid_cursor).
 *
 * This job runs in the background with low priority, syncing older emails
 * in chunks. It self-dispatches for the next batch until backfill is complete.
 * The forward crawler runs independently, so users can see new emails immediately.
 */
class BackfillEmailsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $timeout = 300;

    /**
     * Exponential backoff for retries.
     * Starts at 60s, then 5m, 10m, 20m.
     */
    public function backoff(): array
    {
        return [60, 300, 600, 1200];
    }

    /**
     * The number of seconds after which the job's unique lock will be released.
     */
    public int $uniqueFor = 300;

    /**
     * Number of emails to fetch per batch.
     */
    protected int $batchSize = 25; 

    public function __construct(
        public int $accountId,
        public ?string $folderType = null
    ) {
        $this->onQueue('emails-backfill');
    }

    /**
     * The unique ID of the job.
     */
    public function uniqueId(): string
    {
        return 'backfill-' . $this->accountId . '-' . ($this->folderType ?? 'all');
    }

    public function handle(EmailSyncService $syncService): void
    {
        $account = EmailAccount::find($this->accountId);

        if (!$account) {
            Log::warning('[BackfillEmailsJob] Account not found', ['account_id' => $this->accountId]);
            return;
        }

        Log::info('[BackfillEmailsJob] Starting backfill job', [
            'account_id' => $this->accountId,
            'email' => $account->email,
            'backfill_cursor' => $account->backfill_uid_cursor,
            'forward_cursor' => $account->forward_uid_cursor,
            'backfill_complete' => $account->backfill_complete,
        ]);

        // Check if backfill can run
        if (!$account->canRunBackfillCrawler()) {
            Log::info('[BackfillEmailsJob] Backfill complete or account not ready', [
                'account_id' => $this->accountId,
                'backfill_complete' => $account->backfill_complete,
                'sync_status' => $account->sync_status->value,
            ]);
            return;
        }

        $startTime = microtime(true);
        $totalFetched = 0;
        $folderResults = [];

        try {
            $adapter = AdapterFactory::make($account);
            $client = $adapter->createClient($account);
            $client->connect();

            Log::debug('[BackfillEmailsJob] Connected to IMAP', [
                'account_id' => $this->accountId,
                'provider' => $adapter->getProvider(),
            ]);


            // [CRITICAL FIX] Only backfill INBOX (or All Mail for Gmail).
            // Shared 'backfill_uid_cursor' causes data loss when syncing multiple folders with different UID ranges.
            $folders = $this->folderType
                ? [EmailFolderType::from($this->folderType)]
                : ($adapter->getProvider() === 'gmail' ? [EmailFolderType::Archive] : [EmailFolderType::Inbox]);

            $hasMoreToFetch = false;

            foreach ($folders as $folderType) {
                Log::debug('[BackfillEmailsJob] Processing folder', [
                    'folder' => $folderType->value,
                    'account_id' => $this->accountId,
                ]);

                $result = $this->backfillFolder(
                    $client,
                    $adapter,
                    $account,
                    $folderType,
                    $syncService
                );

                $folderResults[$folderType->value] = $result;
                $totalFetched += $result['fetched'];

                if ($result['has_more']) {
                    $hasMoreToFetch = true;
                }

                Log::debug('[BackfillEmailsJob] Folder result', [
                    'folder' => $folderType->value,
                    'fetched' => $result['fetched'],
                    'has_more' => $result['has_more'],
                    'new_cursor' => $result['new_cursor'] ?? 'N/A',
                ]);
            }

            $client->disconnect();

            // Update backfill timestamp
            $account->update(['last_backfill_at' => now()]);

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            // Refresh account to check latest cursor
            $account->refresh();

            EmailSyncLog::create([
                'email_account_id' => $account->id,
                'action' => 'backfill_batch',
                'details' => [
                    'fetched_count' => $totalFetched,
                    'duration_ms' => $durationMs,
                    'has_more' => $hasMoreToFetch,
                    'folder_results' => $folderResults,
                    'backfill_cursor_after' => $account->backfill_uid_cursor,
                ],
            ]);

            Log::info('[BackfillEmailsJob] Batch completed', [
                'account_id' => $this->accountId,
                'fetched' => $totalFetched,
                'duration_ms' => $durationMs,
                'has_more' => $hasMoreToFetch,
                'backfill_cursor' => $account->backfill_uid_cursor,
            ]);

            // Check if backfill is complete
            if ($hasMoreToFetch && !$account->backfill_complete) {
                Log::info('[BackfillEmailsJob] Dispatching next batch', [
                    'account_id' => $this->accountId,
                    'delay_seconds' => 5,
                ]);

                // Self-dispatch for next batch with delay
                self::dispatch($this->accountId, $this->folderType)
                    ->delay(now()->addSeconds(5));
            } elseif (!$hasMoreToFetch) {
                // Mark backfill as complete
                Log::info('[BackfillEmailsJob] No more to fetch, marking complete', [
                    'account_id' => $this->accountId,
                ]);
                $this->markBackfillComplete($account);
            }
        } catch (\Throwable $e) {
            Log::error('[BackfillEmailsJob] Job failed with exception', [
                'account_id' => $this->accountId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Backfill emails for a specific folder (backward direction).
     */
    protected function backfillFolder(
        \Webklex\PHPIMAP\Client $client,
        $adapter,
        EmailAccount $account,
        EmailFolderType $folderType,
        EmailSyncService $syncService
    ): array {
        $folderName = $adapter->getFolderName($folderType->value);

        Log::debug('[BackfillEmailsJob] Getting folder', [
            'folder_type' => $folderType->value,
            'folder_name' => $folderName,
        ]);

        // Use getFolderWithFallback to try alternative folder names
        $folder = $adapter->getFolderWithFallback($client, $folderType->value);

        if (!$folder) {
            // Folder not found even with fallbacks - skip this folder
            return ['fetched' => 0, 'has_more' => false, 'new_cursor' => null];
        }

        try {
            // Get folder info
            $examine = $folder->examine();
            $totalMessages = $examine['exists'] ?? 0;
            $uidValidity = $examine['uidvalidity'] ?? 0;

            Log::debug('[BackfillEmailsJob] Folder info', [
                'folder' => $folderName,
                'total_messages' => $totalMessages,
                'uid_validity' => $uidValidity,
            ]);

            if ($totalMessages === 0) {
                Log::debug('[BackfillEmailsJob] Folder is empty', ['folder' => $folderName]);
                return ['fetched' => 0, 'has_more' => false, 'new_cursor' => null];
            }

            // Get cursor positions
            $backfillCursor = $account->backfill_uid_cursor;
            $forwardCursor = $account->forward_uid_cursor;

            Log::debug('[BackfillEmailsJob] Cursor state', [
                'backfill_cursor' => $backfillCursor,
                'forward_cursor' => $forwardCursor,
            ]);

            // Initialize backfill cursor if not set
            if ($backfillCursor === null || $backfillCursor === 0) {
                // Start from the forward cursor (if set) minus 1
                if ($forwardCursor && $forwardCursor > 0) {
                    $backfillCursor = $forwardCursor;
                    Log::info('[BackfillEmailsJob] Initialized backfill cursor from forward cursor', [
                        'backfill_cursor' => $backfillCursor,
                    ]);
                } else {
                    // Get the highest UID in the folder
                    $latestUids = $adapter->fetchLatestUids($folder, 1);
                    $backfillCursor = !empty($latestUids) ? max($latestUids) : $totalMessages;
                    Log::info('[BackfillEmailsJob] Initialized backfill cursor from latest UID', [
                        'backfill_cursor' => $backfillCursor,
                    ]);
                }
            }

            // If cursor is at or below 1, nothing more to backfill
            if ($backfillCursor <= 1) {
                Log::info('[BackfillEmailsJob] Cursor at minimum, nothing to backfill', [
                    'backfill_cursor' => $backfillCursor,
                ]);
                return ['fetched' => 0, 'has_more' => false, 'new_cursor' => 1];
            }

            // Sliding Window Strategy:
            // Instead of fetching 1:$backfillCursor (which can cause timeouts/memory issues),
            // we fetch a small window below the cursor.
            $windowSize = 50; 
            $startUid = max(1, $backfillCursor - $windowSize);
            $endUid = $backfillCursor - 1;
            
            $range = "$startUid:$endUid";

            Log::info('[BackfillEmailsJob] Fetching window', [
                'range' => $range,
                'start' => $startUid,
                'end' => $endUid,
                'current_cursor' => $backfillCursor
            ]);

            try {
                // Use fetchUidRange from adapter (optimized for range queries)
                // This returns an ARRAY of UIDs
                $allUids = $adapter->fetchUidRange($folder, $startUid, $endUid);
            } catch (\Throwable $e) {
                Log::error('[BackfillEmailsJob] Window fetch failed', ['error' => $e->getMessage()]);
                $allUids = [];
            }

            if (empty($allUids)) {
                // If no UIDs found in this window, we simply move the cursor down 
                // to the start of the window (skipping the gap)
                Log::info('[BackfillEmailsJob] Empty window - skipping gap', [
                    'old_cursor' => $backfillCursor,
                    'new_cursor' => $startUid
                ]);
                
                return [
                    'fetched' => 0,
                    'has_more' => $startUid > 1, // Only have more if we haven't reached bottom
                    'new_cursor' => $startUid,
                    'skipped' => 0,
                    'errors' => 0
                ];
            }

            // Sort descending (highest first)
            rsort($allUids);
            
            // Process up to batchSize from this window
            // Note: In sliding window, we might process all of them if window <= batchSize
            // But usually window (50) > batch (5).
            $uidsToProcess = array_slice($allUids, 0, $this->batchSize);
            
            Log::info('[BackfillEmailsJob] Processing UIDs', [
                'total_found' => count($allUids),
                'processing' => count($uidsToProcess),
                'window_size' => $windowSize
            ]);

            $fetched = 0;
            $skipped = 0;
            $errors = 0;
            // Initialize minUid to something high; we want the lowest UID from processed batch
            $minUid = $backfillCursor;

            foreach ($uidsToProcess as $uid) {
                try {
                    // Check if already exists
                    $exists = Email::where('email_account_id', $account->id)
                        ->where('imap_uid', $uid)
                        ->where('folder', $folderType->value)
                        ->exists();

                    if ($exists) {
                        $skipped++;
                        $minUid = min($minUid, $uid);
                        continue;
                    }

                    $message = $adapter->getMessageByUid($folder, $uid);
                    if ($message) {
                        // delegate parsing to adapter (handles X-GM-LABELS)
                        // [Lazy Sync] Set skipAttachments to true
                        $emailData = $adapter->parseMessage($message, true);
                        $targetFolder = $emailData['folder'] ?? $folderType->value;
                        
                        $syncService->storeEmailFromImap($account, $emailData, $targetFolder, false);
                        $fetched++;
                        $minUid = min($minUid, $uid);
                    }
                } catch (\Throwable $e) {
                    $errors++;
                    Log::warning('[BackfillEmailsJob] Failed to fetch UID', [
                        'uid' => $uid,
                        'folder' => $folderType->value,
                        'error' => $e->getMessage(),
                    ]);
                    // On error, we still want to move properly. 
                    // If we fail to fetch 11767, we should strictly move past it OR retry.
                    // For now, let's treat it as processed so we don't get stuck forever on one bad email.
                     $minUid = min($minUid, $uid);
                }
            }

            // Update backfill cursor to the minimum UID we processed
            if ($minUid < $backfillCursor) {
                // IMPORTANT: If we processed everything in the batch starting at X, 
                // the new cursor should be X. If the whole window was empty, we handled that above.
                $account->update(['backfill_uid_cursor' => $minUid]);
            }
            
            // Check if there are more
            $remainingInWindow = count($allUids) - count($uidsToProcess);
            // We have more if there are items left in window OR if startUid > 1
            $hasMore = $remainingInWindow > 0 || $startUid > 1;

            Log::info('[BackfillEmailsJob] Batch complete', [
                'fetched' => $fetched,
                'new_cursor' => $minUid,
                'window_remaining' => $remainingInWindow,
                'start_uid' => $startUid,
                'has_more' => $hasMore
            ]);

            return [
                'fetched' => $fetched,
                'has_more' => $hasMore,
                'new_cursor' => $minUid,
                'skipped' => $skipped,
                'errors' => $errors,
            ];
        } catch (\Throwable $e) {
            Log::error('[BackfillEmailsJob] Folder backfill exception', [
                'folder' => $folderType->value,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return ['fetched' => 0, 'has_more' => false, 'new_cursor' => null];
        }
    }

    /**
     * Mark backfill as complete and update account status.
     */
    protected function markBackfillComplete(EmailAccount $account): void
    {
        $account->update([
            'backfill_complete' => true,
            'sync_status' => EmailSyncStatus::Completed,
            'initial_sync_completed_at' => $account->initial_sync_completed_at ?? now(),
        ]);

        EmailSyncLog::create([
            'email_account_id' => $account->id,
            'action' => 'backfill_completed',
            'details' => [
                'completed_at' => now()->toIso8601String(),
                'final_cursor' => $account->backfill_uid_cursor,
                'total_emails' => $account->emails()->count(),
            ],
        ]);

        Log::info('[BackfillEmailsJob] Backfill complete', [
            'account_id' => $account->id,
            'total_emails' => $account->emails()->count(),
        ]);

        // Dispatch sync status changed event
        \App\Events\Email\SyncStatusChanged::dispatch(
            $account,
            EmailSyncStatus::Completed->value
        );
    }


    public function failed(\Throwable $exception): void
    {
        Log::error('[BackfillEmailsJob] Job permanently failed', [
            'account_id' => $this->accountId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        EmailSyncLog::create([
            'email_account_id' => $this->accountId,
            'action' => 'backfill_failed',
            'details' => [
                'error' => $exception->getMessage(),
                'failed_at' => now()->toIso8601String(),
            ],
        ]);
    }

    public function tags(): array
    {
        return ['email', 'backfill-crawler', 'account:' . $this->accountId];
    }
}
