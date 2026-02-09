<?php

use App\Models\Email;
use App\Services\EmailSanitizationService;
use Illuminate\Support\Facades\File;

// The Message-ID from the user
$messageId = '<071d9e2a074c3ad60542a8f45409611d@link-technologies.info>';

$email = Email::where('message_id', $messageId)->first();

if (! $email) {
    echo "Email not found for Message-ID: $messageId\n";
    // Fallback: try to find by similarity or just the latest one if that fails?
    // Let's list recent emails to see if we can identify it.
    $latest = Email::latest('received_at')->take(5)->get();
    echo "Recent emails:\n";
    foreach ($latest as $e) {
        echo "ID: {$e->id}, Subject: {$e->subject}, MsgID: {$e->message_id}\n";
    }
    exit(1);
}

echo 'Found Email ID: '.$email->id."\n";
echo 'Subject: '.$email->subject."\n";

$raw = $email->body_raw;
$currentSanitized = $email->body_html;

// Sanitize again with potentially updated config
$sanitizer = app(EmailSanitizationService::class);
$newSanitized = $sanitizer->sanitize($raw ?? '', 'imap');

File::put(base_path('tests/debuggers/email_raw.html'), $raw);
File::put(base_path('tests/debuggers/email_sanitized_old.html'), $currentSanitized);
File::put(base_path('tests/debuggers/email_sanitized_new.html'), $newSanitized);

echo "Saved HTML files to tests/debuggers/\n";

echo 'Raw Length: '.strlen($raw)."\n";
echo 'Sanitized Length: '.strlen($newSanitized)."\n";
