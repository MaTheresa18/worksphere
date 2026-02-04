<?php

namespace App\Services\EmailAdapters;

use App\Enums\EmailFolderType;
use App\Models\EmailAccount;
use Illuminate\Support\Facades\Log;
use Webklex\PHPIMAP\Folder;

/**
 * Optimized Gmail Adapter.
 * 
 * Uses X-GM-LABELS for accurate folder mapping and batch fetching for performance.
 */
class GmailAdapter extends BaseEmailAdapter
{
    /**
     * Build IMAP client with Gmail-specific extensions.
     */
    public function createClient(EmailAccount $account): \Webklex\PHPIMAP\Client
    {
        // Always refresh token before connecting
        $this->refreshTokenIfNeeded($account);

        $config = $this->buildBaseConfig($account);
        $config['authentication'] = 'oauth';
        $config['password'] = $account->access_token;
        
        // Always request Gmail extensions
        $config['extensions'] = ['X-GM-LABELS', 'X-GM-MSGID', 'X-GM-THRID'];
        
        Log::debug('[GmailAdapter] Creating client', [
            'account_id' => $account->id,
            'email' => $account->email,
            'has_token' => !empty($account->access_token),
        ]);

        return $this->clientManager->make($config);
    }

    /**
     * Gmail supports OAuth2 token refresh.
     */
    public function refreshTokenIfNeeded(EmailAccount $account): bool
    {
        if (!$account->needsTokenRefresh()) {
            return true;
        }

        try {
            $service = app(\App\Services\EmailAccountService::class);
            return $service->refreshToken($account);
        } catch (\Throwable $e) {
            Log::error('[GmailAdapter] Token refresh exception', [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function getMaxParallelFolders(): int
    {
        // Gmail is strict about concurrent connections
        return config('email.max_parallel_folders.gmail', 2);
    }

    public function getFolderName(string $folderType): string
    {
        return match ($folderType) {
            EmailFolderType::Inbox->value => 'INBOX',
            EmailFolderType::Sent->value => '[Gmail]/Sent Mail',
            EmailFolderType::Drafts->value => '[Gmail]/Drafts',
            EmailFolderType::Trash->value => '[Gmail]/Trash',
            EmailFolderType::Spam->value => '[Gmail]/Spam',
            EmailFolderType::Archive->value => '[Gmail]/All Mail',
            default => 'INBOX',
        };
    }

    public function getFolderMapping(): array
    {
        return [
            EmailFolderType::Inbox->value => 'INBOX',
            EmailFolderType::Sent->value => '[Gmail]/Sent Mail',
            EmailFolderType::Drafts->value => '[Gmail]/Drafts',
            EmailFolderType::Trash->value => '[Gmail]/Trash',
            EmailFolderType::Spam->value => '[Gmail]/Spam',
            EmailFolderType::Archive->value => '[Gmail]/All Mail',
        ];
    }

    /**
     * Efficiently fetch the latest N messages from a folder using batch fetch.
     */
    public function fetchLatestMessages(Folder $folder, int $count): \Illuminate\Support\Collection
    {
        $uids = $this->fetchLatestUids($folder, $count);
        
        if (empty($uids)) {
            return collect();
        }

        Log::debug('[GmailAdapter] Batch fetching messages', [
            'folder' => $folder->path,
            'count' => count($uids),
        ]);

        // Use range query with extensions to get everything in one go
        $range = min($uids) . ":" . max($uids);
        
        return $folder->query()
            ->whereUid($range)
            ->setExtensions(['X-GM-LABELS', 'X-GM-MSGID', 'X-GM-THRID'])
            ->get();
    }

    /**
     * Fetch a single message by UID with extensions.
     */
    public function getMessageByUid(Folder $folder, int $uid)
    {
        return $folder->query()
            ->whereUid($uid)
            ->setExtensions(['X-GM-LABELS', 'X-GM-MSGID', 'X-GM-THRID'])
            ->get()
            ->first();
    }

    /**
     * Parse Gmail message, extracting folder from X-GM-LABELS.
     */
    public function parseMessage($message, bool $skipAttachments = false): array
    {
        $data = parent::parseMessage($message, $skipAttachments);

        // Extract and clean Gmail Labels
        $labels = $this->extractLabels($message);
        $normalizedLabels = array_map('strtolower', $labels);

        Log::debug('[GmailAdapter] Parsing Message Labels', [
            'uid' => $data['imap_uid'] ?? 'unknown',
            'subject' => substr($data['subject'] ?? '', 0, 30),
            'labels' => $labels,
            'folder_path' => $message->getFolder() ? $message->getFolder()->path : 'unknown',
        ]);

        $folder = $this->mapLabelsToFolder($normalizedLabels);
        
        // Fallback: If no labels match but we are INBOX, map to Inbox
        if ($folder === EmailFolderType::Archive->value) {
            $folderPath = $message->getFolder() ? strtolower($message->getFolder()->path) : '';
            if (str_contains($folderPath, 'inbox')) {
                $folder = EmailFolderType::Inbox->value;
            }
        }

        $data['folder'] = $folder;
        $data['thread_id'] = $message->getAttributes()['X-GM-THRID'] ?? null;
        $data['message_id'] = $message->getAttributes()['X-GM-MSGID'] ?? $data['message_id'];

        return $data;
    }

    /**
     * Extract labels from multiple source candidates.
     */
    protected function extractLabels($message): array
    {
        $attributes = $message->getAttributes();
        $labels = $attributes['X-GM-LABELS'] ?? 
                 $attributes['x-gm-labels'] ?? 
                 $message->getHeader()->get('X-GM-LABELS') ?? 
                 $message->getHeader()->get('x-gm-labels') ?? 
                 [];
                 
        if ($labels instanceof \Webklex\PHPIMAP\Attribute) {
            $labels = $labels->toArray();
        }
        $labels = (array) ($labels ?: []);

        // Clean: unquote and trim
        return array_map(function($l) {
            return trim(str_replace('"', '', (string)$l));
        }, $labels);
    }

    /**
     * Map normalized labels to internal folder types.
     */
    protected function mapLabelsToFolder(array $normalizedLabels): string
    {
        if ($this->hasLabel($normalizedLabels, '\inbox') || $this->hasLabel($normalizedLabels, 'inbox')) {
            return EmailFolderType::Inbox->value;
        }
        if ($this->hasLabel($normalizedLabels, '\sent') || $this->hasLabel($normalizedLabels, 'sent')) {
            return EmailFolderType::Sent->value;
        }
        if ($this->hasLabel($normalizedLabels, '\trash') || $this->hasLabel($normalizedLabels, '\bin') || $this->hasLabel($normalizedLabels, 'trash')) {
            return EmailFolderType::Trash->value;
        }
        if ($this->hasLabel($normalizedLabels, '\spam') || $this->hasLabel($normalizedLabels, '\junk') || $this->hasLabel($normalizedLabels, 'spam')) {
            return EmailFolderType::Spam->value;
        }
        if ($this->hasLabel($normalizedLabels, '\draft') || $this->hasLabel($normalizedLabels, 'drafts')) {
            return EmailFolderType::Drafts->value;
        }

        return EmailFolderType::Archive->value;
    }

    protected function hasLabel(array $labels, string $term): bool
    {
        foreach ($labels as $label) {
            if ($label === $term || str_contains($label, $term)) {
                return true;
            }
        }
        return false;
    }

    public function getProvider(): string
    {
        return 'gmail';
    }
}
