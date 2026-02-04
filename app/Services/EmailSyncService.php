<?php

namespace App\Services;

use App\Contracts\EmailSyncServiceContract;
use App\Enums\EmailFolderType;
use App\Enums\EmailSyncStatus;
use App\Events\Email\EmailReceived;
use App\Events\Email\SyncStatusChanged;
use App\Jobs\BackfillEmailsJob;
use App\Jobs\FetchLatestEmailsJob;
use App\Jobs\FetchNewEmailsJob;
use App\Jobs\SeedEmailAccountJob;
use App\Jobs\SyncEmailFolderJob;
use App\Models\Email;
use App\Models\EmailAccount;
use App\Models\EmailSyncLog;
use App\Services\EmailAdapters\AdapterFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Enums\AuditAction;
use App\Enums\AuditCategory;

class EmailSyncService implements EmailSyncServiceContract
{
    /**
     * Start initial seed for an account using dual-crawler architecture.
     *
     * This dispatches both the forward crawler (for new emails) and
     * backfill crawler (for historical emails) to run in parallel.
     */
    public function startSeed(EmailAccount $account): void
    {
        // Update status and initialize cursors
        $account->update([
            'sync_status' => EmailSyncStatus::Syncing,
            'sync_cursor' => $this->initializeSyncCursor(),
            'sync_error' => null,
            'sync_started_at' => now(),
            'forward_uid_cursor' => null,
            'backfill_uid_cursor' => null,
            'backfill_complete' => false,
        ]);

        // Log sync start
        EmailSyncLog::create([
            'email_account_id' => $account->id,
            'action' => 'dual_sync_started',
            'details' => [
                'started_at' => now()->toIso8601String(),
                'folders' => array_map(fn ($f) => $f->value, EmailFolderType::priorityFolders()),
            ],
        ]);

        // Dispatch forward crawler immediately (fetches newest emails first)
        FetchLatestEmailsJob::dispatch($account->id);

        // Dispatch backfill crawler with slight delay (for historical emails)
        BackfillEmailsJob::dispatch($account->id)->delay(now()->addSeconds(10));

        SyncStatusChanged::dispatch(
            $account,
            EmailSyncStatus::Syncing->value
        );

        Log::info('[EmailSync] Started dual-crawler sync', ['account_id' => $account->id]);
    }

    /**
     * Continue full sync for an account (Phase 2).
     */
    public function continueSync(EmailAccount $account): void
    {
        $cursor = $account->sync_cursor ?? [];
        $folders = $cursor['folders'] ?? [];

        // Find next folder that needs syncing
        $nextFolder = null;
        $disabledFolders = $account->disabled_folders ?? [];
        
        foreach (EmailFolderType::syncOrder() as $folderType) {
            $folderKey = $folderType->value;
            
            // Skip if folder is disabled by user
            if (in_array($folderKey, $disabledFolders)) {
                continue;
            }
            
            $folderData = $folders[$folderKey] ?? [];

            // Skip if completed
            if (($folderData['synced'] ?? 0) >= ($folderData['total'] ?? 0) && isset($folderData['total'])) {
                continue;
            }

            $nextFolder = $folderType;
            break;
        }

        if (! $nextFolder) {
            // All folders complete
            $this->markSyncCompleted($account);

            return;
        }

        // Dispatch sync job for this folder
        SyncEmailFolderJob::dispatch($account->id, $nextFolder->value);
    }

    /**
     * Fetch new emails using the forward crawler.
     * Now works during any sync status (seeding, syncing, completed).
     */
    public function fetchNewEmails(EmailAccount $account): int
    {
        // Forward crawler can run during any active sync status
        if (!$account->canRunForwardCrawler()) {
            return 0;
        }

        // Dispatch forward crawler job
        FetchLatestEmailsJob::dispatch($account->id);

        return 0; // Actual count returned via job
    }

