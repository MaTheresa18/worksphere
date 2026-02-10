<?php

namespace App\Services\EmailAdapters;

/**
 * Adapter for Web.de.
 */
class WebDeAdapter extends CustomImapAdapter
{
    public function getProvider(): string
    {
        return 'webde';
    }

    public function getFolderMapping(): array
    {
        return config('email.imap_folders.webde');
    }
}
