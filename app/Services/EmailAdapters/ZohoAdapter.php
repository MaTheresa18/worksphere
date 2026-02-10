<?php

namespace App\Services\EmailAdapters;

/**
 * Adapter for Zoho Mail.
 * Supports both App Passwords and OAuth2.
 */
class ZohoAdapter extends CustomImapAdapter
{
    public function getProvider(): string
    {
        return 'zoho';
    }

    public function supportsOAuth(): bool
    {
        return true;
    }

    public function getFolderMapping(): array
    {
        return config('email.imap_folders.zoho');
    }
}