    /**
     * Get sync progress for an account (dual-crawler aware).
     */
    public function getSyncProgress(EmailAccount $account): array
    {
        // Calculate progress based on dual cursors
        $forwardCursor = $account->forward_uid_cursor ?? 0;
        $backfillCursor = $account->backfill_uid_cursor ?? 0;
        $backfillComplete = $account->backfill_complete ?? false;

        // Legacy cursor support
        $cursor = $account->sync_cursor ?? [];
        $folders = $cursor['folders'] ?? [];
        $phase = $cursor['phase'] ?? 'pending';

        // Calculate folder progress for legacy display
        $totalEmails = 0;
        $syncedEmails = 0;
        $folderProgress = [];

        foreach ($folders as $folder => $data) {
            $total = $data['total'] ?? 0;
            $synced = $data['synced'] ?? 0;
            $totalEmails += $total;
            $syncedEmails += $synced;

            $folderProgress[$folder] = [
                'total' => $total,
                'synced' => $synced,
                'percent' => $total > 0 ? round(($synced / $total) * 100) : 0,
            ];
        }

        $overallPercent = $account->getSyncProgressPercent();

        return [
            'status' => $account->sync_status->value,
            'phase' => $backfillComplete ? 'completed' : 'syncing',
            'folders' => $folderProgress,
            'overall_percent' => $overallPercent,
            'total_emails' => $totalEmails,
            'synced_emails' => $syncedEmails,
            // Dual-crawler specific fields
            'forward_cursor' => $forwardCursor,
            'backfill_cursor' => $backfillCursor,
            'backfill_complete' => $backfillComplete,
            'can_use_email' => $account->hasEmailsReady(),
            'last_forward_sync' => $account->last_forward_sync_at?->toIso8601String(),
            'last_backfill' => $account->last_backfill_at?->toIso8601String(),
        ];
    }

    /**
     * Mark sync as completed for an account.
     */
    public function markSyncCompleted(EmailAccount $account): void
    {
        $account->update([
            'sync_status' => EmailSyncStatus::Completed,
            'initial_sync_completed_at' => now(),
            'last_sync_at' => now(),
            'sync_error' => null,
        ]);

        EmailSyncLog::create([
            'email_account_id' => $account->id,
            'action' => EmailSyncLog::ACTION_SYNC_COMPLETED,
            'details' => ['completed_at' => now()->toIso8601String()],
        ]);

        Log::info('[EmailSync] Full sync completed', ['account_id' => $account->id]);

        SyncStatusChanged::dispatch(
            $account,
            EmailSyncStatus::Completed->value
        );
    }

    /**
     * Mark sync as failed for an account.
     */
    public function markSyncFailed(EmailAccount $account, string $error): void
    {
        $account->update([
            'sync_status' => EmailSyncStatus::Failed,
            'sync_error' => $error,
        ]);

        EmailSyncLog::logError($account->id, $error);

        Log::error('[EmailSync] Sync failed', [
            'account_id' => $account->id,
            'error' => $error,
        ]);

        SyncStatusChanged::dispatch(
            $account,
            EmailSyncStatus::Failed->value,
            $error
        );
    }

    /**
     * Get accounts that need sync.
     */
    public function getAccountsNeedingSync(): Collection
    {
        return EmailAccount::query()
            ->active()
            ->verified()
            ->needsSync()
            ->get();
    }

    /**
     * Get accounts ready for forward sync (incremental).
     * Now works during seeding, syncing, or completed status.
     */
    public function getAccountsForIncrementalSync(): Collection
    {
        $intervalMinutes = config('email.scheduler.forward_interval', 2);

        return EmailAccount::query()
            ->active()
            ->verified()
            ->whereIn('sync_status', [
                EmailSyncStatus::Seeding,
                EmailSyncStatus::Syncing,
                EmailSyncStatus::Completed,
            ])
            ->where(function ($query) use ($intervalMinutes) {
                $query->whereNull('last_forward_sync_at')
                    ->orWhere('last_forward_sync_at', '<=', now()->subMinutes($intervalMinutes));
            })
            ->get();
    }

    /**
     * Get IMAP folder name for provider and folder type.
     */
    public function getImapFolderName(string $provider, string $folderType): string
    {
        $mappings = config("email.imap_folders.{$provider}", config('email.imap_folders.custom'));

        return $mappings[$folderType] ?? strtoupper($folderType);
    }

    /**
     * Get max parallel folder limit for provider.
     */
    public function getMaxParallelFolders(string $provider): int
    {
        return config("email.max_parallel_folders.{$provider}", 2);
    }

