<?php

use App\Enums\EmailFolderType;
use App\Models\Email;
use App\Models\EmailAccount;
use App\Services\EmailAdapters\AdapterFactory;

echo "--- DEEP EMAIL AUDIT ---\n";

// 1. Get Account
$account = EmailAccount::first();
echo "Account: {$account->email} (ID: {$account->id})\n";
echo "Sync Status: {$account->sync_status->value}\n";
echo 'Forward Cursor: '.($account->forward_uid_cursor ?? 'NULL')."\n";
echo 'Backfill Cursor: '.($account->backfill_uid_cursor ?? 'NULL')."\n";
echo 'Backfill Complete: '.($account->backfill_complete ? 'YES' : 'NO')."\n";
echo "------------------------------\n";

// 2. Connect to IMAP
echo "Connecting to IMAP...\n";
try {
    $adapter = AdapterFactory::make($account);
    $client = $adapter->createClient($account);
    $client->connect();
    echo "Connected successfully.\n";
} catch (\Exception $e) {
    echo 'CRITICAL FAULT: Could not connect to IMAP. '.$e->getMessage()."\n";
    exit(1);
}

// 3. Inspect INBOX
$folderType = EmailFolderType::Inbox;
$folderName = $adapter->getFolderName($folderType->value);
$folder = $client->getFolder($folderName);

if (! $folder) {
    echo "CRITICAL FAULT: INBOX not found on server.\n";
    $client->disconnect();
    exit(1);
}

$examine = $folder->examine();
$serverTotal = $examine['exists'] ?? 0;
$uidValidity = $examine['uidvalidity'] ?? 0;
$uidNext = $examine['uidnext'] ?? 0;

echo "\n--- INBOX AUDIT ---\n";
echo "Server UIDVALIDITY: $uidValidity\n";
echo "Server UIDNEXT:     $uidNext\n";
echo "Server Total Msgs:  $serverTotal\n";

// Get Server Min/Max UIDs (Sampling)
$maxUid = 0;
$minUid = 0;

if ($serverTotal > 0) {
    // Top
    $topUids = $adapter->fetchLatestUids($folder, 1);
    $maxUid = $topUids[0] ?? 0;

    // Bottom (Tricky without fetching all, but we can guess or fetch old range)
    // We'll trust 'exists' for count.
}
echo "Server Max UID:     $maxUid\n";

// 4. DB Stats for INBOX
$dbCount = Email::where('email_account_id', $account->id)
    ->where('folder', $folderType->value)
    ->count();

$dbMaxUid = Email::where('email_account_id', $account->id)
    ->where('folder', $folderType->value)
    ->max('imap_uid');

$dbMinUid = Email::where('email_account_id', $account->id)
    ->where('folder', $folderType->value)
    ->min('imap_uid');

echo "\n--- COMPARISON ---\n";
echo str_pad('Metric', 20).' | '.str_pad('Server', 10).' | '.str_pad('Database', 10).' | '.'Diff'."\n";
echo str_repeat('-', 60)."\n";
echo str_pad('Total Count', 20).' | '.str_pad($serverTotal, 10).' | '.str_pad($dbCount, 10).' | '.($dbCount - $serverTotal)."\n";
echo str_pad('Max UID', 20).' | '.str_pad($maxUid, 10).' | '.str_pad($dbMaxUid, 10).' | '.($dbMaxUid - $maxUid)."\n";
// Min UID is harder to compare easily on server without fetch, but count is key.

echo "\n--- VERDICT ---\n";
if ($dbCount < $serverTotal) {
    echo "MISSING EMAILS! Database has fewer emails than server.\n";
    echo 'Diff: '.($serverTotal - $dbCount)." missing.\n";

    // Suggest Backfill Check
    if ($account->backfill_uid_cursor > 1) {
        echo "Root Cause: Backfill is still in progress (Cursor: {$account->backfill_uid_cursor}). This is EXPECTED.\n";
    } elseif ($account->backfill_complete) {
        echo "Root Cause: Backfill marked complete but emails are missing. Possible skip/error or sparse UIDs.\n";
    }
} elseif ($dbCount > $serverTotal) {
    echo "EXTRA EMAILS! Database has more emails than server (Deletions not synced?).\n";
} else {
    echo "SYNC PERFECT. Counts match exactly.\n";
}

$client->disconnect();
echo "\nAudit Complete.\n";
