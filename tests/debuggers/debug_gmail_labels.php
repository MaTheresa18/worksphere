<?php

use App\Models\EmailAccount;
use App\Services\EmailAdapters\AdapterFactory;
use Webklex\PHPIMAP\Attribute;

echo "--- GMAIL LABEL DEBUG ---\n";

// 1. Get Gmail Account
$account = EmailAccount::where('email', 'like', '%gmail.com%')->first();
if (!$account) {
    echo "No Gmail account found.\n";
    exit;
}
echo "Account: {$account->email}\n";

// 2. Connect
$adapter = AdapterFactory::make($account);
$client = $adapter->createClient($account);
$client->connect();
echo "Connected.\n";

// 3. Get All Mail
$folderName = '[Gmail]/All Mail';
echo "Getting folder: $folderName\n";

try {
    $folder = $client->getFolder($folderName);
} catch (\Exception $e) {
    echo "Folder not found. Trying '[Gmail]/All Mail'...\n";
    // List folders to debug
    $folders = $client->getFolders();
    foreach ($folders as $f) {
        echo "- " . $f->path . "\n";
    }
    exit;
}




// 4. Fetch Sample via UIDs (Production Pattern)
echo "Fetching sample UIDs...\n";
// Re-implement fetchLatestUids logic simply for debug
$examine = $folder->examine();
$uidNext = $examine['uidnext'];
$start = max(1, $uidNext - 5);
$range = "$start:*";

echo "Fetching overview for range: $range\n";
$overview = $folder->overview($range);
$uids = [];
foreach ($overview as $msg) {
    if (isset($msg->uid)) $uids[] = $msg->uid;
}
rsort($uids);
$topUids = array_slice($uids, 0, 3);
echo "Use UIDs: " . implode(', ', $topUids) . "\n";

foreach ($topUids as $uid) {
    echo "\nFetching UID: $uid\n";
    $msg = $folder->query()->getMessageByUid($uid);
    
    if (!$msg) continue;

    echo "Subject: " . $msg->getSubject() . "\n";
    
    // Check Attributes for Labels
    $attributes = $msg->getAttributes();
    echo "Attribute Keys: " . implode(', ', array_keys($attributes)) . "\n";
    
    if (isset($attributes['x-gm-labels'])) {
        echo "X-GM-LABELS: " . print_r($attributes['x-gm-labels'], true) . "\n";
    } elseif (isset($attributes['X-GM-LABELS'])) {
         echo "X-GM-LABELS (Caps): " . print_r($attributes['X-GM-LABELS'], true) . "\n";
    } else {
        echo "X-GM-LABELS NOT FOUND. Dumping all attributes:\n";
        print_r($attributes);
    }
}

$client->disconnect();