    /**
     * Update sync cursor after a chunk is processed.
     */
    public function updateSyncCursor(
        EmailAccount $account,
        string $folder,
        int $synced,
        ?int $total = null
    ): void {
        $cursor = $account->sync_cursor ?? ['phase' => 'seed', 'folders' => []];

        $folderData = $cursor['folders'][$folder] ?? ['synced' => 0, 'total' => 0];
        $folderData['synced'] = $synced;

        if ($total !== null) {
            $folderData['total'] = $total;
        }

        $cursor['folders'][$folder] = $folderData;

        $account->update(['sync_cursor' => $cursor]);
    }

    /**
     * Transition from seed phase to full sync phase.
     */
    public function transitionToFullSync(EmailAccount $account): void
    {
        $cursor = $account->sync_cursor ?? [];
        $cursor['phase'] = 'full';

        $account->update([
            'sync_status' => EmailSyncStatus::Syncing,
            'sync_cursor' => $cursor,
        ]);

        EmailSyncLog::create([
            'email_account_id' => $account->id,
            'action' => EmailSyncLog::ACTION_SEED_COMPLETED,
            'details' => ['transitioned_at' => now()->toIso8601String()],
        ]);

        SyncStatusChanged::dispatch(
            $account,
            EmailSyncStatus::Syncing->value
        );
    }

    /**
     * Initialize a new sync cursor.
     */
    protected function initializeSyncCursor(): array
    {
        $folders = [];

        foreach (EmailFolderType::cases() as $folder) {
            $folders[$folder->value] = [
                'total' => 0,
                'synced' => 0,
                'priority' => $folder->syncPriority(),
            ];
        }

        return [
            'phase' => 'seed',
            'folders' => $folders,
        ];
    }

