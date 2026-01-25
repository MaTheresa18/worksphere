<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Smart media URL service that automatically determines
 * whether to return a direct URL or a temporary signed URL
 * based on the disk configuration.
 */
class SmartMediaUrlService
{
    /**
     * Get the appropriate URL for a media item.
     *
     * For public disks: returns direct URL
     * For private disks: returns temporary signed URL
     *
     * @param  Media  $media  The media item
     * @param  string  $conversion  Optional conversion name (e.g., 'thumb', 'optimized')
     * @param  int  $expiryMinutes  Expiry time for signed URLs (default: 60 minutes)
     */
    public function getUrl(Media $media, string $conversion = '', int $expiryMinutes = 60): ?string
    {
        try {
            $diskName = $media->disk;
            $diskConfig = config("filesystems.disks.{$diskName}");

            if (! $diskConfig) {
                Log::warning("SmartMediaUrlService: Unknown disk '{$diskName}' for media ID {$media->id}");

                return null;
            }

            // Check if this is a public disk
            if ($this->isPublicDisk($diskConfig)) {
                return $conversion
                    ? $media->getUrl($conversion)
                    : $media->getUrl();
            }

            // Private disk - use temporary signed URL
            return $conversion
                ? $media->getTemporaryUrl(now()->addMinutes($expiryMinutes), $conversion)
                : $media->getTemporaryUrl(now()->addMinutes($expiryMinutes));

        } catch (\Exception $e) {
            Log::error("SmartMediaUrlService: Failed to get URL for media ID {$media->id}", [
                'error' => $e->getMessage(),
                'disk' => $media->disk,
            ]);

            return null;
        }
    }

    /**
     * Get URLs for multiple media items.
     *
     * @param  iterable<Media>  $mediaItems
     * @return array<int, string|null>
     */
    public function getUrls(iterable $mediaItems, string $conversion = '', int $expiryMinutes = 60): array
    {
        $urls = [];
        foreach ($mediaItems as $media) {
            $urls[$media->id] = $this->getUrl($media, $conversion, $expiryMinutes);
        }

        return $urls;
    }

    /**
     * Format a media item with smart URL for API responses.
     */
    public function formatForApi(Media $media, int $expiryMinutes = 60): array
    {
        return [
            'id' => $media->id,
            'uuid' => $media->uuid,
            'name' => $media->name,
            'file_name' => $media->file_name,
            'mime_type' => $media->mime_type,
            'size' => $media->size,
            'human_readable_size' => $media->human_readable_size,
            'url' => $this->getUrl($media, '', $expiryMinutes),
            'thumb_url' => $media->hasGeneratedConversion('thumb')
                ? $this->getUrl($media, 'thumb', $expiryMinutes)
                : null,
            'is_private' => ! $this->isPublicDisk(config("filesystems.disks.{$media->disk}")),
            'created_at' => $media->created_at?->toIso8601String(),
        ];
    }

    /**
     * Determine if a disk configuration is public.
     */
    protected function isPublicDisk(?array $diskConfig): bool
    {
        if (! $diskConfig) {
            return false;
        }

        // Check explicit visibility setting
        if (isset($diskConfig['visibility']) && $diskConfig['visibility'] === 'public') {
            return true;
        }

        // Check if disk has a public URL configured
        if (! empty($diskConfig['url'])) {
            return true;
        }

        return false;
    }

    /**
     * Check if a media item is on a private disk.
     */
    public function isPrivate(Media $media): bool
    {
        $diskConfig = config("filesystems.disks.{$media->disk}");

        return ! $this->isPublicDisk($diskConfig);
    }
}
