<?php

namespace App\Services\EmailAdapters;

use App\Enums\EmailFolderType;
use App\Models\EmailAccount;
use App\Services\EmailSyncService;
use App\Services\GmailApiService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Folder;

/**
 * Modern Gmail Adapter using Gmail API.
 */
class GmailAdapter extends BaseEmailAdapter
{
    protected GmailApiService $apiService;

    public function __construct()
    {
        parent::__construct();
        $this->apiService = app(GmailApiService::class);
    }

    public function getProvider(): string
    {
        return 'gmail';
    }

    /**
     * Gmail API doesn't use the IMAP client, but we keep this for interface compatibility
     * and potential fallback if needed, though we primarily use the API now.
     */
    public function createClient(EmailAccount $account): Client
    {
        // For Gmail, we prefer the API service. 
        // We only implement this if some part of the system still strictly requires an IMAP Client object.
        return parent::createClient($account);
    }

    public function getFolderName(string $folderType): string
    {
        return match ($folderType) {
            EmailFolderType::Inbox->value => 'INBOX',
            EmailFolderType::Sent->value => 'SENT',
            EmailFolderType::Drafts->value => 'DRAFT',
            EmailFolderType::Trash->value => 'TRASH',
            EmailFolderType::Spam->value => 'SPAM',
            EmailFolderType::Archive => 'ALL', // Special mapping or handled by lack of labels
            default => 'INBOX',
        };
    }

    public function getFolderMapping(): array
    {
        return [
            EmailFolderType::Inbox->value => 'INBOX',
            EmailFolderType::Sent->value => 'SENT',
            EmailFolderType::Drafts->value => 'DRAFT',
            EmailFolderType::Trash->value => 'TRASH',
            EmailFolderType::Spam->value => 'SPAM',
        ];
    }

    /**
     * Get high-level folder status via API.
     */
    public function getFolderStatus(EmailAccount $account, string $folderType): array
    {
        try {
            $labelId = $this->getFolderName($folderType);
            $label = $this->apiService->getLabel($account, $labelId);

            return [
                'exists' => $label->getMessagesTotal() ?? 0,
                'unread' => $label->getMessagesUnread() ?? 0,
            ];
        } catch (\Throwable $e) {
            Log::error('[GmailAdapter] Failed to get folder status', [
                'account_id' => $account->id,
                'folder' => $folderType,
                'error' => $e->getMessage(),
            ]);
            return ['exists' => 0];
        }
    }

    /**
     * Fetch a chunk of messages via API.
     */
    public function fetchMessages(EmailAccount $account, string $folderType, int $offset, int $limit): Collection
    {
        try {
            $labelId = $this->getFolderName($folderType);
            
            // Gmail API uses page tokens instead of numeric offsets.
            // For the initial implementation, we'll fetch the first batch.
            // Improvement: Store page tokens in sync_cursor if offset > limit.
            $result = $this->apiService->listMessages($account, $labelId, $limit);
            $messages = collect($result['messages'] ?? []);

            return $messages->map(function ($msgSummary) use ($account) {
                $fullMsg = $this->apiService->getMessage($account, $msgSummary->getId());
                return $this->parseGmailMessage($fullMsg);
            });
        } catch (\Throwable $e) {
            Log::error('[GmailAdapter] Failed to fetch messages', [
                'account_id' => $account->id,
                'folder' => $folderType,
                'error' => $e->getMessage(),
            ]);
            return collect();
        }
    }

    /**
     * Fetch latest messages for a folder (Gmail API implementation).
     */
    public function fetchLatestMessagesForAccount(EmailAccount $account, string $folderType, int $count): Collection
    {
        try {
            $labelId = $this->getFolderName($folderType);
            $result = $this->apiService->listMessages($account, $labelId, $count);
            
            $messages = collect();
            foreach ($result['messages'] as $msg) {
                $fullMsg = $this->apiService->getMessage($account, $msg->getId());
                $messages->push($this->parseGmailMessage($fullMsg));
            }

            return $messages;
        } catch (\Throwable $e) {
            Log::error('[GmailAdapter] Failed to fetch latest messages', [
                'account_id' => $account->id,
                'folder' => $folderType,
                'error' => $e->getMessage(),
            ]);
            return collect();
        }
    }

