<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEmailSignatureRequest;
use App\Models\EmailSignature;
use App\Services\MediaService; // Add import
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str; // Add import

class EmailSignatureController extends Controller
{
    public function __construct(
        protected MediaService $mediaService
    ) {}

    /**
     * Upload an image/media for the signature (CID support).
     */
    public function uploadMedia(Request $request, EmailSignature $emailSignature): JsonResponse
    {
        $this->authorize('update', $emailSignature);

        $request->validate(['file' => ['required', 'image', 'max:5120']]); // 5MB limit

        // Attach to the signature model
        // We use a specific collection 'images' or 'attachments'
        $media = $this->mediaService->attachFromRequest(
            $emailSignature,
            'file',
            'images',
            Str::random(40) . '.' . $request->file('file')->extension()
        );

        return response()->json([
            'url' => $media->getUrl(), // This URL is used in the editor
            'id' => $media->id,
            'mime_type' => $media->mime_type,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $signatures = EmailSignature::forUser($request->user()->id)
            ->get();

        return response()->json(['data' => $signatures]);
    }

    /**
     * Create signature.
     */
    public function store(StoreEmailSignatureRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $isDefault = $validated['is_default'] ?? false;
        unset($validated['is_default']);

        $signature = $request->user()->emailSignatures()->create($validated);

        if ($isDefault) {
            $signature->setAsDefault();
        }

        return response()->json(['data' => $signature], 201);
    }

    /**
     * Update signature.
     */
    public function update(StoreEmailSignatureRequest $request, EmailSignature $emailSignature): JsonResponse
    {
        $this->authorize('update', $emailSignature);

        $validated = $request->validated();
        $isDefault = $validated['is_default'] ?? false;
        unset($validated['is_default']);

        $emailSignature->update($validated);

        if ($isDefault) {
            $emailSignature->setAsDefault();
        }

        return response()->json(['data' => $emailSignature]);
    }

    /**
     * Delete signature.
     */
    public function destroy(EmailSignature $emailSignature): JsonResponse
    {
        $this->authorize('delete', $emailSignature);

        $emailSignature->delete();

        return response()->json(['message' => 'Signature deleted']);
    }
}
