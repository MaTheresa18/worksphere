<?php

use App\Models\EmailAccount;
use App\Enums\EmailFolderType;
use App\Services\EmailAdapters\AdapterFactory;
use Illuminate\Support\Facades\Crypt;

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$account = EmailAccount::first();

if (!$account) {
    echo "No account found.\n";
    exit;
}

echo "Account: {$account->email} (Provider: {$account->provider})\n";
echo "Sync Status: {$account->sync_status->value}\n";
echo "Forward Cursor: " . ($account->forward_uid_cursor ?? 'NULL') . "\n";
echo "Backfill Cursor: " . ($account->backfill_uid_cursor ?? 'NULL') . "\n";
echo "Backfill Complete: " . ($account->backfill_complete ? 'YES' : 'NO') . "\n";

try {
    $adapter = AdapterFactory::make($account);
    $client = $adapter->createClient($account);
    $client->connect();
    
    // Determine folder
    $folderType = $account->provider === 'gmail' ? EmailFolderType::Archive : EmailFolderType::Inbox;
    $folderName = $adapter->getFolderName($folderType->value);
    
    echo "Checking Folder: {$folderName}\n";
    
    $folder = $adapter->getFolderWithFallback($client, $folderType->value);
    
    if (!$folder) {
        echo "Folder not found!\n";
        exit;
    }
    
    $examine = $folder->examine();
    echo "Total Messages on Server: " . ($examine['exists'] ?? 'unknown') . "\n";
    echo "UID Validity: " . ($examine['uidvalidity'] ?? 'unknown') . "\n";
    
    // Check highest UIDs
    echo "fetching latest UIDs...\n";
    $latestUids = $adapter->fetchLatestUids($folder, 25);
    echo "Top 25 UIDs on server: " . implode(', ', $latestUids) . "\n";
    
    // Check UIDs below backfill cursor
    if ($account->backfill_uid_cursor) {
        $cursor = $account->backfill_uid_cursor;
        echo "Checking for UIDs < {$cursor}...\n";
        
        $endUid = $cursor - 1;
        if ($endUid > 0) {
             // To avoid memory issues, just fetch a small range below cursor
             // E.g. (cursor - 100) to cursor - 1
             $startUid = max(1, $cursor - 100);
             $range = "$startUid:$endUid";
             
             echo "Querying range: $range\n";
             $overview = $folder->overview($range);
             
             $uidsBelow = [];
             foreach ($overview as $item) {
                 $uid = $adapter->extractUidFromOverview($item);
                 if ($uid && $uid < $cursor) {
                     $uidsBelow[] = $uid;
                 }
             }
             
             echo "Found " . count($uidsBelow) . " UIDs in range $range.\n";
             if (count($uidsBelow) > 0) {
                 echo "Sample UIDs: " . implode(', ', array_slice($uidsBelow, 0, 10)) . "\n";
             } else {
                 echo "No UIDs found in range $range.\n";
                 // If none found in top 100, might be a gap or emptiness.
                 // Try from 1 to 20?
                 echo "Checking bottom range 1:20...\n";
                 $overviewBottom = $folder->overview("1:20");
                 $bottomUids = [];
                  foreach ($overviewBottom as $item) {
                      $uid = $adapter->extractUidFromOverview($item);
                      if ($uid) $bottomUids[] = $uid;
                  }
                  echo "Found " . count($bottomUids) . " UIDs in bottom range.\n";
                  echo "Bottom UIDs: " . implode(', ', $bottomUids) . "\n";
             }
        }
    }

} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
