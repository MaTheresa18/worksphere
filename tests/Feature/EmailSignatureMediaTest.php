<?php

namespace Tests\Feature;

use App\Models\EmailSignature;
use App\Models\EmailTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmailSignatureMediaTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_upload_media_to_signature()
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $signature = EmailSignature::factory()->create(['user_id' => $user->id]);

        $file = UploadedFile::fake()->image('signature.jpg');

        $response = $this->actingAs($user)
            ->postJson("/api/emails/signatures/{$signature->id}/media", [
                'file' => $file,
            ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['url', 'id', 'mime_type']);
    }

    public function test_it_can_upload_media_to_template()
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $template = EmailTemplate::factory()->create(['user_id' => $user->id]);

        $file = UploadedFile::fake()->image('template.jpg');

        $response = $this->actingAs($user)
            ->postJson("/api/emails/templates/{$template->id}/media", [
                'file' => $file,
            ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['url', 'id', 'mime_type']);
    }

    public function test_it_can_update_signature()
    {
        $user = User::factory()->create();
        $signature = EmailSignature::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)
            ->putJson("/api/emails/signatures/{$signature->id}", [
                'name' => 'Updated Signature',
                'content' => 'Updated content',
                'is_default' => true,
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('email_signatures', [
            'id' => $signature->id,
            'name' => 'Updated Signature',
            'is_default' => true,
        ]);
    }
}
