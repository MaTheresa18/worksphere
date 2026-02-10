<?php

namespace App\Http\Controllers\Api\Chat;

use App\Events\Chat\CallEnded;
use App\Events\Chat\CallInitiated;
use App\Events\Chat\CallSignal;
use App\Http\Controllers\Controller;
use App\Models\Chat\Chat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class VideoCallController extends Controller
{
    /**
     * Verify that the current user is a participant in the chat.
     */
    private function findChatOrFail(Chat $chat): Chat
    {
        $userId = Auth::id();

        $isParticipant = $chat->participants()
            ->where('user_id', $userId)
            ->exists();

        if (! $isParticipant) {
            abort(404, 'Chat not found.');
        }

        return $chat;
    }

    /**
     * Generate short-lived TURN credentials via Cloudflare API.
     * Falls back to STUN-only config if TURN is not configured.
     */
    public function turnCredentials(Chat $chat): JsonResponse
    {
        $this->findChatOrFail($chat);

        // Default fallback: STUN-only
        $iceServers = [
            [
                'urls' => 'stun:stun.cloudflare.com:3478',
            ],
        ];

        $turnKeyId = config('services.cloudflare.turn_key_id');
        $turnApiToken = config('services.cloudflare.turn_api_token');

        if ($turnKeyId && $turnApiToken) {
            try {
                /** @var \Illuminate\Http\Client\Response $response */
                $response = Http::withToken($turnApiToken)
                    ->post("https://rtc.live.cloudflare.com/v1/turn/keys/{$turnKeyId}/credentials/generate-ice-servers", [
                        'ttl' => 86400, // 24 hours
                    ]);

                if ($response->successful()) {
                    $data = $response->json();

                    // Cloudflare returns { iceServers: [{ urls: [...], username, credential }] }
                    // Pass through directly — includes STUN + TURN in one entry
                    if (! empty($data['iceServers'])) {
                        $iceServers = $data['iceServers'];
                    }
                }
            } catch (\Exception $e) {
                // Log but don't fail — STUN-only fallback
                Log::warning('Failed to fetch TURN credentials from Cloudflare', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'ice_servers' => $iceServers,
        ]);
    }

    /**
     * Initiate a call — notifies the other participant(s).
     */
    public function initiate(Request $request, Chat $chat): JsonResponse
    {
        $chat = $this->findChatOrFail($chat);

        $request->validate([
            'call_type' => 'required|in:video,audio',
        ]);

        // Only allow DM calls for now
        if ($chat->type !== 'dm') {
            return response()->json([
                'message' => 'Group calls are not yet supported.',
            ], 422);
        }

        $user = Auth::user();
        $callId = (string) Str::ulid();

        event(new CallInitiated($chat, $user, $callId, $request->input('call_type')));

        return response()->json([
            'call_id' => $callId,
            'chat_id' => $chat->public_id,
        ]);
    }

    /**
     * Relay a WebRTC signal (offer, answer, or ICE candidate).
     */
    public function signal(Request $request, Chat $chat): JsonResponse
    {
        $chat = $this->findChatOrFail($chat);

        $request->validate([
            'call_id' => 'required|string|max:64',
            'signal_type' => 'required|in:offer,answer,ice-candidate',
            'signal_data' => 'required|array',
        ]);

        $user = Auth::user();

        event(new CallSignal(
            $chat,
            $user->public_id,
            $request->input('call_id'),
            $request->input('signal_type'),
            $request->input('signal_data'),
        ));

        return response()->json(['status' => 'ok']);
    }

    /**
     * End an active call.
     */
    public function end(Request $request, Chat $chat): JsonResponse
    {
        $chat = $this->findChatOrFail($chat);

        $request->validate([
            'call_id' => 'required|string|max:64',
            'reason' => 'sometimes|in:hangup,declined,timeout,failed',
        ]);

        $user = Auth::user();
        $reason = $request->input('reason', 'hangup');

        event(new CallEnded($chat, $user->public_id, $request->input('call_id'), $reason));

        return response()->json(['status' => 'ok']);
    }
}
