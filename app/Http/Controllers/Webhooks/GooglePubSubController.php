<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\EmailAccount;
use App\Services\EmailSyncService;
use App\Services\GmailApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GooglePubSubController extends Controller
{
    public function handle(Request $request, EmailSyncService $syncService)
    {
        $data = $request->json('message.data');
        if (!$data) {
            return response()->json(['error' => 'Invalid message'], 400);
        }

        $decoded = json_decode(base64_decode($data), true);
        $emailAddress = $decoded['emailAddress'] ?? null;
        $historyId = $decoded['historyId'] ?? null;

        if (!$emailAddress) {
            return response()->json(['error' => 'No email address in message'], 400);
        }

        Log::info('[Pub/Sub] Notification received', [
            'email' => $emailAddress,
            'historyId' => $historyId,
        ]);

        $account = EmailAccount::where('email', $emailAddress)->where('provider', 'gmail')->first();

        if ($account) {
            // Trigger an incremental sync for this specific account
            $syncService->fetchNewEmails($account);
        }

        return response()->json(['success' => true]);
    }
}
