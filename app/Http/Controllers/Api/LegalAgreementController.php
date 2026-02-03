<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LegalAgreementLog;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class LegalAgreementController extends Controller
{
    /**
     * Check status of legal agreements.
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $required = [];

        foreach (['tos', 'privacy'] as $type) {
            $config = config("legal.{$type}");
            if ($config && !$user->hasAcceptedLatest($type)) {
                $required[] = [
                    'type' => $type,
                    'version' => $config['version'],
                    'url' => $config['url'],
                    'published_at' => $config['published_at'],
                ];
            }
        }

        return response()->json([
            'pending_agreements' => $required,
            'is_compliant' => empty($required),
        ]);
    }

    /**
     * Record acceptance of a legal agreement.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'document_type' => ['required', 'in:tos,privacy'],
        ]);

        $type = $request->document_type;
        $config = config("legal.{$type}");

        if (!$config) {
            return response()->json(['message' => 'Invalid document type.'], 400);
        }

        // Check if already accepted
        if ($request->user()->hasAcceptedLatest($type)) {
            return response()->json(['message' => 'Already accepted latest version.']);
        }

        LegalAgreementLog::create([
            'user_id' => $request->user()->id,
            'document_type' => $type,
            'version' => $config['version'],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'accepted_at' => now(),
        ]);

        return response()->json(['message' => 'Agreement recorded successfully.']);
    }
}