    /**
     * Fetch incremental updates (Gmail API implementation).
     */
    public function fetchIncrementalUpdates(EmailAccount $account): Collection
    {
        $cursor = $account->sync_cursor ?? [];
        $startHistoryId = $cursor['history_id'] ?? null;

        if (!$startHistoryId) {
            // No history ID yet, fallback to fetching latest from Inbox
            return $this->fetchLatestMessagesForAccount($account, 'inbox', 50);
        }

        try {
            $result = $this->apiService->listHistory($account, (string)$startHistoryId);
            $newMessages = collect();

            if (!empty($result['history'])) {
                foreach ($result['history'] as $historyRecord) {
                    $messagesAdded = $historyRecord->getMessagesAdded();
                    if ($messagesAdded) {
                        foreach ($messagesAdded as $msgAdded) {
                            $msg = $msgAdded->getMessage();
                            $fullMsg = $this->apiService->getMessage($account, $msg->getId());
                            $newMessages->push($this->parseGmailMessage($fullMsg));
                        }
                    }
                }
            }

            return $newMessages;
        } catch (\Throwable $e) {
            Log::error('[GmailAdapter] Failed to fetch incremental updates', [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);
            
            // If history ID is expired, fallback
            if (str_contains(strtolower($e->getMessage()), 'expired')) {
                return $this->fetchLatestMessagesForAccount($account, 'inbox', 50);
            }
            
            return collect();
        }
    }

    /**
     * Subscribe to real-time notifications (Gmail API implementation).
     */
    public function subscribeToNotifications(EmailAccount $account): bool
    {
        $topicName = config('services.google.pubsub_topic');
        if (!$topicName) {
            Log::warning('[GmailAdapter] Pub/Sub topic not configured');
            return false;
        }

        try {
            $result = $this->apiService->watch($account, $topicName);
            
            // Store the initial historyId
            $cursor = $account->sync_cursor ?? [];
            $cursor['history_id'] = $result['historyId'];
            $account->update(['sync_cursor' => $cursor]);

            return true;
        } catch (\Throwable $e) {
            Log::error('[GmailAdapter] Failed to setup watch', [
                'account_id' => $account->id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Standardized parseMessage for unified interface.
     */
    public function parseMessage($message, bool $skipAttachments = false): array
    {
        return $this->parseGmailMessage($message);
    }

    /**
     * Parse Gmail API message to standardized array.
     */
    protected function parseGmailMessage(\Google\Service\Gmail\Message $message): array
    {
        $payload = $message->getPayload();
        $headers = collect($payload->getHeaders());
        
        $getHeader = function($name) use ($headers) {
            $header = $headers->first(fn($h) => strtolower($h->getName()) === strtolower($name));
            return $header ? $header->getValue() : null;
        };

        $from = $getHeader('From');
        $fromEmail = 'unknown@unknown.com';
        $fromName = $from;

        if (preg_match('/^(.*?)\s*<(.*?)>$/', $from, $matches)) {
            $fromName = trim($matches[1], '" ');
            $fromEmail = $matches[2];
        } else {
            $fromEmail = $from;
        }

        $body = $this->extractGmailBody($payload);

        return [
            'message_id' => $getHeader('Message-ID'),
            'thread_id' => $message->getThreadId(),
            'gmail_id' => $message->getId(),
            'from_email' => $fromEmail,
            'from_name' => $fromName ?: $fromEmail,
            'to' => $this->parseGmailRecipients($getHeader('To')),
            'cc' => $this->parseGmailRecipients($getHeader('Cc')),
            'bcc' => [],
            'subject' => $getHeader('Subject') ?? '(No Subject)',
            'preview' => trim(mb_substr(strip_tags($body['plain'] ?: $body['html']), 0, 200)),
            'body_html' => $body['html'],
            'body_plain' => $body['plain'],
            'history_id' => $message->getHistoryId(),
            'is_read' => !in_array('UNREAD', $message->getLabelIds() ?? []),
            'is_starred' => in_array('STARRED', $message->getLabelIds() ?? []),
            'has_attachments' => $this->hasGmailAttachments($payload),
            'imap_uid' => null, // Gmail API doesn't use IMAP UIDs in the same way
            'date' => $message->getInternalDate() ? date('Y-m-d H:i:s', $message->getInternalDate() / 1000) : now(),
        ];
    }

    protected function extractGmailBody($payload): array
    {
        $html = '';
        $plain = '';

        $processPart = function($part) use (&$html, &$plain, &$processPart) {
            $mimeType = $part->getMimeType();
            $data = $part->getBody()->getData();
            
            if ($data !== null) {
                $data = strtr($data, '-_', '+/'); // Gmail base64url to base64
                if ($mimeType === 'text/html') {
                    $html .= base64_decode($data);
                } elseif ($mimeType === 'text/plain') {
                    $plain .= base64_decode($data);
                }
            }

            if ($part->getParts()) {
                foreach ($part->getParts() as $subPart) {
                    $processPart($subPart);
                }
            }
        };

        $processPart($payload);

        return ['html' => $html, 'plain' => $plain];
    }

    protected function parseGmailRecipients($header): array
    {
        if (!$header) return [];
        
        $recipients = [];
        $parts = explode(',', $header);
        
        foreach ($parts as $part) {
            $part = trim($part);
            if (preg_match('/^(.*?)\s*<(.*?)>$/', $part, $matches)) {
                $recipients[] = [
                    'name' => trim($matches[1], '" '),
                    'email' => $matches[2]
                ];
            } else {
                $recipients[] = ['name' => null, 'email' => $part];
            }
        }
        
        return $recipients;
    }

    protected function hasGmailAttachments($payload): bool
    {
        if ($payload->getFilename()) return true;
        
        if ($payload->getParts()) {
            foreach ($payload->getParts() as $part) {
                if ($part->getFilename()) return true;
                if ($this->hasGmailAttachments($part)) return true;
            }
        }
        
        return false;
    }

    /**
     * Backfill implementation for Gmail using API.
     */
    public function backfill(EmailAccount $account, ?string $folderType, int $batchSize): array
    {
        try {
            $labelId = $this->getFolderName($folderType ?: EmailFolderType::Inbox->value);
            $cursor = $account->sync_cursor ?? [];
            $nextPageToken = $cursor['backfill_page_token'] ?? null;

            $result = $this->apiService->listMessages($account, $labelId, $batchSize, $nextPageToken);
            $messages = collect($result['messages'] ?? []);

            if ($messages->isEmpty()) {
                return ['fetched' => 0, 'has_more' => false];
            }

            // [NEW] Update backfill_page_token on the model immediately so storeEmail sees it and preserves it
            $cursor = $account->sync_cursor ?? [];
            $cursor['backfill_page_token'] = $result['nextPageToken'] ?? null;
            $account->sync_cursor = $cursor;
            $account->save();

            $fetched = 0;
            foreach ($messages as $msgSummary) {
                try {
                    $fullMsg = $this->apiService->getMessage($account, $msgSummary->getId());
                    $emailData = $this->parseGmailMessage($fullMsg);
                    
                    // Store using sync service (provider agnostic)
                    app(EmailSyncService::class)->storeEmail($account, $emailData, $labelId);
                    $fetched++;
                } catch (\Throwable $e) {
                    Log::warning('[GmailAdapter] Failed to process single message during backfill', [
                        'account_id' => $account->id,
                        'message_id' => $msgSummary->getId(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Reload cursor from model to include any history_id updates from storeEmail
            $cursor = $account->sync_cursor ?? [];
            
            if (empty($cursor['labels'][$labelId])) {
                $cursor['labels'][$labelId] = [
                    'total' => $result['resultSizeEstimate'] ?? 0,
                    'synced' => 0,
                    'priority' => 1,
                ];
            }
            
            $cursor['backfill_page_token'] = $result['nextPageToken'] ?? null;
            $cursor['labels'][$labelId]['total'] = max($cursor['labels'][$labelId]['total'], $result['resultSizeEstimate'] ?? 0);
            $cursor['labels'][$labelId]['synced'] += $fetched;
            
            $account->sync_cursor = $cursor;
            $account->save();

            Log::info('[GmailAdapter] Backfill batch completed', [
                'account_id' => $account->id,
                'folder' => $labelId,
                'fetched' => $fetched,
                'total_synced' => $cursor['labels'][$labelId]['synced'],
                'estimated_total' => $cursor['labels'][$labelId]['total'],
                'has_more' => !empty($result['nextPageToken']),
            ]);

            return [
                'fetched' => $fetched,
                'has_more' => !empty($result['nextPageToken']),
                'new_cursor' => $cursor['backfill_page_token'],
            ];
        } catch (\Throwable $e) {
            Log::error('[GmailAdapter] Backfill failed', [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);
            return ['fetched' => 0, 'has_more' => false];
        }
    }
}
