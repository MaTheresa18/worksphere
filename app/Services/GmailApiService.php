<?php

namespace App\Services;

use App\Models\EmailAccount;
use Google\Client;
use Google\Service\Gmail;
use Illuminate\Support\Facades\Log;

class GmailApiService
{
    protected Client $client;

    public function __construct()
    {
        $this->client = new Client();
        $this->client->setClientId(config('services.google.client_id'));
        $this->client->setClientSecret(config('services.google.client_secret'));
    }

    /**
     * Set up client with account tokens.
     */
    protected function setupForAccount(EmailAccount $account): void
    {
        $this->client->setAccessToken([
            'access_token' => $account->access_token,
            'refresh_token' => $account->refresh_token,
            'expires_in' => $account->token_expires_at ? $account->token_expires_at->diffInSeconds(now()) : 3600,
            'created' => $account->updated_at->timestamp,
        ]);

        if ($this->client->isAccessTokenExpired()) {
            $newToken = $this->client->fetchAccessTokenWithRefreshToken($account->refresh_token);
            if (isset($newToken['access_token'])) {
                $account->update([
                    'access_token' => $newToken['access_token'],
                    'token_expires_at' => now()->addSeconds($newToken['expires_in'] ?? 3600),
                ]);
            }
        }
    }

    /**
     * List messages in a folder (label).
     */
    public function listMessages(EmailAccount $account, string $labelId, int $maxResults = 100, ?string $pageToken = null): array
    {
        $this->setupForAccount($account);
        $gmail = new Gmail($this->client);

        $params = [
            'labelIds' => [$labelId],
            'maxResults' => $maxResults,
        ];

        if ($pageToken) {
            $params['pageToken'] = $pageToken;
        }

        $results = $gmail->users_messages->listUsersMessages('me', $params);
        
        return [
            'messages' => $results->getMessages(),
            'nextPageToken' => $results->getNextPageToken(),
            'resultSizeEstimate' => $results->getResultSizeEstimate(),
        ];
    }

    /**
     * Get full message details.
     */
    public function getMessage(EmailAccount $account, string $messageId): Gmail\Message
    {
        $this->setupForAccount($account);
        $gmail = new Gmail($this->client);

        return $gmail->users_messages->get('me', $messageId, ['format' => 'full']);
    }

    /**
     * Get label details (for status).
     */
    public function getLabel(EmailAccount $account, string $labelId): Gmail\Label
    {
        $this->setupForAccount($account);
        $gmail = new Gmail($this->client);

        return $gmail->users_labels->get('me', $labelId);
    }

    /**
     * Set up push notifications (watch).
     */
    public function watch(EmailAccount $account, string $topicName): array
    {
        $this->setupForAccount($account);
        $gmail = new Gmail($this->client);

        $request = new Gmail\WatchRequest();
        $request->setTopicName($topicName);
        $request->setLabelIds(['INBOX']); // We usually only watch Inbox for real-time

        $response = $gmail->users->watch('me', $request);

        return [
            'historyId' => $response->getHistoryId(),
            'expiration' => $response->getExpiration(),
        ];
    }

    /**
     * Stop push notifications.
     */
    public function stopWatch(EmailAccount $account): void
    {
        $this->setupForAccount($account);
        $gmail = new Gmail($this->client);

        $gmail->users->stop('me');
    }

    /**
     * List history (what changed since historyId).
     */
    public function listHistory(EmailAccount $account, string $startHistoryId, ?string $pageToken = null): array
    {
        $this->setupForAccount($account);
        $gmail = new Gmail($this->client);

        $params = ['startHistoryId' => $startHistoryId];
        if ($pageToken) {
            $params['pageToken'] = $pageToken;
        }

        $results = $gmail->users_history->listUsersHistory('me', $params);

        return [
            'history' => $results->getHistory(),
            'nextPageToken' => $results->getNextPageToken(),
        ];
    }
}