    /**
     * Store fetched email from IMAP with transaction and deduplication.
     *
     * Uses updateOrCreate to prevent duplicates and DB::transaction for atomicity.
     * Retries up to 3 times on deadlock.
     *
     * @param bool $broadcast Whether to broadcast realtime event (false for backfill)
     */
    public function storeEmailFromImap(
        EmailAccount $account,
        array $emailData,
        string $folder,
        bool $broadcast = true
    ): Email {
        $maxRetries = 3;
        $attempt = 0;

        while ($attempt < $maxRetries) {
            try {
                return DB::transaction(function () use ($account, $emailData, $folder, $broadcast) {
                    // Get the sanitization service
                    $sanitizer = app(EmailSanitizationService::class);

                    // Store original HTML in body_raw, sanitize for body_html
                    $bodyRaw = $emailData['body_html'] ?? null;
                    $bodyHtml = $bodyRaw ? $sanitizer->sanitize($bodyRaw, $account->provider ?? 'imap') : null;

                    // Use updateOrCreate to handle duplicates gracefully
                    // [Refactor] Match by message_id to allow migration from Inbox UIDs to All Mail UIDs without duplicates
                    $matchAttributes = [
                        'email_account_id' => $account->id,
                    ];
                    
                    if (!empty($emailData['message_id'])) {
                        $matchAttributes['message_id'] = $emailData['message_id'];
                    } else {
                        // Fallback compatible with old logic
                        $matchAttributes['imap_uid'] = $emailData['imap_uid'] ?? null;
                        $matchAttributes['folder'] = $folder;
                    }

                    // [Sticky Folder Logic]
                    // If the email already exists and is in a "special" folder (inbox, sent, etc),
                    // don't let it be overwritten by 'archive'.
                    $existingEmail = null;
                    if (!empty($emailData['message_id'])) {
                        $existingEmail = Email::where('email_account_id', $account->id)
                            ->where('message_id', $emailData['message_id'])
                            ->first();
                    }

                    if (!$existingEmail && !empty($emailData['imap_uid'])) {
                        $existingEmail = Email::where('email_account_id', $account->id)
                            ->where('imap_uid', $emailData['imap_uid'])
                            ->where('folder', $folder)
                            ->first();
                    }

                    $targetFolder = $folder;
                    if ($existingEmail && $folder === EmailFolderType::Archive->value) {
                         $currentFolder = $existingEmail->folder;
                         if (in_array($currentFolder, [
                             EmailFolderType::Inbox->value,
                             EmailFolderType::Sent->value,
                             EmailFolderType::Drafts->value,
                             EmailFolderType::Trash->value,
                             EmailFolderType::Spam->value
                         ])) {
                             $targetFolder = $currentFolder;
                         }
                    }

                    $email = Email::updateOrCreate(
                        $matchAttributes,
                        [
                            'user_id' => $account->user_id,
                            'imap_uid' => $emailData['imap_uid'] ?? null,
                            'folder' => $targetFolder,
                            'thread_id' => $emailData['thread_id'] ?? null,
                            'from_email' => $emailData['from_email'],
                            'from_name' => $emailData['from_name'] ?? null,
                            'to' => $emailData['to'] ?? [],
                            'cc' => $emailData['cc'] ?? [],
                            'bcc' => $emailData['bcc'] ?? [],
                            'subject' => $emailData['subject'] ?? '(No Subject)',
                            'preview' => $emailData['preview'] ?? '',
                            'body_html' => $bodyHtml,
                            'body_plain' => $emailData['body_plain'] ?? null,
                            'body_raw' => $bodyRaw,
                            'headers' => $emailData['headers'] ?? [],
                            'is_read' => $emailData['is_read'] ?? false,
                            'is_starred' => $emailData['is_starred'] ?? false,
                            'has_attachments' => $emailData['has_attachments'] ?? false,
                            'received_at' => $emailData['date'] ?? now(),
                            'sanitized_at' => $bodyHtml ? now() : null,
                        ]
                    );

                    $isNew = $email->wasRecentlyCreated;

                    // Store attachments only for new emails
                    if ($isNew && !empty($emailData['attachments'])) {
                        $placeholders = [];

                        foreach ($emailData['attachments'] as $attachment) {
                            // If lazy, we gathered metadata but skipped the content
                            if (!empty($attachment['is_lazy'])) {
                                $placeholders[] = [
                                    'name' => $attachment['name'] ?? 'attachment',
                                    'mime' => $attachment['mime'] ?? 'application/octet-stream',
                                    'size' => $attachment['size'] ?? 0,
                                    'content_id' => $attachment['content_id'] ?? null,
                                ];
                                continue;
                            }

                            try {
                                $media = $email->addMediaFromString($attachment['content'])
                                    ->usingFileName($attachment['name'] ?? 'attachment')
                                    ->usingName($attachment['name'] ?? 'Attachment')
                                    ->toMediaCollection('attachments');

                                // Store content_id in custom properties for inline image matching
                                if (!empty($attachment['content_id'])) {
                                    $media->setCustomProperty('content_id', $attachment['content_id']);
                                    $media->save();
                                }
                            } catch (\Throwable $e) {
                                Log::warning('[EmailSync] Failed to store attachment', [
                                    'email_id' => $email->id,
                                    'attachment' => $attachment['name'] ?? 'unknown',
                                    'error' => $e->getMessage(),
                                ]);
                            }
                        }

                        // Store placeholders for on-demand downloading
                        if (!empty($placeholders)) {
                            $email->update(['attachment_placeholders' => $placeholders]);
                        }

                        // [Graphics Fix] Resolve inline images after all potential CID attachments are stored
                        // This replaces cid: links with actual Media URLs on the server-side.
                        if ($email->has_attachments) {
                            $email->update([
                                'body_html' => $sanitizer->resolveInlineImages($email)
                            ]);
                        }
                    }

                    // Broadcast only for new emails AND if broadcast is enabled
                    // (backfill passes false to avoid spamming realtime updates)
                    if ($isNew && $broadcast) {
                        broadcast(new EmailReceived($email));
                    }

                    return $email;
                });
            } catch (\Illuminate\Database\DeadlockException $e) {
                $attempt++;
                if ($attempt >= $maxRetries) {
                    Log::error('[EmailSync] Deadlock after max retries', [
                        'email_account_id' => $account->id,
                        'imap_uid' => $emailData['imap_uid'] ?? null,
                        'attempts' => $attempt,
                    ]);
                    throw $e;
                }
                Log::warning('[EmailSync] Deadlock, retrying', [
                    'attempt' => $attempt,
                    'imap_uid' => $emailData['imap_uid'] ?? null,
                ]);
                usleep(100000 * $attempt); // Exponential backoff: 100ms, 200ms, 300ms
            }
        }

        // Should never reach here, but satisfy return type
        throw new \RuntimeException('Failed to store email after retries');
    }

