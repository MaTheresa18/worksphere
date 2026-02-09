<?php

use App\Enums\EmailSyncStatus;
use App\Models\Email;
use App\Models\EmailAccount;
use App\Models\EmailSyncLog;
use App\Services\EmailSyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "Resetting Email Sync State...\n";

Schema::disableForeignKeyConstraints();

try {
    // 1. Truncate tables (outside transaction as DDL commits implicit)
    echo "Truncating emails table...\n";
    Email::truncate();

    echo "Truncating email_sync_logs table...\n";
    EmailSyncLog::truncate();

    echo "Truncating media table...\n";
    DB::table('media')->truncate();

    DB::transaction(function () {
        // 2. Reset Accounts
        echo "Resetting accounts...\n";
        $accounts = EmailAccount::all();

        $syncService = app(EmailSyncService::class);

        foreach ($accounts as $account) {
            // Reset to clean state
            $account->update([
                'sync_status' => EmailSyncStatus::Pending,
                'sync_cursor' => null,
                'forward_uid_cursor' => 0,
                'backfill_uid_cursor' => 0,
                'backfill_complete' => false,
                'last_forward_sync_at' => null,
                'last_backfill_at' => null,
                'sync_error' => null,
                'sync_started_at' => null,
                'initial_sync_completed_at' => null,
            ]);

            echo "Reset account: {$account->email}\n";

            // Restart Seed
            echo "Restarting Seed for {$account->email}...\n";
            $syncService->startSeed($account);
        }
    });

    echo "Done.\n";
} catch (\Throwable $e) {
    echo 'Error: '.$e->getMessage()."\n";
} finally {
    Schema::enableForeignKeyConstraints();
}
