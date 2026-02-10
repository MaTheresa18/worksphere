<?php

namespace App\Services\EmailAdapters;

/**
 * Adapter for Yahoo Mail.
 * Supports both App Passwords and OAuth2.
 */
class YahooAdapter extends CustomImapAdapter
{
    public function getProvider(): string
    {
        return 'yahoo';
    }

    public function supportsOAuth(): bool
    {
        return true;
    }

    public function getFolderMapping(): array
    {
        return config('email.imap_folders.yahoo');
    }
}
