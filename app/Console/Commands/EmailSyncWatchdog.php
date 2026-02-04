<?php

namespace App\Console\Commands;

use App\Services\EmailSyncService;
use Illuminate\Console\Command;

class EmailSyncWatchdog extends Command
{
    protected $signature = 'email:sync-watchdog';

    protected $description = 'Watch for email accounts that need sync and dispatch jobs';

    public function handle(EmailSyncService $syncService): int
    {
        $this->info("Starting Email Sync Watchdog...");

        // 1. Kickstart any brand new accounts (Pending)
        $pendingAccounts = \App\Models\EmailAccount::where('sync_status', \App\Enums\EmailSyncStatus::Pending)->get();
        
        foreach ($pendingAccounts as $account) {
            $this->info("Starting initial seed for: {$account->email}");
            $syncService->startSeed($account);
        }

        // 2. Rescue stuck jobs (Self-healing)
        // This checks timestamps and restarts Backfill/Forward jobs if they died.
        $syncService->rescueStuckAccounts();

        $this->info('Watchdog cycle completed. Checked for stuck accounts.');

        return self::SUCCESS;
    }
}
