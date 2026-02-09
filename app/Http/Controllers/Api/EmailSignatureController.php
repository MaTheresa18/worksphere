<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEmailSignatureRequest;
use App\Models\EmailSignature;
use App\Services\MediaService; // Add import
use Illuminate\Http\JsonResponse; // Add import
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media; // Add import

class EmailSignatureController extends Controller
{
    public function __construct(
        protected MediaService $mediaService
    ) {}

    /**
     * Upload an image/media for the signature (CID support).
     */
    public function uploadMedia(Request $request, EmailSignature $signature): JsonResponse
    {
        $this->authorize('update', $signature);

        $request->validate(['file' => ['required', 'image', 'max:5120']]); // 5MB limit

        // Attach to the signature model
        // We use a specific collection 'images' or 'attachments'
        $media = $this->mediaService->attachFromRequest(
            $signature,
            'file',
            'images',
            Str::random(40).'.'.$request->file('file')->extension()
        );

        return response()->json([
            'url' => route('api.media.show', ['media' => $media->id]), // Secure URL
            'id' => $media->id,
            'mime_type' => $media->mime_type,
            'extension' => pathinfo($media->file_name, PATHINFO_EXTENSION),
        ]);
    }

    /**
     * List media attached to the signature.
     */
    public function indexMedia(Request $request, EmailSignature $signature): JsonResponse
    {
        $this->authorize('update', $signature);

        $media = $signature->getMedia('images')->map(function ($item) {
            return [
                'id' => $item->id,
                'name' => $item->name,
                'file_name' => $item->file_name,
                'mime_type' => $item->mime_type,
                'size' => $item->size,
                'url' => route('api.media.show', ['media' => $item->id]),
                'thumbnail_url' => route('api.media.show', ['media' => $item->id]), // Same secure URL for thumb
                'created_at' => $item->created_at,
                'extension' => pathinfo($item->file_name, PATHINFO_EXTENSION),
            ];
        });

        return response()->json(['data' => $media]);
    }

    /**
     * Delete media from the signature.
     */
    public function deleteMedia(EmailSignature $signature, Media $media): JsonResponse
    {
        $this->authorize('update', $signature);

        // Ensure media belongs to this signature
        if ($media->model_id !== $signature->id || $media->model_type !== EmailSignature::class) {
            abort(403, 'Media does not belong to this signature.');
        }

        $media->delete();

        return response()->json(['message' => 'Media deleted']);
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
    public function update(StoreEmailSignatureRequest $request, EmailSignature $signature): JsonResponse
    {
        $this->authorize('update', $signature);

        $validated = $request->validated();
        $isDefault = $validated['is_default'] ?? false;
        unset($validated['is_default']);

        $signature->update($validated);

        if ($isDefault) {
            $signature->setAsDefault();
        }

        return response()->json(['data' => $signature]);
    }

    /**
     * Delete signature.
     */
    public function destroy(EmailSignature $signature): JsonResponse
    {
        $this->authorize('delete', $signature);

        $signature->delete();

        return response()->json(['message' => 'Signature deleted']);
    }
}
