<?php

namespace Tests\Feature;

use App\Models\Email;
use App\Models\EmailAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailSortingFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected EmailAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->account = EmailAccount::factory()->create(['user_id' => $this->user->id]);
    }

    public function test_can_sort_emails_by_date_desc()
    {
        // Newest First
        $email1 = Email::factory()->create([
            'email_account_id' => $this->account->id,
            'user_id' => $this->user->id,
            'received_at' => now()->subDays(2),
            'subject' => 'Oldest Email',
        ]);
        $email2 = Email::factory()->create([
            'email_account_id' => $this->account->id,
            'user_id' => $this->user->id,
            'received_at' => now(),
            'subject' => 'Newest Email',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/emails?sort_by=date&order=desc');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertEquals($email2->id, $data[0]['id']);
        $this->assertEquals($email1->id, $data[1]['id']);
    }

    public function test_can_sort_emails_by_date_asc()
    {
        // Oldest First
        $email1 = Email::factory()->create([
            'email_account_id' => $this->account->id,
            'user_id' => $this->user->id,
            'received_at' => now()->subDays(2),
            'subject' => 'Oldest Email',
        ]);
        $email2 = Email::factory()->create([
            'email_account_id' => $this->account->id,
            'user_id' => $this->user->id,
            'received_at' => now(),
            'subject' => 'Newest Email',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/emails?sort_by=date&order=asc');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertEquals($email1->id, $data[0]['id']);
        $this->assertEquals($email2->id, $data[1]['id']);
    }

    public function test_can_sort_emails_by_sender()
    {
        // Alphabetical A-Z
        $email1 = Email::factory()->create([
            'email_account_id' => $this->account->id,
            'user_id' => $this->user->id,
            'from_name' => 'Alice',
            'received_at' => now(),
        ]);
        $email2 = Email::factory()->create([
            'email_account_id' => $this->account->id,
            'user_id' => $this->user->id,
            'from_name' => 'Bob',
            'received_at' => now(),
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/emails?sort_by=sender&order=asc');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertEquals($email1->id, $data[0]['id']); // Alice first
        $this->assertEquals($email2->id, $data[1]['id']);

        // Reverse Z-A
        $response = $this->actingAs($this->user)
            ->getJson('/api/emails?sort_by=sender&order=desc');

        $data = $response->json('data');
        $this->assertEquals($email2->id, $data[0]['id']); // Bob first
    }

    public function test_can_sort_emails_by_subject()
    {
        // Alphabetical A-Z
        $email1 = Email::factory()->create([
            'email_account_id' => $this->account->id,
            'user_id' => $this->user->id,
            'subject' => 'Apple',
            'received_at' => now(),
        ]);
        $email2 = Email::factory()->create([
            'email_account_id' => $this->account->id,
            'user_id' => $this->user->id,
            'subject' => 'Banana',
            'received_at' => now(),
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/emails?sort_by=subject&order=asc');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertEquals($email1->id, $data[0]['id']);
        $this->assertEquals($email2->id, $data[1]['id']);
    }

    public function test_default_sort_is_date_desc()
    {
        $email1 = Email::factory()->create([
            'email_account_id' => $this->account->id,
            'user_id' => $this->user->id,
            'received_at' => now()->subDays(1),
        ]);
        $email2 = Email::factory()->create([
            'email_account_id' => $this->account->id,
            'user_id' => $this->user->id,
            'received_at' => now(),
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/emails'); // No params

        $data = $response->json('data');
        $this->assertEquals($email2->id, $data[0]['id']); // Newest first
    }
}
