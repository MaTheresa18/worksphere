<?php

namespace Tests\Feature;

use App\Models\EmailAccount;
use App\Models\User;
use App\Services\EmailAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailProviderIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected EmailAccountService $service;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(EmailAccountService::class);
        $this->user = User::factory()->create();
    }

    /** @test */
    public function it_applies_correct_presets_for_new_providers()
    {
        $providersToTest = [
            'yahoo' => 'imap.mail.yahoo.com',
            'zoho' => 'imap.zoho.com',
            'fastmail' => 'imap.fastmail.com',
            'yandex' => 'imap.yandex.com',
            'gmx' => 'imap.gmx.com',
            'webde' => 'imap.web.de',
        ];

        foreach ($providersToTest as $provider => $expectedImapHost) {
            $account = $this->service->create([
                'email' => "test@{$provider}.com",
                'provider' => $provider,
                'name' => "My {$provider} Account",
                'auth_type' => 'password',
                'password' => 'secret',
            ], $this->user);

            $this->assertEquals($expectedImapHost, $account->imap_host, "Failed for {$provider}");
            $this->assertEquals(EmailAccount::PROVIDERS[$provider]['smtp_host'], $account->smtp_host, "SMTP Failed for {$provider}");
        }
    }

    /** @test */
    public function it_instantiates_correct_adapters()
    {
        $providersByClass = [
            'yahoo' => \App\Services\EmailAdapters\YahooAdapter::class,
            'zoho' => \App\Services\EmailAdapters\ZohoAdapter::class,
            'fastmail' => \App\Services\EmailAdapters\FastmailAdapter::class,
            'yandex' => \App\Services\EmailAdapters\YandexAdapter::class,
            'gmx' => \App\Services\EmailAdapters\GmxAdapter::class,
            'webde' => \App\Services\EmailAdapters\WebDeAdapter::class,
        ];

        foreach ($providersByClass as $provider => $expectedClass) {
            $adapter = \App\Services\EmailAdapters\AdapterFactory::forProvider($provider);
            $this->assertInstanceOf($expectedClass, $adapter, "Failed for {$provider}");
        }
    }
}
