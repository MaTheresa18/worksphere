<?php
use Illuminate\Support\Facades\Log;

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "Testing Logging...\n";
Log::info('TEST LOG ENTRY ' . microtime(true));

$channels = config('logging.channels');
echo "Default Channel: " . config('logging.default') . "\n";
print_r($channels[config('logging.default')] ?? 'Unknown');

echo "\nChecking 'channel-auth' channel:\n";
print_r($channels['channel-auth'] ?? 'Not set');
