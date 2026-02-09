<?php

namespace App\Console\Commands;

use App\Jobs\FetchLatestEmailsJob;
use App\Services\EmailSyncService;
use Illuminate\Console\Command;

class EmailSyncIncremental extends Command
{
    protected $signature = 'email:sync-incremental';

    protected $description = 'Dispatch forward crawler jobs for active email accounts';

    public function handle(EmailSyncService $syncService): int
    {
        $accounts = $syncService->getAccountsForIncrementalSync();

        if ($accounts->isEmpty()) {
            $this->line('No accounts ready for forward sync.');

            return self::SUCCESS;
        }

        $this->info("Dispatching forward crawler for {$accounts->count()} account(s).");

        foreach ($accounts as $account) {
            FetchLatestEmailsJob::dispatch($account->id);
            $this->line("  → Queued: {$account->email} (forward: {$account->forward_uid_cursor}, backfill: ".($account->backfill_complete ? 'complete' : $account->backfill_uid_cursor).')');
        }

        return self::SUCCESS;
    }
}
