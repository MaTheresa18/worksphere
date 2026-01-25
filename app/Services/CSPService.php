<?php

namespace App\Services;

use Illuminate\Support\Str;

class CSPService
{
    /**
     * The nonce for the current request.
     */
    protected ?string $nonce = null;

    /**
     * Get the nonce for the current request, generating one if it doesn't exist.
     */
    public function getNonce(): string
    {
        if (! $this->nonce) {
            $this->nonce = Str::random(32);
        }

        return $this->nonce;
    }
}
