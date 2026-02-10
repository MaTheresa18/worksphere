<?php

namespace App\Services\EmailAdapters;

/**
 * Adapter for Yandex Mail.
 */
class YandexAdapter extends CustomImapAdapter
{
    public function getProvider(): string
    {
        return 'yandex';
    }

    public function supportsOAuth(): bool
    {
        return true;
    }

    public function getFolderMapping(): array
    {
        return config('email.imap_folders.yandex');
    }
}
