<?php

use App\Models\Email;
use App\Models\EmailAccount;

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "Checking Email Data...\n";
$count = Email::count();
echo "Total Emails: $count\n";

if ($count > 0) {
    echo "First 10 emails:\n";
    $emails = Email::select('id', 'subject', 'folder', 'imap_uid', 'created_at')
        ->orderBy('imap_uid', 'desc')
        ->limit(10)
        ->get();
        
    foreach ($emails as $email) {
        echo "[{$email->id}] UID:{$email->imap_uid} Folder:{$email->folder} Subject: " . substr($email->subject, 0, 30) . "\n";
    }
} else {
    echo "No emails found.\n";
}

echo "\nChecking Account State:\n";
$account = EmailAccount::first();
echo "Account: {$account->email}\n";
echo "Sync Status: {$account->sync_status->value}\n";
echo "Backfill Complete: " . ($account->backfill_complete ? 'YES' : 'NO') . "\n";
echo "Backfill Cursor: {$account->backfill_uid_cursor}\n";
echo "Forward Cursor: {$account->forward_uid_cursor}\n";
