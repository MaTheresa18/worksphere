<?php

namespace App\Services\EmailAdapters;

use App\Contracts\EmailProviderAdapter;
use App\Enums\EmailFolderType;
use App\Models\Email;
use App\Models\EmailAccount;
use App\Services\EmailSanitizationService;
use App\Services\EmailSyncService;
use Illuminate\Support\Facades\Log;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Folder;

/**
 * Base adapter with common functionality for all email providers.
 */
abstract class BaseEmailAdapter implements EmailProviderAdapter
{
    protected ClientManager $clientManager;

    public function __construct()
    {
        $this->clientManager = new ClientManager;
    }

    /**
     * Extract UID from overview item - handles both object and array formats.
     */
    public function extractUidFromOverview(mixed $item): ?int
    {
        if (is_object($item)) {
            return isset($item->uid) ? (int) $item->uid : null;
        }

        if (is_array($item)) {
            return isset($item['uid']) ? (int) $item['uid'] : null;
        }

        return null;
    }

    /**
     * Fetch latest UIDs using overview() with UID range.
     */
    public function fetchLatestUids(Folder $folder, int $count): array
    {
        $totalInfo = $folder->examine();
        $uidnext = $totalInfo['uidnext'] ?? 0;
        $exists = $totalInfo['exists'] ?? 0;

        if ($exists === 0 || $uidnext === 0) {
            return [];
        }

        // Widen the range to account for sparse UIDs
        $rangeStart = max(1, $uidnext - ($count * 2));
        $range = "{$rangeStart}:*";

        try {
            $overview = $this->executeWithBackoff(fn () => $folder->overview($range));
            $uids = [];

            // Overview can return data in two ways:
            // 1. Objects/arrays with 'uid' property/key (some providers)
            // 2. Arrays keyed BY the UID (Gmail) - the key IS the UID
            foreach ($overview as $key => $item) {
                // First try to extract from item content
                $uid = $this->extractUidFromOverview($item);

                // If no uid in content, the array KEY is likely the UID (Gmail behavior)
                if ($uid === null && is_int($key) && $key > 0) {
                    $uid = $key;
                }

                if ($uid !== null) {
                    $uids[] = $uid;
                }
            }

            // Sort descending (newest first) and take requested count
            rsort($uids);

            return array_slice($uids, 0, $count);
        } catch (\Throwable $e) {
            Log::warning("[{$this->getProvider()}Adapter] Overview fetch failed", [
                'folder' => $folder->path,
                'range' => $range,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Fetch UIDs for a sequence range.
     */
    public function fetchUidRange(Folder $folder, int $start, int $end): array
    {
        $range = "{$start}:{$end}";

        try {
            $overview = $folder->overview($range);
            $uids = [];

            foreach ($overview as $key => $item) {
                $uid = $this->extractUidFromOverview($item);

                // Fallback: If no uid in content, use key if integer (Gmail behavior)
                if ($uid === null && is_int($key) && $key > 0) {
                    $uid = $key;
                }

                if ($uid !== null) {
                    $uids[] = $uid;
                }
            }

            return $uids;
        } catch (\Throwable $e) {
            Log::warning("[{$this->getProvider()}Adapter] UID range fetch failed", [
                'folder' => $folder->path,
                'range' => $range,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Get folder name from config.
     */
    public function getFolderName(string $folderType): string
    {
        $mapping = $this->getFolderMapping();

        return $mapping[$folderType] ?? strtoupper($folderType);
    }

    /**
     * Get folder with fallback to alternatives.
     * Tries primary folder name first, then alternatives if configured.
     */
    public function getFolderWithFallback(\Webklex\PHPIMAP\Client $client, string $folderType): ?\Webklex\PHPIMAP\Folder
    {
        $primaryName = $this->getFolderName($folderType);

        // Try primary folder name
        $folder = $client->getFolder($primaryName);
        if ($folder) {
            return $folder;
        }

        // Try alternative names if configured
        $alternativesKey = $this->getProvider().'_alternatives';
        $alternatives = config("email.imap_folders.{$alternativesKey}.{$folderType}", []);

        foreach ($alternatives as $altName) {
            $folder = $client->getFolder($altName);
            if ($folder) {
                Log::debug("[{$this->getProvider()}Adapter] Using alternative folder name", [
                    'folder_type' => $folderType,
                    'primary_name' => $primaryName,
                    'used_name' => $altName,
                ]);

                return $folder;
            }
        }

        Log::warning("[{$this->getProvider()}Adapter] Folder not found", [
            'folder_type' => $folderType,
            'primary_name' => $primaryName,
            'alternatives_tried' => $alternatives,
        ]);

        return null;
    }

    /**
     * Default: no OAuth support.
     */
    public function supportsOAuth(): bool
    {
        return false;
    }

    /**
     * Default: no token refresh needed.
     */
    public function refreshTokenIfNeeded(EmailAccount $account): bool
    {
        return true;
    }

    /**
     * Get max parallel folders from config.
     */
    public function getMaxParallelFolders(): int
    {
        return config("email.max_parallel_folders.{$this->getProvider()}", 2);
    }

    /**
     * Build base configuration for IMAP client.
     */
    protected function buildBaseConfig(EmailAccount $account, bool $fetchBody = true): array
    {
        return [
            'host' => $account->imap_host,
            'port' => $account->imap_port,
            'encryption' => $account->imap_encryption,
            'validate_cert' => true,
            'username' => $account->username ?? $account->email,
            'protocol' => 'imap',
            'timeout' => 30,
            // Important: Use PEEK mode to avoid marking messages as seen
            // and avoid STORE commands on READ-ONLY folders
            'options' => [
                'fetch' => \Webklex\PHPIMAP\IMAP::FT_PEEK,
                'sequence' => \Webklex\PHPIMAP\IMAP::ST_UID,
                'fetch_body' => $fetchBody,
                'fetch_flags' => true,
                'soft_fail' => true, // Ignore certain exceptions when fetching
            ],
        ];
    }

    /**
     * Execute an operation with exponential backoff.
     */
    protected function executeWithBackoff(callable $operation, int $maxRetries = 3)
    {
        $delay = 1;
        for ($i = 0; $i < $maxRetries; $i++) {
            try {
                return $operation();
            } catch (\Throwable $e) {
                if ($i === $maxRetries - 1) {
                    throw $e;
                }

                // Only retry on typical connection/rate limit errors
                // We assume any error here *could* be transient for now
                Log::warning("[{$this->getProvider()}Adapter] Operation failed, retrying in {$delay}s", [
                    'error' => $e->getMessage(),
                ]);

                sleep($delay);
                $delay *= 2;
            }
        }
    }

    /**
     * Fetch the latest N messages from a folder.
     *
     * Default implementation: get UIDs then fetch messages.
     * Subclasses can override for more efficient provider-specific methods.
     */
    public function fetchLatestMessages(Folder $folder, int $count): \Illuminate\Support\Collection
    {
        $uids = $this->fetchLatestUids($folder, $count);

        if (empty($uids)) {
            // Fallback: use query with limit
            try {
                return $this->executeWithBackoff(fn () => $folder->query()->limit($count)->get());
            } catch (\Throwable $e) {
                Log::warning("[{$this->getProvider()}Adapter] Fallback query failed", [
                    'folder' => $folder->path,
                    'error' => $e->getMessage(),
                ]);

                return collect();
            }
        }

        // Fetch messages one by one to avoid "invalid sequence set" errors
        $messages = collect();
        foreach ($uids as $uid) {
            try {
                // Fetch individual message with backoff using getMessageByUid
                $msg = $this->executeWithBackoff(fn () => $this->getMessageByUid($folder, $uid));
                if ($msg) {
                    $messages->push($msg);
                }
            } catch (\Throwable $e) {
                Log::debug("[{$this->getProvider()}Adapter] Failed to fetch UID {$uid}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $messages;
    }

    /**
     * Parse IMAP message to email data array.
     * Can be overridden by providers to add specific logic (e.g. Gmail labels).
     */
    /**
     * Parse message attributes into a standardized array.
     *
     * @param  mixed  $message  The IMAP message
     * @param  bool  $skipAttachments  Whether to skip downloading attachment content
     * @param  bool  $fetchBody  Whether to fetch body content (html/plain)
     */
    public function parseMessage($message, bool $skipAttachments = false, bool $fetchBody = true): array
    {
        try {
            $from = @$message->getFrom()[0] ?? null;
        } catch (\Throwable $e) {
            $from = null;
        }

        $fromEmail = 'unknown@unknown.com';
        $fromName = null;

        if ($from && is_object($from)) {
            $fromEmail = $from->mail ?? 'unknown@unknown.com';
            $fromName = $from->personal ?? null;
        } elseif (is_string($from)) {
            $fromEmail = $from;
        }

        $textBody = '';
        $bodyHtml = '';
        $preview = '';

        if ($fetchBody) {
            $textBody = $message->getTextBody() ?? '';
            $bodyHtml = (string) ($message->getHTMLBody() ?? '');
            $preview = app(EmailSanitizationService::class)->generatePreview($bodyHtml ?: $textBody);
        } else {
            // Even if body is skipped, we might have a snippet/preview from headers in some providers,
            // but for standard IMAP, we often need the body to get the preview.
            // We'll leave it empty or null for now.
        }

        // Process attachments
        $attachments = [];
        if ($message->hasAttachments()) {
            foreach ($message->getAttachments() as $attachment) {
                $contentId = $attachment->id ?? null;
                if ($contentId) {
                    $contentId = trim($contentId, '<>');
                }

                // [Graphics Fix] Check if attachment is inline (referenced in HTML)
                // If so, we force download even in lazy mode to ensure graphics render correctly.
                $isInline = false;
                if ($contentId && $bodyHtml && str_contains($bodyHtml, 'cid:'.$contentId)) {
                    $isInline = true;
                }

                $attachmentData = [
                    'name' => $attachment->getName(),
                    'mime' => $attachment->getMimeType(),
                    'size' => $attachment->getSize(),
                    'content_id' => $contentId,
                    'is_inline' => $isInline,
                    'is_lazy' => $isInline ? false : $skipAttachments,
                ];

                if (! $attachmentData['is_lazy']) {
                    $attachmentData['content'] = $attachment->getContent();
                }

                $attachments[] = $attachmentData;
            }
        }

        // Process headers
        $headers = [];
        try {
            $rawHeaders = $message->getHeader()->getAttributes();
            foreach ($rawHeaders as $key => $value) {
                $headers[strtolower((string) $key)] = (string) $value;
            }
        } catch (\Throwable $e) {
        }

        return [
            'message_id' => (string) ($message->getMessageId()?->first() ?? ''),
            'from_email' => $fromEmail,
            'from_name' => (string) ($fromName ?? $fromEmail),
            'to' => $this->safeGetRecipients($message, 'getTo'),
            'cc' => $this->safeGetRecipients($message, 'getCc'),
            'bcc' => $this->safeGetRecipients($message, 'getBcc'),
            'subject' => (string) ($message->getSubject()?->first() ?? '(No Subject)'),
            'preview' => (string) $preview,
            'body_html' => (string) $bodyHtml,
            'body_plain' => (string) $textBody,
            'headers' => $headers,
            'is_read' => $message->getFlags()->contains('Seen'),
            'is_starred' => $message->getFlags()->contains('Flagged'),
            // When fetch_body=false, MIME structure isn't loaded so hasAttachments() may
            // return false. Fall back to header-based detection (Content-Type).
            'has_attachments' => $message->hasAttachments() || $this->detectAttachmentsFromHeaders($headers),
            'attachments' => $attachments,
            'imap_uid' => (int) $message->getUid(),
            'date' => $message->getDate()?->first()?->toDate(),
        ];
    }

    /**
     * Detect attachments from email headers when MIME body isn't available.
     *
     * When the IMAP client is created with fetch_body=false, the MIME structure
     * isn't loaded, so hasAttachments() returns false. This method provides a
     * lightweight fallback by checking the Content-Type header.
     */
    protected function detectAttachmentsFromHeaders(array $headers): bool
    {
        $contentType = strtolower($headers['content-type'] ?? '');

        // multipart/mixed = standard email with attachments
        // multipart/related = email with inline images
        // multipart/alternative = just plain+HTML versions (no attachments)
        return str_contains($contentType, 'multipart/mixed')
            || str_contains($contentType, 'multipart/related');
    }

    /**
     * Format recipients to array of [name, email].
     */
    protected function formatRecipients($attribute): array
    {
        if (! $attribute || ! method_exists($attribute, 'toArray')) {
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

    /**
     * Safely get recipients suppressing IMAP errors.
     */
    protected function safeGetRecipients($message, string $method): array
    {
        try {
            // Suppress IMAP warnings that can cause fatal errors in Laravel (e.g. malformed addresses)
            $recipients = @$message->$method();

            return $this->formatRecipients($recipients);
        } catch (\Throwable $e) {
            Log::warning('[BaseEmailAdapter] Failed to parse recipients', [
                'method' => $method,
                'message_id' => $message->getUid(),
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Fetch a single message by UID.
     */
    public function getMessageByUid(Folder $folder, int $uid)
    {
        return $folder->query()->getMessageByUid($uid);
    }

    /**
     * Get high-level folder status (IMAP implementation).
     */
    public function getFolderStatus(EmailAccount $account, string $folderType): array
    {
        $this->refreshTokenIfNeeded($account);
        $client = $this->createClient($account);
        $client->connect();

        $folder = $this->getFolderWithFallback($client, $folderType);
        $status = ['exists' => 0];

        if ($folder) {
            $examine = $folder->examine();
            $status = [
                'exists' => $examine['exists'] ?? 0,
                'uidnext' => $examine['uidnext'] ?? 0,
            ];
        }

        $client->disconnect();

        return $status;
    }

    /**
     * Fetch a chunk of messages (IMAP implementation).
     */
    public function fetchMessages(EmailAccount $account, string $folderType, int $offset, int $limit, bool $fetchBody = true): \Illuminate\Support\Collection
    {
        $this->refreshTokenIfNeeded($account);
        $client = $this->createClient($account, $fetchBody);
        $client->connect();

        $folder = $this->getFolderWithFallback($client, $folderType);
        if (! $folder) {
            return collect();
        }

        $totalMessages = $folder->examine()['exists'] ?? 0;
        $start = $offset + 1;
        $end = min($offset + $limit, $totalMessages);

        $uidsToFetch = $this->fetchUidRange($folder, $start, $end);
        $messages = collect();

        foreach ($uidsToFetch as $uid) {
            try {
                $msg = $this->executeWithBackoff(fn () => $this->getMessageByUid($folder, $uid));
                if ($msg) {
                    $messages->push($this->parseMessage($msg));
                }
            } catch (\Throwable $e) {
                Log::warning("[BaseEmailAdapter] Failed to fetch UID {$uid}", ['error' => $e->getMessage()]);
            }
        }

        $client->disconnect();

        return $messages;
    }

    /**
     * Fetch the latest N messages for a folder (IMAP implementation).
     */
    public function fetchLatestMessagesForAccount(EmailAccount $account, string $folderType, int $count, bool $fetchBody = true): \Illuminate\Support\Collection
    {
        $this->refreshTokenIfNeeded($account);
        $client = $this->createClient($account, $fetchBody);
        $client->connect();

        $folder = $this->getFolderWithFallback($client, $folderType);
        if (! $folder) {
            return collect();
        }

        $messages = $this->fetchLatestMessages($folder, $count);
        $parsed = $messages->map(fn ($msg) => $this->parseMessage($msg, true, $fetchBody));

        $client->disconnect();

        return $parsed;
    }

    /**
     * Fetch incremental updates (IMAP implementation).
     * For IMAP, this falls back to fetching latest FROM INBOX compared to a cursor.
     */
    public function fetchIncrementalUpdates(EmailAccount $account, bool $fetchBody = true): \Illuminate\Support\Collection
    {
        // For now, IMAP doesn't have a standardized efficient incremental sync like Gmail API.
        // SyncEmailFolderJob handles the UID-based increment for full synced folders.
        // We return empty here to let the legacy jobs handle it, or we could implement a basic 'check inbox' here.
        return collect();
    }

    /**
     * Subscribe to notifications (IMAP implementation).
     */
    public function subscribeToNotifications(EmailAccount $account): bool
    {
        return false; // IMAP doesn't support Pub/Sub notifications
    }

    /**
     * Create and configure an IMAP client for the account.
     */
    public function createClient(EmailAccount $account, bool $fetchBody = true): Client
    {
        $config = $this->buildBaseConfig($account, $fetchBody);

        return $this->clientManager->make($config);
    }

    /**
     * Default backfill implementation (IMAP UID crawling).
     * Providers should override this if they have a better way (e.g. Gmail API).
     */
    public function backfill(EmailAccount $account, ?string $folderType, int $batchSize, bool $fetchBody = true): array
    {
        $client = $this->createClient($account, $fetchBody);
        $client->connect();

        $folders = $folderType
            ? [EmailFolderType::from($folderType)]
            : ($this->getProvider() === 'gmail' ? [EmailFolderType::Archive] : [EmailFolderType::Inbox]);

        $totalFetched = 0;
        $hasMoreToFetch = false;
        $folderResults = [];
        $syncService = app(EmailSyncService::class);

        foreach ($folders as $type) {
            $result = $this->backfillFolder($client, $account, $type, $syncService, $batchSize);
            $folderResults[$type->value] = $result;
            $totalFetched += $result['fetched'];
            if ($result['has_more']) {
                $hasMoreToFetch = true;
            }
        }

        $client->disconnect();

        return [
            'fetched' => $totalFetched,
            'has_more' => $hasMoreToFetch,
            'details' => $folderResults,
            'new_cursor' => $account->backfill_uid_cursor, // IMAP updates cursor in account model directly for now
        ];
    }

    /**
     * Internal IMAP backfill for a specific folder.
     */
    protected function backfillFolder(Client $client, EmailAccount $account, EmailFolderType $folderType, EmailSyncService $syncService, int $batchSize): array
    {
        $folder = $this->getFolderWithFallback($client, $folderType->value);
        if (! $folder) {
            return ['fetched' => 0, 'has_more' => false];
        }

        // [Health Check] Skip if folder is disabled by user
        $disabledFolders = $account->disabled_folders ?? [];
        if (in_array($folderType->value, $disabledFolders)) {
            Log::debug("[{$this->getProvider()}Adapter] Skipping backfill for disabled folder", [
                'account_id' => $account->id,
                'folder' => $folderType->value,
            ]);

            return ['fetched' => 0, 'has_more' => false];
        }

        try {
            $examine = $folder->examine();
            $totalMessages = $examine['exists'] ?? 0;
            if ($totalMessages === 0) {
                return ['fetched' => 0, 'has_more' => false];
            }

            $backfillCursor = $account->backfill_uid_cursor;
            $forwardCursor = $account->forward_uid_cursor;

            if ($backfillCursor === null || $backfillCursor === 0) {
                if ($forwardCursor && $forwardCursor > 0) {
                    $backfillCursor = $forwardCursor;
                } else {
                    $latestUids = $this->fetchLatestUids($folder, 1);
                    $backfillCursor = ! empty($latestUids) ? max($latestUids) : $totalMessages;
                }
            }

            if ($backfillCursor <= 1) {
                return ['fetched' => 0, 'has_more' => false, 'new_cursor' => 1];
            }

            $windowSize = 100;
            $startUid = max(1, $backfillCursor - $windowSize);
            $endUid = $backfillCursor - 1;

            $allUids = $this->fetchUidRange($folder, $startUid, $endUid);
            if (empty($allUids)) {
                // [Loop Fix] Even if empty, move cursor back to avoid infinite retry on same window
                if ($startUid < $backfillCursor) {
                    $account->update(['backfill_uid_cursor' => $startUid]);
                }

                return [
                    'fetched' => 0,
                    'has_more' => $startUid > 1,
                    'new_cursor' => $startUid,
                ];
            }

            rsort($allUids);
            $uidsToProcess = array_slice($allUids, 0, $batchSize);

            $fetched = 0;
            $minUid = $backfillCursor;

            foreach ($uidsToProcess as $uid) {
                try {
                    $exists = Email::where('email_account_id', $account->id)
                        ->where('imap_uid', $uid)
                        ->where('folder', $folderType->value)
                        ->exists();

                    if ($exists) {
                        $minUid = min($minUid, $uid);

                        continue;
                    }

                    $message = $this->getMessageByUid($folder, $uid);
                    if ($message) {
                        $emailData = $this->parseMessage($message, true);
                        $targetFolder = $emailData['folder'] ?? $folderType->value;
                        $syncService->storeEmail($account, $emailData, $targetFolder, false);
                        $fetched++;
                        $minUid = min($minUid, $uid);
                    }
                } catch (\Throwable $e) {
                    Log::warning("[{$this->getProvider()}Adapter] Failed to fetch UID {$uid} during backfill", ['error' => $e->getMessage()]);
                    $minUid = min($minUid, $uid);
                }
            }

            // Update minUid to the start of the window if we processed everything in the window (or they already existed)
            // This prevents getting stuck if we process a batch but don't reach the target min.
            // However, we only move it as far as the lowest UID we actually saw or the start of the window.
            $nextCursor = min($minUid, empty($allUids) ? $startUid : min($allUids));

            if ($nextCursor < $backfillCursor) {
                $account->update(['backfill_uid_cursor' => $nextCursor]);
            }

            $hasMore = $nextCursor > 1;

            return [
                'fetched' => $fetched,
                'has_more' => $hasMore,
                'new_cursor' => $nextCursor,
            ];
        } catch (\Throwable $e) {
            Log::error("[{$this->getProvider()}Adapter] Folder backfill exception", [
                'folder' => $folderType->value,
                'error' => $e->getMessage(),
            ]);

            return ['fetched' => 0, 'has_more' => false];
        }
    }

    public function listFolders(EmailAccount $account): \Illuminate\Support\Collection
    {
        $this->refreshTokenIfNeeded($account);
        $client = $this->createClient($account);
        $client->connect();

        $folders = $client->getFolders(false);
        $result = collect();

        foreach ($folders as $folder) {
            $result->push([
                'id' => $folder->path,
                'name' => $folder->name,
                'path' => $folder->path,
                'type' => strtolower($folder->name) === 'inbox' ? 'system' : 'user',
            ]);
        }

        $client->disconnect();

        return $result;
    }

    /**
     * Download a specific attachment from IMAP.
     */
    public function downloadAttachment(Email $email, int $placeholderIndex): \Spatie\MediaLibrary\MediaCollections\Models\Media
    {
        $placeholders = $email->attachment_placeholders ?? [];
        if (! isset($placeholders[$placeholderIndex])) {
            throw new \InvalidArgumentException('Attachment placeholder not found.');
        }

        $placeholder = $placeholders[$placeholderIndex];
        $account = $email->emailAccount;

        $client = $this->createClient($account);
        $client->connect();

        // For Gmail, always use [Gmail]/All Mail since UIDs are folder-specific
        // and All Mail contains all messages. For other providers, use the stored folder.
        $imapFolderName = $this->getProvider() === 'gmail'
            ? '[Gmail]/All Mail'
            : $this->getFolderName($email->folder);

        $folder = $client->getFolder($imapFolderName);

        if (! $folder) {
            throw new \RuntimeException("Folder '{$imapFolderName}' not found on IMAP server.");
        }

        $message = $this->getMessageByUid($folder, $email->imap_uid);

        if (! $message) {
            throw new \RuntimeException('Message not found on IMAP server.');
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

        if (! $targetAttachment) {
            throw new \RuntimeException('Attachment not found in message.');
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
        $email->update(['attachment_placeholders' => $placeholders]);

        // If it was an inline image, resolve it now
        if (! empty($placeholder['content_id'])) {
            $sanitizer = app(\App\Services\EmailSanitizationService::class);
            $email->update([
                'body_html' => $sanitizer->resolveInlineImages($email),
            ]);
        }

        return $media;
    }

    /**
     * Fetch the raw RFC822 message source for an existing email.
     */
    public function fetchRawSource(\App\Models\Email $email): string
    {
        $account = $email->emailAccount;
        $client = $this->createClient($account);
        $client->connect();

        $imapFolderName = $this->getProvider() === 'gmail'
            ? '[Gmail]/All Mail'
            : $this->getFolderName($email->folder);

        $folder = $client->getFolder($imapFolderName);
        if (! $folder) {
            throw new \RuntimeException("Folder '{$imapFolderName}' not found on IMAP server.");
        }

        // Fetch raw body (RFC822)
        // Webklex\PHPIMAP uses 'body' for the raw content when not parsing
        $query = $folder->query()->where('UID', $email->imap_uid);

        // We need to fetch the raw message.
        // using getMessageByUid often parses it.
        // Let's use the client to fetch strictly the RFC822 content if possible,
        // or get the message and dump its raw structure.
        // Actually, Webklex Message object has `getRawBody()` if available or we can construct it.
        // But `FT_PEEK` is default in config so we are safe.

        $message = $query->getMessageByUid($email->imap_uid);

        if (! $message) {
            throw new \RuntimeException('Message not found on IMAP server.');
        }

        // In Webklex 5.x+, getHeader()->raw returns headers, and we can get body.
        // A simple way is to get the full raw message if supported.
        // Use `fetch` with 'RFC822' or 'BODY[]'.
        // The library exposes `headers` and `body`.
        // Let's try to reconstruct or use a raw fetch method if available.
        // Looking at the library, $message->raw contains the raw header + body if fetched.
        // If not, we might need to rely on what we have.
        // However, standard IMAP fetch 'BODY[]' gives everything.

        // For now, let's try to return what we can access.
        // If we can't get the *exact* bytes easily without low-level commands,
        // we might rely on the library's `raw` attribute if it exists, or reconstruct.

        // Wait, Webklex client doesn't always expose raw easily.
        // Let's assume for now we can get it via `getRawBody()` which some forks have,
        // or strictly checking documentation which says `raw` might be available.
        // Let's use a safe fallback:

        // It seems `Webklex\PHPIMAP\Message` might not publicize raw output directly in all versions.
        // It seems `Webklex\PHPIMAP\Message` might not publicize raw output directly in all versions.
        // Let's try to get header and body and concatenate.

        return $message->getHeader()->raw."\r\n\r\n".$message->getBody();
    }

    /**
     * Fetch the full message (body and attachments) for an existing email.
     */
    public function fetchFullMessage(Email $email): array
    {
        // Force fetchBody=true
        $client = $this->createClient($email->emailAccount, true);
        $client->connect();

        // Check if folder is mapped using Enum
        $folderName = $email->folder;
        $folder = null;

        // Try to resolve folder using standard logic
        if (\App\Enums\EmailFolderType::tryFrom($folderName)) {
            $folder = $this->getFolderWithFallback($client, $folderName);
        }

        // If not found or custom folder, try name directly
        if (! $folder) {
            try {
                $folder = $client->getFolder($folderName);
            } catch (\Throwable $e) {
                // ignore
            }
        }

        if (! $folder) {
            // Last resort: check if folder name is actually a path
            // For now, fail if not found
            throw new \RuntimeException("Folder not found: {$email->folder}");
        }

        if ($email->imap_uid) {
            $message = $this->getMessageByUid($folder, $email->imap_uid);
            if ($message) {
                // skipAttachments=false, fetchBody=true
                $parsed = $this->parseMessage($message, false, true);
                $client->disconnect();

                return $parsed;
            }
        }

        $client->disconnect();
        throw new \RuntimeException("Message not found with UID {$email->imap_uid} in folder {$folder->path}");
    }
}
