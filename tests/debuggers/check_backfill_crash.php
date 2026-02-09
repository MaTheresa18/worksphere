<?php

use App\Enums\EmailFolderType;
use App\Models\EmailAccount;
use App\Services\EmailAdapters\AdapterFactory;

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$account = EmailAccount::first();

if (! $account) {
    echo "No account.\n";
    exit;
}

echo "Testing Backfill Logic (Part 2) for: {$account->email}\n";

$adapter = AdapterFactory::make($account);
$client = $adapter->createClient($account);
$client->connect();

$folderType = $account->provider === 'gmail' ? EmailFolderType::Archive : EmailFolderType::Inbox;
$folder = $adapter->getFolderWithFallback($client, $folderType->value);

// Targeted UIDs based on cursor 11768
$uidsToTest = [11767, 11766, 11765, 11764, 11763];
echo 'Testing UIDs: '.implode(', ', $uidsToTest)."\n";

foreach ($uidsToTest as $uid) {
    echo "Fetching UID $uid... ";
    try {
        $msg = $folder->query()->getMessageByUid($uid);
        if ($msg) {
            echo 'Found. Parsing... ';
            $data = $adapter->parseMessage($msg);
            echo 'Success! Subject: '.substr($data['subject'], 0, 30)."\n";
        } else {
            echo "Not found.\n";
        }
    } catch (\Throwable $e) {
        echo 'EXCEPTION: '.$e->getMessage()."\n";
    }
}
