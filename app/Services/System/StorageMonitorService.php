<?php

namespace App\Services\System;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Throwable;

class StorageMonitorService
{
    /**
     * Get combined storage statistics.
     */
    public function getStorageStats(): array
    {
        return [
            'local' => $this->getLocalUsage(),
            's3' => $this->getS3Usage(),
        ];
    }

    /**
     * Calculate local public storage usage.
     */
    public function getLocalUsage(): array
    {
        try {
            $path = storage_path('app/public');
            if (! is_dir($path)) {
                return $this->formatStats(0, 0);
            }

            $size = 0;
            $count = 0;
            $directory = new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS);
            $iterator = new \RecursiveIteratorIterator($directory, \RecursiveIteratorIterator::LEAVES_ONLY);

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $size += $file->getSize();
                    $count++;
                }
            }

            return $this->formatStats($size, $count, $path);
        } catch (Throwable $e) {
            return [
                'size_bytes' => 0,
                'size_formatted' => 'Error',
                'file_count' => 0,
                'path' => storage_path('app/public'),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Calculate S3 bucket usage (if configured).
     */
    /**
     * Calculate S3 bucket usage (if configured).
     * Now supports multiple buckets attached to different disks (s3, private, public).
     */
    public function getS3Usage(): ?array
    {
        $buckets = [];
        // Check these disks for S3 driver configuration
        $disksToCheck = ['s3', 'private', 'public'];

        foreach ($disksToCheck as $diskName) {
            $config = Config::get("filesystems.disks.{$diskName}");
            // Only proceed if disk exists, uses 's3' driver, and has a bucket
            if ($config && ($config['driver'] ?? '') === 's3' && ! empty($config['bucket'])) {

                $bucketName = $config['bucket'];

                // Avoid checking the same bucket multiple times if mapped to multiple disks
                // Key by bucket name to deduplicate
                if (isset($buckets[$bucketName])) {
                    continue;
                }

                try {
                    // Optimized listing that fetches metadata including size
                    $contents = Storage::disk($diskName)->listContents('', true);

                    $size = 0;
                    $count = 0;

                    foreach ($contents as $item) {
                        if ($item->isFile()) {
                            $size += $item->fileSize() ?? 0;
                            $count++;
                        }
                    }

                    $buckets[$bucketName] = $this->formatStats($size, $count, $bucketName);
                    // Add disk name for context
                    $buckets[$bucketName]['disk'] = $diskName;

                } catch (Throwable $e) {
                    $buckets[$bucketName] = [
                        'size_bytes' => 0,
                        'size_formatted' => 'Error',
                        'file_count' => 0,
                        'path' => $bucketName,
                        'disk' => $diskName,
                        'error' => $e->getMessage(),
                    ];
                }
            }
        }

        if (empty($buckets)) {
            return null;
        }

        return array_values($buckets);
    }

    protected function formatStats(int $bytes, int $count, string $path = ''): array
    {
        return [
            'size_bytes' => $bytes,
            'size_formatted' => $this->formatBytes($bytes),
            'file_count' => $count,
            'path' => $path,
        ];
    }

    protected function formatBytes(int $bytes, int $precision = 2): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= (1 << (10 * $pow));

        return round($bytes, $precision).' '.$units[$pow];
    }
}
