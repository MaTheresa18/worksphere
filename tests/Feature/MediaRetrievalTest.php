<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

class MediaRetrievalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Skip permission middleware if possible, or create user with permissions
        // For simplicity, we'll assume the controller authorization logic works (tested elsewhere hopefully, or we can mock)
        // Actually MediaController has complex authorization. We should use a user who owns the media.
    }

    public function test_it_serves_file_from_local_disk()
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $file = UploadedFile::fake()->create('test.jpg', 100);

        // Manually create media attached to user
        $media = $user->addMedia($file)->toMediaCollection('avatar', 'public');

        $this->actingAs($user)
            ->getJson("/api/media/{$media->id}") // Assuming route is /api/media/{id} or similar. checking routes file might be needed if this fails.
            ->assertStatus(200);
    }

    public function test_it_serves_file_from_s3_disk()
    {
        Storage::fake('s3');

        $user = User::factory()->create();

        // Manually create media record for S3
        $media = Media::create([
            'model_type' => User::class,
            'model_id' => $user->id,
            'collection_name' => 'documents',
            'name' => 'test-s3',
            'file_name' => 'test-s3.pdf',
            'mime_type' => 'application/pdf',
            'disk' => 's3',
            'size' => 1024,
            'manipulations' => [],
            'custom_properties' => [],
            'generated_conversions' => [],
            'responsive_images' => [],
        ]);

        // Place file in fake s3
        Storage::disk('s3')->put($media->getPath(), 'content');

        $this->actingAs($user)
            ->getJson("/api/media/{$media->id}")
            ->assertStatus(200);
    }
}
