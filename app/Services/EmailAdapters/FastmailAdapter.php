<?php

namespace App\Services\EmailAdapters;

/**
 * Adapter for Fastmail.
 */
class FastmailAdapter extends CustomImapAdapter
{
    public function getProvider(): string
    {
        return 'fastmail';
    }

    public function getFolderMapping(): array
    {
        return config('email.imap_folders.fastmail');
    }
}
