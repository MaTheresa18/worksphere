<?php

use App\Models\EmailAccount;
use Webklex\PHPIMAP\ClientManager;

require __DIR__.'/vendor/autoload.php';

$account = EmailAccount::first();

if (! $account) {
    echo "No account found.\n";
    exit;
}

echo "Account: {$account->email}\n";
echo "DB Forward Cursor: {$account->forward_uid_cursor}\n";

$cm = new ClientManager;
$config = [
    'host' => $account->imap_host,
    'port' => $account->imap_port,
    'encryption' => $account->imap_encryption,
    'validate_cert' => false,
    'username' => $account->username ?: $account->email,
    'password' => $account->password,
    'protocol' => 'imap',
];

if ($account->auth_type === 'oauth') {
    $config['password'] = $account->access_token;
    $config['authentication'] = 'oauth';
}

$client = $cm->make($config);
$client->connect();

$folder = $client->getFolder('INBOX');
// Just query directly

$messages = $folder->query()->since($account->forward_uid_cursor ?? 1)->limit(5)->get();

$count = $messages->count();
$maxUid = $messages->count() > 0 ? $messages->max(fn ($m) => $m->getUid()) : 0;

echo "IMAP Max UID: {$maxUid}\n";
echo "Messages since cursor: {$count}\n";

if ($count > 0) {
    echo "There are new emails to fetch!\n";
} else {
    echo "No new emails found via IMAP query.\n";
}
