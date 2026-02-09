<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Email;
use App\Services\EmailSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailAttachmentController extends Controller
{
    /**
     * Download an attachment that was skipped during initial sync.
     */
    public function download(Request $request, Email $email, int $index, EmailSyncService $syncService): JsonResponse
    {
        // Ensure user can access this email
        if ($request->user()->cannot('view', $email)) {
            abort(403);
        }

        try {
            $media = $syncService->downloadAttachment($email, $index);

            return response()->json([
                'message' => 'Attachment downloaded successfully',
                'attachment' => [
                    'id' => (string) $media->id,
                    'name' => $media->file_name,
                    'size' => $media->human_readable_size,
                    'type' => $media->mime_type,
                    'url' => $media->getUrl(),
                    'content_id' => $media->getCustomProperty('content_id'),
                    'is_downloaded' => true,
                ],
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => 'Attachment not found'], 404);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Failed to download attachment: '.$e->getMessage()], 500);
        }
    }
}
