<?php

namespace Tests\Unit;

use App\Services\SmartMediaUrlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

class SmartMediaUrlServiceTest extends TestCase
{
    use RefreshDatabase;

    protected SmartMediaUrlService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SmartMediaUrlService;
    }

    public function test_is_private_returns_true_for_private_disk(): void
    {
        $media = Mockery::mock(Media::class);
        $media->shouldReceive('getAttribute')->with('disk')->andReturn('private');

        // Set up config for private disk
        config(['filesystems.disks.private' => [
            'driver' => 's3',
            'visibility' => 'private',
        ]]);

        $this->assertTrue($this->service->isPrivate($media));
    }

    public function test_is_private_returns_false_for_public_disk(): void
    {
        $media = Mockery::mock(Media::class);
        $media->shouldReceive('getAttribute')->with('disk')->andReturn('public');

        // Set up config for public disk
        config(['filesystems.disks.public' => [
            'driver' => 's3',
            'visibility' => 'public',
            'url' => 'https://example.com',
        ]]);

        $this->assertFalse($this->service->isPrivate($media));
    }

    public function test_format_for_api_returns_expected_structure(): void
    {
        $media = Mockery::mock(Media::class);
        $media->shouldReceive('getAttribute')->with('disk')->andReturn('public');
        $media->shouldReceive('getAttribute')->with('id')->andReturn(1);
        $media->shouldReceive('getAttribute')->with('uuid')->andReturn('test-uuid');
        $media->shouldReceive('getAttribute')->with('name')->andReturn('test-file');
        $media->shouldReceive('getAttribute')->with('file_name')->andReturn('test-file.pdf');
        $media->shouldReceive('getAttribute')->with('mime_type')->andReturn('application/pdf');
        $media->shouldReceive('getAttribute')->with('size')->andReturn(1024);
        $media->shouldReceive('getAttribute')->with('human_readable_size')->andReturn('1 KB');
        $media->shouldReceive('getAttribute')->with('created_at')->andReturn(now());
        $media->shouldReceive('getUrl')->andReturn('https://example.com/test-file.pdf');
        $media->shouldReceive('hasGeneratedConversion')->with('thumb')->andReturn(false);

        config(['filesystems.disks.public' => [
            'driver' => 's3',
            'visibility' => 'public',
            'url' => 'https://example.com',
        ]]);

        $result = $this->service->formatForApi($media);

        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('url', $result);
        $this->assertArrayHasKey('is_private', $result);
        $this->assertFalse($result['is_private']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
