<?php

use Spatie\MediaLibrary\MediaCollections\Models\Media;

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$lastMedia = Media::latest()->first();

if ($lastMedia) {
    echo 'Last Media ID: '.$lastMedia->id."\n";
    echo 'Collection: '.$lastMedia->collection_name."\n";
    echo 'File Name: '.$lastMedia->file_name."\n";
    echo 'Disk: '.$lastMedia->disk."\n";
    echo 'Path: '.$lastMedia->getPath()."\n";
    echo 'File Exists: '.(file_exists($lastMedia->getPath()) ? 'YES' : 'NO')."\n";
} else {
    echo "No media found.\n";
}
