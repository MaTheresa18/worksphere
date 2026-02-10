<?php

namespace App\Services\EmailAdapters;

/**
 * Adapter for GMX.
 */
class GmxAdapter extends CustomImapAdapter
{
    public function getProvider(): string
    {
        return 'gmx';
    }

    public function getFolderMapping(): array
    {
        return config('email.imap_folders.gmx');
    }
}
