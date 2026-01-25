<?php

namespace App\Support\MediaLibrary;

use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\PathGenerator;

class PrivatePathGenerator implements PathGenerator
{
    /*
     * Get the path for the given media, relative to the root storage path.
     */
    public function getPath(Media $media): string
    {
        return $this->getBasePath($media) . '/';
    }

    /*
     * Get the path for conversions of the given media, relative to the root storage path.
     */
    public function getPathForConversions(Media $media): string
    {
        return $this->getBasePath($media) . '/conversions/';
    }

    /*
     * Get the path for responsive images of the given media, relative to the root storage path.
     */
    public function getPathForResponsiveImages(Media $media): string
    {
        return $this->getBasePath($media) . '/responsive-images/';
    }

    /*
     * Get the base path for the media.
     * Structure: email_media/{user_id}/{model_type_slug}/{media_id}
     */
    protected function getBasePath(Media $media): string
    {
        $model = $media->model;
        $userId = $model && isset($model->user_id) ? $model->user_id : 'shared';
        
        // Determine type slug (e.g. 'templates' or 'signatures')
        $type = 'consultants'; // default fallback
        if ($model instanceof \App\Models\EmailTemplate) {
            $type = 'templates';
        } elseif ($model instanceof \App\Models\EmailSignature) {
            $type = 'signatures';
        }

        return "email_media/{$userId}/{$type}/{$media->id}";
    }
}
