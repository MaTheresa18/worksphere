<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEmailTemplateRequest;
use App\Models\EmailTemplate;
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
    public function uploadMedia(Request $request, EmailTemplate $emailTemplate): JsonResponse
    {
        $this->authorize('update', $emailTemplate);

        $request->validate(['file' => ['required', 'image', 'max:5120']]);

        $media = $this->mediaService->attachFromRequest(
            $emailTemplate,
            'file',
            'images',
            Str::random(40) . '.' . $request->file('file')->extension()
        );

        return response()->json([
            'url' => $media->getUrl(),
            'id' => $media->id,
            'mime_type' => $media->mime_type,
        ]);
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
    public function update(StoreEmailTemplateRequest $request, EmailTemplate $emailTemplate): JsonResponse
    {
        $this->authorize('update', $emailTemplate);

        $emailTemplate->update($request->validated());

        return response()->json(['data' => $emailTemplate]);
    }

    /**
     * Delete template.
     */
    public function destroy(EmailTemplate $emailTemplate): JsonResponse
    {
        $this->authorize('delete', $emailTemplate);

        $emailTemplate->delete();

        return response()->json(['message' => 'Template deleted']);
    }
}
