<?php

namespace App\Support\MediaLibrary;

use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\PathGenerator;

class EmailPathGenerator implements PathGenerator
{
    public function getPath(Media $media): string
    {
        return $this->getBasePath($media).'/';
    }

    public function getPathForConversions(Media $media): string
    {
        return $this->getBasePath($media).'/conversions/';
    }

    public function getPathForResponsiveImages(Media $media): string
    {
        return $this->getBasePath($media).'/responsive-images/';
    }

    protected function getBasePath(Media $media): string
    {
        $model = $media->model;
        $userId = $model && isset($model->user_id) ? $model->user_id : 'shared';
        $emailId = $model ? $model->id : 'unknown';

        // Structure: email_media/{user_id}/{email_id}/{media_id}
        return "email_media/{$userId}/{$emailId}/{$media->id}";
    }
}
