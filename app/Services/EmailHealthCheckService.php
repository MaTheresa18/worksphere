<?php

namespace App\Services;

use App\Models\EmailAccount;
use Spatie\Dns\Dns;

class EmailHealthCheckService
{
    protected $dns;

    public function __construct()
    {
        $this->dns = (new Dns)->useNameserver('8.8.8.8');
    }

    public function checkHealth(EmailAccount $account): array
    {
        $domain = $this->getDomainFromEmail($account->email);

        return [
            'mx' => $this->checkMx($domain),
            'spf' => $this->checkSpf($domain),
            'dmarc' => $this->checkDmarc($domain),
            'dkim' => $this->checkDkim($domain), // Note: DKIM requires knowing the selector, which we might not always know
        ];
    }

    protected function getDomainFromEmail(string $email): string
    {
        return substr(strrchr($email, '@'), 1);
    }

    public function checkMx(string $domain): array
    {
        try {
            $records = $this->dns->getRecords($domain, 'MX');
            $formattedRecords = array_map(function ($record) {
                return $record->toArray();
            }, $records);

            return [
                'status' => count($records) > 0,
                'records' => $formattedRecords,
                'message' => count($records) > 0 ? 'MX records found.' : 'No MX records found.',
            ];
        } catch (\Exception $e) {
            return [
                'status' => false,
                'error' => $e->getMessage(),
                'message' => 'Failed to retrieve MX records.',
            ];
        }
    }

    public function checkSpf(string $domain): array
    {
        try {
            $records = $this->dns->getRecords($domain, 'TXT');
            $spfRecord = null;
            $status = false;

            foreach ($records as $record) {
                if ($record instanceof \Spatie\Dns\Records\TXT && str_contains($record->txt(), 'v=spf1')) {
                    $spfRecord = $record->txt();
                    $status = true;
                    break;
                }
            }

            return [
                'status' => $status,
                'record' => $spfRecord,
                'message' => $status ? 'SPF record found.' : 'No SPF record found.',
            ];
        } catch (\Exception $e) {
            return [
                'status' => false,
                'error' => $e->getMessage(),
                'message' => 'Failed to retrieve SPF record.',
            ];
        }
    }

    public function checkDmarc(string $domain): array
    {
        try {
            $dmarcDomain = '_dmarc.'.$domain;
            $records = $this->dns->getRecords($dmarcDomain, 'TXT');
            $dmarcRecord = null;
            $status = false;

            foreach ($records as $record) {
                if ($record instanceof \Spatie\Dns\Records\TXT && str_contains($record->txt(), 'v=DMARC1')) {
                    $dmarcRecord = $record->txt();
                    $status = true;
                    break;
                }
            }

            return [
                'status' => $status,
                'record' => $dmarcRecord,
                'message' => $status ? 'DMARC record found.' : 'No DMARC record found.',
            ];
        } catch (\Exception $e) {
            return [
                'status' => false,
                'error' => $e->getMessage(),
                'message' => 'Failed to retrieve DMARC record.',
            ];
        }
    }

    public function checkDkim(string $domain, string $selector = 'default'): array
    {
        // DKIM check is tricky because we need the selector.
        // We'll check for a 'default' selector or maybe 'google'/'k1' for common providers if we want to be fancy,
        // but for now, we might just skip or return a warning if we don't know the selector.
        // Usually, the selector is configured on the sending service.

        // For now, let's just return a generic "unknown" unless we want to try common selectors.
        return [
            'status' => null, // null means "not checked" or "unknown"
            'message' => 'DKIM check requires a selector.',
        ];
    }
}
