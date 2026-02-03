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
use Illuminate\Support\Facades\Log;

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

    public int $timeout = 180;

    public int $backoff = 30;

    /**
     * The number of seconds after which the job's unique lock will be released.
     */
    public int $uniqueFor = 300;

    /**
     * Number of emails to fetch per batch.
     */
    protected int $batchSize = 50;

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

        // Check if backfill can run
        if (!$account->canRunBackfillCrawler()) {
            Log::debug('[BackfillEmailsJob] Backfill complete or account not ready', [
                'account_id' => $this->accountId,
                'backfill_complete' => $account->backfill_complete,
            ]);
            return;
        }

        $startTime = microtime(true);
        $totalFetched = 0;

        try {
            $adapter = AdapterFactory::make($account);
            $client = $adapter->createClient($account);
            $client->connect();

            // Determine which folders to backfill
            $folders = $this->folderType
                ? [EmailFolderType::from($this->folderType)]
                : EmailFolderType::syncOrder();

            $hasMoreToFetch = false;

            foreach ($folders as $folderType) {
                $result = $this->backfillFolder(
                    $client,
                    $adapter,
                    $account,
                    $folderType,
                    $syncService
                );

                $totalFetched += $result['fetched'];

                if ($result['has_more']) {
                    $hasMoreToFetch = true;
                }
            }

            $client->disconnect();

            // Update backfill timestamp
            $account->update(['last_backfill_at' => now()]);

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            EmailSyncLog::create([
                'email_account_id' => $account->id,
                'action' => 'backfill_batch',
                'details' => [
                    'fetched_count' => $totalFetched,
                    'duration_ms' => $durationMs,
                    'has_more' => $hasMoreToFetch,
                ],
            ]);

            if ($totalFetched > 0) {
                Log::info('[BackfillEmailsJob] Backfilled emails', [
                    'account_id' => $this->accountId,
                    'count' => $totalFetched,
                    'duration_ms' => $durationMs,
                ]);
            }

            // Check if backfill is complete
            $account->refresh();
            if ($hasMoreToFetch && !$account->backfill_complete) {
                // Self-dispatch for next batch with delay
                self::dispatch($this->accountId, $this->folderType)
                    ->delay(now()->addSeconds(5));
            } elseif (!$hasMoreToFetch) {
                // Mark backfill as complete
                $this->markBackfillComplete($account);
            }
        } catch (\Throwable $e) {
            Log::error('[BackfillEmailsJob] Failed', [
                'account_id' => $this->accountId,
                'error' => $e->getMessage(),
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
        $folder = $client->getFolder($folderName);

        if (!$folder) {
            return ['fetched' => 0, 'has_more' => false];
        }

        try {
            // Get folder info
            $examine = $folder->examine();
            $totalMessages = $examine['exists'] ?? 0;

            if ($totalMessages === 0) {
                return ['fetched' => 0, 'has_more' => false];
            }

            // Get cursor positions
            $backfillCursor = $account->backfill_uid_cursor;
            $forwardCursor = $account->forward_uid_cursor ?? PHP_INT_MAX;

            // Initialize backfill cursor if not set
            if ($backfillCursor === null) {
                // Start from the forward cursor (or latest UID) and work backwards
                $backfillCursor = $forwardCursor;
                
                // If forward cursor isn't set yet, get the latest UID
                if ($backfillCursor === PHP_INT_MAX) {
                    $overview = $folder->overview('1:1');
                    $firstItem = collect($overview)->first();
                    if ($firstItem) {
                        $minUid = $adapter->extractUidFromOverview($firstItem);
                        // Get latest UID
                        $latestOverview = $folder->overview("$totalMessages:$totalMessages");
                        $latestItem = collect($latestOverview)->first();
                        $backfillCursor = $latestItem ? $adapter->extractUidFromOverview($latestItem) : $totalMessages;
                    } else {
                        $backfillCursor = $totalMessages;
                    }
                }
            }

            // If cursor is at or below 1, nothing more to backfill
            if ($backfillCursor <= 1) {
                return ['fetched' => 0, 'has_more' => false];
            }

            // Calculate range: work backwards from cursor
            $endUid = max(1, $backfillCursor - 1);
            $startUid = max(1, $endUid - $this->batchSize + 1);

            // Fetch UIDs in range
            $range = "$startUid:$endUid";
            $overview = $folder->overview($range);

            $uidsToFetch = [];
            foreach ($overview as $item) {
                $uid = $adapter->extractUidFromOverview($item);
                if ($uid && $uid < $backfillCursor) {
                    $uidsToFetch[] = $uid;
                }
            }

            if (empty($uidsToFetch)) {
                // No more to fetch
                $account->update(['backfill_uid_cursor' => 1]);
                return ['fetched' => 0, 'has_more' => false];
            }

            // Sort descending (newest first within batch)
            rsort($uidsToFetch);

            $fetched = 0;
            $minUid = $backfillCursor;

            foreach ($uidsToFetch as $uid) {
                try {
                    // Check if already exists
                    $exists = Email::where('email_account_id', $account->id)
                        ->where('imap_uid', $uid)
                        ->where('folder', $folderType->value)
                        ->exists();

                    if ($exists) {
                        $minUid = min($minUid, $uid);
                        continue;
                    }

                    $message = $folder->query()->getMessageByUid($uid);
                    if ($message) {
                        $emailData = $this->parseMessage($message);
                        $syncService->storeEmailFromImap($account, $emailData, $folderType->value);
                        $fetched++;
                        $minUid = min($minUid, $uid);
                    }
                } catch (\Throwable $e) {
                    Log::warning('[BackfillEmailsJob] Failed to fetch UID', [
                        'uid' => $uid,
                        'error' => $e->getMessage(),
                    ]);
                    $minUid = min($minUid, $uid);
                }
            }

            // Update backfill cursor
            if ($minUid < $backfillCursor) {
                $account->update(['backfill_uid_cursor' => $minUid]);
            }

            // Has more if cursor is still above 1
            $hasMore = $minUid > 1;

            return ['fetched' => $fetched, 'has_more' => $hasMore];
        } catch (\Throwable $e) {
            Log::warning('[BackfillEmailsJob] Folder backfill failed', [
                'folder' => $folderType->value,
                'error' => $e->getMessage(),
            ]);
            return ['fetched' => 0, 'has_more' => false];
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
            'details' => ['completed_at' => now()->toIso8601String()],
        ]);

        Log::info('[BackfillEmailsJob] Backfill complete', [
            'account_id' => $account->id,
        ]);

        // Dispatch sync status changed event
        \App\Events\Email\SyncStatusChanged::dispatch(
            $account,
            EmailSyncStatus::Completed->value
        );
    }

    /**
     * Parse IMAP message to email data array.
     */
    protected function parseMessage($message): array
    {
        $from = $message->getFrom()[0] ?? null;

        $fromEmail = 'unknown@unknown.com';
        $fromName = null;

        if ($from && is_object($from)) {
            $fromEmail = $from->mail ?? 'unknown@unknown.com';
            $fromName = $from->personal ?? null;
        } elseif (is_string($from)) {
            $fromEmail = $from;
        }

        $textBody = $message->getTextBody() ?? '';
        $preview = \Illuminate\Support\Str::limit(trim(preg_replace('/\s+/', ' ', $textBody)), 200);

        // Process attachments
        $attachments = [];
        if ($message->hasAttachments()) {
            foreach ($message->getAttachments() as $attachment) {
                $contentId = $attachment->id ?? null;
                if ($contentId) {
                    $contentId = trim($contentId, '<>');
                }

                $attachments[] = [
                    'name' => $attachment->getName(),
                    'content' => $attachment->getContent(),
                    'mime' => $attachment->getMimeType(),
                    'content_id' => $contentId,
                ];
            }
        }

        // Process headers
        $headers = [];
        try {
            $rawHeaders = $message->getHeader()->getAttributes();
            foreach ($rawHeaders as $key => $value) {
                $headers[$key] = (string) $value;
            }
        } catch (\Throwable $e) {
        }

        return [
            'message_id' => (string) ($message->getMessageId()?->first() ?? ''),
            'from_email' => $fromEmail,
            'from_name' => (string) ($fromName ?? $fromEmail),
            'to' => $this->formatRecipients($message->getTo()),
            'cc' => $this->formatRecipients($message->getCc()),
            'bcc' => $this->formatRecipients($message->getBcc()),
            'subject' => (string) ($message->getSubject()?->first() ?? '(No Subject)'),
            'preview' => (string) $preview,
            'body_html' => (string) ($message->getHTMLBody() ?? ''),
            'body_plain' => (string) $textBody,
            'headers' => $headers,
            'is_read' => $message->getFlags()->contains('Seen'),
            'is_starred' => $message->getFlags()->contains('Flagged'),
            'has_attachments' => $message->hasAttachments(),
            'attachments' => $attachments,
            'imap_uid' => (int) $message->getUid(),
            'date' => $message->getDate()?->first()?->toDate(),
        ];
    }

    /**
     * Format recipients to array of [name, email].
     */
    protected function formatRecipients($attribute): array
    {
        if (!$attribute || !method_exists($attribute, 'toArray')) {
            return [];
        }

        $flattened = [];
        foreach ($attribute->toArray() as $recipient) {
            $flattened[] = [
                'name' => $recipient->personal,
                'email' => $recipient->mail,
            ];
        }

        return $flattened;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('[BackfillEmailsJob] Job failed', [
            'account_id' => $this->accountId,
            'error' => $exception->getMessage(),
        ]);
    }

    public function tags(): array
    {
        return ['email', 'backfill-crawler', 'account:' . $this->accountId];
    }
}
