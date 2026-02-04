<?php

namespace App\Console\Commands;

use App\Models\EmailAccount;
use Illuminate\Console\Command;

class ResetEmailSyncCommand extends Command
{
    protected $signature = 'email:reset 
        {--account= : The email account ID to reset}
        {--all : Reset all email accounts}
        {--keep-account : Only delete emails, keep the account and credentials}';

    protected $description = 'Reset email sync for an account (deletes emails, resets cursors)';

    public function handle(): int
    {
        if ($this->option('all')) {
            return $this->resetAllAccounts();
        }

        $accountId = $this->option('account');
        if (!$accountId) {
            $this->error('Please specify --account=ID or --all');
            return self::FAILURE;
        }

        $account = EmailAccount::find($accountId);
        if (!$account) {
            $this->error("Email account #{$accountId} not found.");
            return self::FAILURE;
        }

        return $this->resetAccount($account);
    }

    protected function resetAccount(EmailAccount $account): int
    {
        $keepAccount = $this->option('keep-account');
        
        $this->info("Resetting email sync for: {$account->email}");
        $this->newLine();

        // Show current state
        $this->table(
            ['Property', 'Value'],
            [
                ['Email Count', $account->emails()->count()],
                ['Forward Cursor', $account->forward_cursor ?? 'null'],
                ['Backfill Cursor', $account->backfill_cursor ?? 'null'],
                ['Backfill Complete', $account->backfill_complete ? 'Yes' : 'No'],
                ['Sync Status', $account->sync_status->value ?? $account->sync_status],
            ]
        );

        if (!$this->confirm('Are you sure you want to reset this account?')) {
            $this->info('Operation cancelled.');
            return self::SUCCESS;
        }

        // Delete emails
        $emailCount = $account->emails()->count();
        $this->info("Deleting {$emailCount} emails...");
        
        // Delete in chunks to avoid memory issues
        $account->emails()->chunkById(100, function ($emails) {
            foreach ($emails as $email) {
                /** @var \App\Models\Email $email */
                // Clear media (attachments) first
                $email->clearMediaCollection('attachments');
                $email->delete();
            }
        });

        // Delete sync logs
        $logCount = $account->syncLogs()->count();
        $this->info("Deleting {$logCount} sync logs...");
        $account->syncLogs()->delete();

        if ($keepAccount) {
            // Reset cursors but keep account
            $account->update([
                'forward_cursor' => null,
                'backfill_cursor' => null,
                'backfill_complete' => false,
                'sync_status' => 'pending',
                'last_synced_at' => null,
                'last_error' => null,
            ]);
            $this->info('✓ Account reset. Cursors cleared, ready for fresh sync.');
        } else {
            $account->delete();
            $this->info('✓ Account deleted completely.');
        }

        return self::SUCCESS;
    }

    protected function resetAllAccounts(): int
    {
        $accounts = EmailAccount::all();
        
        if ($accounts->isEmpty()) {
            $this->info('No email accounts found.');
            return self::SUCCESS;
        }

        $this->warn("This will reset {$accounts->count()} email account(s).");
        
        if (!$this->confirm('Are you sure you want to proceed?')) {
            $this->info('Operation cancelled.');
            return self::SUCCESS;
        }

        foreach ($accounts as $account) {
            $this->resetAccount($account);
            $this->newLine();
        }

        $this->info('All accounts have been reset.');
        return self::SUCCESS;
    }
}
