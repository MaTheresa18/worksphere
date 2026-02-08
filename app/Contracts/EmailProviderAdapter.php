<?php

namespace App\Contracts;

use App\Models\EmailAccount;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Folder;

/**
 * Interface for provider-specific email operations.
 *
 * Each email provider (Gmail, Outlook, custom IMAP) may have different:
 * - Authentication methods (OAuth vs password)
 * - Folder naming conventions
 * - IMAP command support
 * - Response formats
 */
interface EmailProviderAdapter
{
    /**
     * Get the provider identifier.
     */
    public function getProvider(): string;

    /**
     * Create and configure an IMAP client for the account.
     */
    public function createClient(EmailAccount $account, bool $fetchBody = true): Client;

    /**
     * Get IMAP folder name for a logical folder type.
     *
     * @param  string  $folderType  One of: inbox, sent, drafts, trash, spam, archive
     */
    public function getFolderName(string $folderType): string;

    /**
     * Get all folder mappings.
     *
     * @return array<string, string> Map of folder type => IMAP folder name
     */
    public function getFolderMapping(): array;

    /**
     * Extract UID from an overview item.
     *
     * Different providers may return overview items as objects or arrays.
     *
     * @param  mixed  $item  Overview item (object or array)
     * @return int|null The UID or null if not found
     */
    public function extractUidFromOverview(mixed $item): ?int;

    /**
     * Refresh OAuth token if needed.
     *
     * @return bool True if token was refreshed or not needed, false on failure
     */
    public function refreshTokenIfNeeded(EmailAccount $account): bool;

    /**
     * Check if this provider uses OAuth authentication.
     */
    public function supportsOAuth(): bool;

    /**
     * Get the maximum number of parallel folder syncs allowed.
     */
    public function getMaxParallelFolders(): int;

    /**
     * Fetch UIDs for the latest N messages in a folder.
     *
     * @param  Folder  $folder  The IMAP folder
     * @param  int  $count  Number of UIDs to fetch
     * @return array<int> Array of UIDs (newest first)
     */
    public function fetchLatestUids(Folder $folder, int $count): array;

    /**
     * Fetch UIDs for a range of messages (for full sync).
     *
     * @param  Folder  $folder  The IMAP folder
     * @param  int  $start  Start sequence number (1-based)
     * @param  int  $end  End sequence number
     * @return array<int> Array of UIDs
     */
    public function fetchUidRange(Folder $folder, int $start, int $end): array;

    /**
     * Fetch the latest N messages from a folder.
     *
     * This is the preferred method as it avoids a second query after getting UIDs.
     *
     * @param  Folder  $folder  The IMAP folder
     * @param  int  $count  Number of messages to fetch
     * @return \Illuminate\Support\Collection Collection of messages
     */
    public function fetchLatestMessages(Folder $folder, int $count): \Illuminate\Support\Collection;

    /**
     * Fetch a single message by UID, applying provider-specific modifiers (e.g. Gmail labels).
     *
     * @param  Folder  $folder  The IMAP folder
     * @param  int  $uid  The message UID
     * @return mixed The message object or null if not found
     */
    public function getMessageByUid(Folder $folder, int $uid);

    /**
     * Get high-level folder status (message count, etc.) without exposing IMAP objects.
     */
    public function getFolderStatus(EmailAccount $account, string $folderType): array;

    /**
     * Fetch a chunk of messages for a folder.
     */
    public function fetchMessages(EmailAccount $account, string $folderType, int $offset, int $limit, bool $fetchBody = true): \Illuminate\Support\Collection;

    /**
     * Fetch the latest N messages for a folder (for seeding).
     */
    public function fetchLatestMessagesForAccount(EmailAccount $account, string $folderType, int $count, bool $fetchBody = true): \Illuminate\Support\Collection;

    /**
     * Fetch new messages since the last sync cursor (incremental).
     */
    public function fetchIncrementalUpdates(EmailAccount $account, bool $fetchBody = true): \Illuminate\Support\Collection;

    /**
     * Subscribe to real-time notifications.
     */
    public function subscribeToNotifications(EmailAccount $account): bool;

    /**
     * Run a backfill batch for the account.
     * 
     * @return array{fetched: int, has_more: bool, new_cursor: mixed}
     */
    public function backfill(EmailAccount $account, ?string $folderType, int $batchSize, bool $fetchBody = true): array;

    /**
     * List all available folders/labels from the provider.
     *
     * @param EmailAccount $account
     * @return \Illuminate\Support\Collection Collection of folder data arrays
     */
    public function listFolders(EmailAccount $account): \Illuminate\Support\Collection;

    /**
     * Download a specific attachment for an email on-demand.
     *
     * @param  \App\Models\Email  $email
     * @param  int  $placeholderIndex
     * @return \Spatie\MediaLibrary\MediaCollections\Models\Media
     */
    public function downloadAttachment(\App\Models\Email $email, int $placeholderIndex): \Spatie\MediaLibrary\MediaCollections\Models\Media;

    /**
     * Fetch the full message (body and attachments) for an existing email.
     *
     * @return array The parsed email data including body and attachments.
     */
    public function fetchFullMessage(\App\Models\Email $email): array;

    /**
     * Fetch the raw RFC822 message source for an existing email.
     *
     * @return string The raw message content (headers + body).
     */
    public function fetchRawSource(\App\Models\Email $email): string;
}
