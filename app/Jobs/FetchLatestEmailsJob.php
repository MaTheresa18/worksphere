<?php

namespace App\Jobs;

use App\Enums\EmailFolderType;
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
 * Forward Crawler - Fetches newest emails (UID > forward_uid_cursor).
 *
 * This job runs frequently (every 2 minutes) and fetches new emails
 * that arrived since the last check. It can run during seeding, syncing,
 * or completed status - allowing users to see new emails immediately.
 */
class FetchLatestEmailsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public int $maxExceptions = 2;

    /**
     * The number of seconds after which the job's unique lock will be released.
     */
    public int $uniqueFor = 120;

    public function __construct(
        public int $accountId
    ) {
        $this->onQueue('emails-live');
    }

    /**
     * The unique ID of the job.
     */
    public function uniqueId(): string
    {
        return 'fetch-latest-' . $this->accountId;
    }

    public function handle(EmailSyncService $syncService): void
    {
        $account = EmailAccount::find($this->accountId);

        if (!$account) {
            Log::warning('[FetchLatestEmailsJob] Account not found', ['account_id' => $this->accountId]);
            return;
        }

        // Check if forward crawler can run (active, verified, and in valid status)
        if (!$account->canRunForwardCrawler()) {
            Log::debug('[FetchLatestEmailsJob] Account not ready for forward sync', [
                'account_id' => $this->accountId,
                'status' => $account->sync_status->value,
            ]);
            return;
        }

        $startTime = microtime(true);
        $totalFetched = 0;

        try {
            $adapter = AdapterFactory::make($account);
            $client = $adapter->createClient($account);
            $client->connect();

            // Only fetch from priority folders (inbox, sent, drafts, trash)
            foreach (EmailFolderType::priorityFolders() as $folderType) {
                $fetched = $this->fetchForwardForFolder(
                    $client,
                    $adapter,
                    $account,
                    $folderType,
                    $syncService
                );
                $totalFetched += $fetched;
            }

            $client->disconnect();

            // Update forward sync timestamp
            $account->update(['last_forward_sync_at' => now()]);

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            if ($totalFetched > 0) {
                EmailSyncLog::create([
                    'email_account_id' => $account->id,
                    'action' => 'forward_fetch',
                    'details' => [
                        'fetched_count' => $totalFetched,
                        'duration_ms' => $durationMs,
                    ],
                ]);

                Log::info('[FetchLatestEmailsJob] Fetched new emails', [
                    'account_id' => $this->accountId,
                    'count' => $totalFetched,
                    'duration_ms' => $durationMs,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('[FetchLatestEmailsJob] Failed', [
                'account_id' => $this->accountId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Fetch new emails for a specific folder (forward direction).
     */
    protected function fetchForwardForFolder(
        \Webklex\PHPIMAP\Client $client,
        $adapter,
        EmailAccount $account,
        EmailFolderType $folderType,
        EmailSyncService $syncService
    ): int {
        $folderName = $adapter->getFolderName($folderType->value);
        $folder = $client->getFolder($folderName);

        if (!$folder) {
            return 0;
        }

        try {
            // Get the current forward cursor for this account
            $forwardCursor = $account->forward_uid_cursor ?? 0;

            // Get folder info
            $examine = $folder->examine();
            $uidNext = $examine['uidnext'] ?? 0;

            // If cursor is 0, this is first run - set cursor to latest UID
            if ($forwardCursor === 0) {
                // Fetch latest UIDs (max 50) to bootstrap
                // Use adapter method if available, otherwise manual
                $uids = $adapter->fetchLatestUids($folder, 50);
                
                $fetched = 0;
                $maxUid = 0;

                foreach ($uids as $uid) {
                    $maxUid = max($maxUid, $uid);

                    try {
                        // Check if already exists
                        $exists = Email::where('email_account_id', $account->id)
                            ->where('imap_uid', $uid)
                            ->where('folder', $folderType->value)
                            ->exists();

                        if (!$exists) {
                            $message = $folder->query()->getMessageByUid($uid);
                            if ($message) {
                                $emailData = $this->parseMessage($message);
                                $syncService->storeEmailFromImap($account, $emailData, $folderType->value);
                                $fetched++;
                            }
                        }
                    } catch (\Throwable $e) {
                         Log::warning('[FetchLatestEmailsJob] Failed to fetch bootstrap UID', [
                            'uid' => $uid,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // Update forward cursor to the highest UID we've seen
                if ($maxUid > 0) {
                    $account->update(['forward_uid_cursor' => $maxUid]);
                }

                return $fetched;
            }

            // Normal forward fetch: get messages > forwardCursor
            if ($uidNext <= $forwardCursor + 1) {
                // No new messages
                return 0;
            }

            // Fetch UIDs greater than cursor
            $range = ($forwardCursor + 1) . ':*';
            $overview = $folder->overview($range);

            $newUids = [];
            foreach ($overview as $item) {
                $uid = $adapter->extractUidFromOverview($item);
                if ($uid && $uid > $forwardCursor) {
                    $newUids[] = $uid;
                }
            }

            if (empty($newUids)) {
                return 0;
            }

            // Limit to 100 per batch
            $newUids = array_slice($newUids, 0, 100);

            $fetched = 0;
            $maxUid = $forwardCursor;

            foreach ($newUids as $uid) {
                try {
                    // Check if already exists
                    $exists = Email::where('email_account_id', $account->id)
                        ->where('imap_uid', $uid)
                        ->where('folder', $folderType->value)
                        ->exists();

                    if ($exists) {
                        $maxUid = max($maxUid, $uid);
                        continue;
                    }

                    $message = $folder->query()->getMessageByUid($uid);
                    if ($message) {
                        $emailData = $this->parseMessage($message);
                        $syncService->storeEmailFromImap($account, $emailData, $folderType->value);
                        $fetched++;
                        $maxUid = max($maxUid, $uid);
                    }
                } catch (\Throwable $e) {
                    Log::warning('[FetchLatestEmailsJob] Failed to fetch UID', [
                        'uid' => $uid,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Update forward cursor
            if ($maxUid > $forwardCursor) {
                $account->update(['forward_uid_cursor' => $maxUid]);
            }

            return $fetched;
        } catch (\Throwable $e) {
            Log::warning('[FetchLatestEmailsJob] Folder fetch failed', [
                'folder' => $folderType->value,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
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
        Log::error('[FetchLatestEmailsJob] Job failed', [
            'account_id' => $this->accountId,
            'error' => $exception->getMessage(),
        ]);
    }

    public function tags(): array
    {
        return ['email', 'forward-crawler', 'account:' . $this->accountId];
    }
}