    /**
     * Download a specific attachment from IMAP.
     *
     * @param  \App\Models\Email  $email
     * @param  int  $placeholderIndex
     * @return \Spatie\MediaLibrary\MediaCollections\Models\Media
     */
    public function downloadAttachment(Email $email, int $placeholderIndex)
    {
        $placeholders = $email->attachment_placeholders ?? [];
        if (!isset($placeholders[$placeholderIndex])) {
            throw new \InvalidArgumentException('Attachment placeholder not found.');
        }

        $placeholder = $placeholders[$placeholderIndex];
        $account = $email->emailAccount;
        $adapter = $this->getAdapterForAccount($account);

        try {
            $client = $adapter->createClient($account);
            $client->connect();
            
            // For Gmail, always use [Gmail]/All Mail since UIDs are folder-specific
            // and All Mail contains all messages. For other providers, use the stored folder.
            $imapFolderName = $adapter->getProvider() === 'gmail' 
                ? '[Gmail]/All Mail'
                : $adapter->getFolderName($email->folder);
            
            $folder = $client->getFolder($imapFolderName);
            
            if (!$folder) {
                throw new \RuntimeException("Folder '{$imapFolderName}' not found on IMAP server.");
            }
            
            $message = $adapter->getMessageByUid($folder, $email->imap_uid);

            if (!$message) {
                throw new \RuntimeException("Message not found on IMAP server.");
            }

            // Find the attachment in the message by name and size (heuristic)
            $targetAttachment = null;
            if ($message->hasAttachments()) {
                foreach ($message->getAttachments() as $attachment) {
                    $name = $attachment->getName();
                    $size = $attachment->getSize();
                    $contentId = $attachment->id ? trim($attachment->id, '<>') : null;

                    // Match by name and size or Content-ID
                    if (($contentId && $contentId === $placeholder['content_id']) || 
                        ($name === $placeholder['name'] && abs($size - $placeholder['size']) < 1024)) {
                        $targetAttachment = $attachment;
                        break;
                    }
                }
            }

            if (!$targetAttachment) {
                throw new \RuntimeException("Attachment not found in message.");
            }

            // Store the attachment as Media
            $media = $email->addMediaFromString($targetAttachment->getContent())
                ->usingFileName($targetAttachment->getName() ?? 'attachment')
                ->usingName($targetAttachment->getName() ?? 'Attachment')
                ->toMediaCollection('attachments');

            if ($contentId = $targetAttachment->id) {
                $media->setCustomProperty('content_id', trim($contentId, '<>'));
                $media->save();
            }

            // Remove from placeholders
            unset($placeholders[$placeholderIndex]);
            $email->update(['attachment_placeholders' => array_values($placeholders)]);

            // If it was an inline image, resolve it now
            if (!empty($placeholder['content_id'])) {
                $sanitizer = app(EmailSanitizationService::class);
                $email->update([
                    'body_html' => $sanitizer->resolveInlineImages($email)
                ]);
            }

            return $media;
        } catch (\Throwable $e) {
            Log::error('[EmailSync] Failed to download attachment on-demand', [
                'email_id' => $email->id,
                'placeholder_index' => $placeholderIndex,
                'error' => $e->getMessage(),
            ]);

            // Log to audit trail for visibility
            app(AuditService::class)->log(
                action: AuditAction::SystemError,
                category: AuditCategory::System,
                context: [
                    'error_type' => 'email_sync_failure',
                    'email_id' => $email->id,
                    'account_id' => $email->email_account_id,
                    'error' => $e->getMessage(),
                ]
            );

            throw $e;
        }
    }

    /**
     * Get adapter for an account.
     */
    protected function getAdapterForAccount(EmailAccount $account)
    {
        return AdapterFactory::make($account);
    }
}
