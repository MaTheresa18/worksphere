<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEmailTemplateRequest;
use App\Models\EmailTemplate;
use Spatie\MediaLibrary\MediaCollections\Models\Media; // Add import
use App\Services\MediaService; // Add import
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str; // Add import

class EmailTemplateController extends Controller
{
    public function __construct(
        protected MediaService $mediaService
    ) {}

    /**
     * Upload an image/media for the template (CID support).
     */
    public function uploadMedia(Request $request, EmailTemplate $template): JsonResponse
    {
        $this->authorize('update', $template);

        $request->validate(['file' => ['required', 'image', 'max:5120']]);

        $media = $this->mediaService->attachFromRequest(
            $template,
            'file',
            'images',
            Str::random(40).'.'.$request->file('file')->extension()
        );

        return response()->json([
            'url' => route('api.media.show', ['media' => $media->id]),
            'id' => $media->id,
            'mime_type' => $media->mime_type,
            'extension' => pathinfo($media->file_name, PATHINFO_EXTENSION),
        ]);
    }

    /**
     * List media attached to the template.
     */
    public function indexMedia(Request $request, EmailTemplate $template): JsonResponse
    {
        $this->authorize('update', $template);

        $media = $template->getMedia('images')->map(function ($item) {
            return [
                'id' => $item->id,
                'name' => $item->name,
                'file_name' => $item->file_name,
                'mime_type' => $item->mime_type,
                'size' => $item->size,
                'url' => route('api.media.show', ['media' => $item->id]),
                'thumbnail_url' => route('api.media.show', ['media' => $item->id]),
                'created_at' => $item->created_at,
                'extension' => pathinfo($item->file_name, PATHINFO_EXTENSION),
            ];
        });

        return response()->json(['data' => $media]);
    }

    /**
     * Delete media from the template.
     */
    public function deleteMedia(EmailTemplate $template, Media $media): JsonResponse
    {
        $this->authorize('update', $template);

        // Ensure media belongs to this template
        if ($media->model_id !== $template->id || $media->model_type !== EmailTemplate::class) {
            abort(403, 'Media does not belong to this template.');
        }

        $media->delete();

        return response()->json(['message' => 'Media deleted']);
    }

    public function index(Request $request): JsonResponse
    {
        $templates = EmailTemplate::forUser($request->user()->id)
            ->get();

        return response()->json(['data' => $templates]);
    }

    /**
     * Create template.
     */
    public function store(StoreEmailTemplateRequest $request): JsonResponse
    {
        $template = $request->user()->emailTemplates()->create($request->validated());

        return response()->json(['data' => $template], 201);
    }

    /**
     * Update template.
     */
    public function update(StoreEmailTemplateRequest $request, EmailTemplate $template): JsonResponse
    {
        $this->authorize('update', $template);

        $template->update($request->validated());

        return response()->json(['data' => $template]);
    }

    /**
     * Delete template.
     */
    public function destroy(EmailTemplate $template): JsonResponse
    {
        $this->authorize('delete', $template);

        $template->delete();

        return response()->json(['message' => 'Template deleted']);
    }
}
