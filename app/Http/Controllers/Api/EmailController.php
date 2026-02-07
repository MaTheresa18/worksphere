<?php

namespace App\Http\Controllers\Api;

use App\Contracts\EmailServiceContract;
use App\Http\Controllers\Controller;
use App\Http\Requests\SendEmailRequest;
use App\Http\Resources\EmailResource;
use App\Models\Email;
use App\Models\EmailAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailController extends Controller
{
    public function __construct(
        protected EmailServiceContract $emailService
    ) {}

    /**
     * Display a listing of the resource.
     */
    /**
     * Display a listing of the resource (Threaded View).
     */
    public function index(Request $request)
    {
        // Base constraints
        $query = Email::query()->forUser(auth()->id());

        // --- Apply Filters to Base Query ---

        // Filter by Email Account
        if ($request->filled('email_account_id')) {
            $accountId = $request->email_account_id;
            if (\Illuminate\Support\Str::isUuid($accountId)) {
                $account = \App\Models\EmailAccount::where('public_id', $accountId)->first();
                $query->where('email_account_id', $account ? $account->id : -1);
            } else {
                $query->where('email_account_id', $accountId);
            }
        }

        // Filter by Label
        if ($request->filled('label')) {
            $query->whereHas('labels', function ($q) use ($request) {
                $q->where('email_labels.id', $request->label)
                    ->orWhere('email_labels.name', $request->label);
            });
        } else {
            // Filter by Folder (default to 'inbox')
            $folder = $request->input('folder', 'inbox');
            if ($folder !== 'all') {
                $query->where('folder', $folder);
            }
        }

        // Search
        if ($request->filled('q')) {
            $query->search($request->q);
        } elseif ($request->filled('search')) {
            $query->search($request->search);
        }

        // Date Filters
        if ($request->filled('date_from')) {
            $query->whereDate('received_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('received_at', '<=', $request->date_to);
        }

        // --- Threading Logic ---
        // We group by thread_id (or id if null) to get distinct conversations.
        // We emulate a "Thread" entity by creating a query that returns the stats of the thread.
        
        $threadQuery = $query->clone()
            ->selectRaw('
                COALESCE(thread_id, CAST(id AS CHAR)) as thread_key,
                MAX(received_at) as last_activity,
                COUNT(*) as thread_count,
                MAX(id) as latest_email_id
            ')
            ->groupBy('thread_key')
            ->groupBy('thread_key');

        // Apply Sorting
        $sortBy = $request->input('sort_by', 'date');
        $sortOrder = $request->input('order', 'desc');

        switch ($sortBy) {
            case 'sender':
                // For threads, we can sort by the sender of the latest email
                // or just MAX(from_name). Simplest is to sort by min/max based on order.
                // However, without a join this is tricky in a pure aggregate query if we want meaningful thread sorting.
                // But since we group by thread_key, we can aggregate.
                $threadQuery->orderByRaw("MAX(from_name) $sortOrder");
                break;
            case 'subject':
                $threadQuery->orderByRaw("MAX(subject) $sortOrder");
                break;
            case 'date':
            default:
                $threadQuery->orderBy('last_activity', $sortOrder);
                break;
        }

        // Paginate the THREADS, not the emails
        $threads = $threadQuery->paginate($request->integer('per_page', 25));

        // Now verify/hydrate the actual email models for the "latest" in each thread
        $latestIds = $threads->pluck('latest_email_id');
        
        $emails = Email::whereIn('id', $latestIds)
            ->with(['labels', 'emailAccount', 'media'])
            ->get()
            ->keyBy('id');

        // Map the results back to the paginator, injecting thread_count
        $resourceCollection = $threads->getCollection()->map(function ($threadStat) use ($emails) {
            $email = $emails->get($threadStat->latest_email_id);
            if (!$email) return null;

            $email->thread_count = $threadStat->thread_count;
            return $email;
        })->filter();

        $threads->setCollection($resourceCollection);

        // We use a header to tell the resource to use "lite" mode 
        // without needing every frontend call to explicitly add ?lite=1
        $request->merge(['lite' => true]);

        return EmailResource::collection($threads);
    }

    /**
     * Get all emails in a thread.
     */
    public function thread(Request $request, string $threadId): JsonResponse
    {
        // If threadId looks like an integer, it might be a single email fallback.
        // But our logic uses valid thread_ids usually.
        
        $emails = Email::query()
            ->forUser(auth()->id())
            ->where('thread_id', $threadId)
            ->with(['labels', 'emailAccount', 'media'])
            ->orderBy('received_at', 'asc') // Oldest first for reading flow
            ->get();

        // Fallback: if no emails found by thread_id, maybe it was a single email ID passed as thread key?
        // (Scenario: thread_id was null, so we used ID as key)
        if ($emails->isEmpty() && is_numeric($threadId)) {
             $emails = Email::query()
                ->forUser(auth()->id())
                ->where('id', $threadId)
                ->with(['labels', 'emailAccount', 'media'])
                ->get();
        }

        return response()->json(EmailResource::collection($emails));
    }

    /**
     * Display the specified resource.
     */
    public function show(Email $email)
    {
        $this->authorize('view', $email);

        $email->load(['labels', 'emailAccount', 'media']);

        // Use EmailResource for single item
        return new EmailResource($email);
    }

    /**
     * Send an email.
     */
    public function store(SendEmailRequest $request): JsonResponse
    {
        $this->authorize('create', Email::class);

        $accountId = $request->account_id;
        $account = \Illuminate\Support\Str::isUuid($accountId)
            ? EmailAccount::where('public_id', $accountId)->firstOrFail()
            : EmailAccount::findOrFail($accountId);

        // Ensure user can send from this account
        if ($account->is_system) {
            $this->authorize('sendAsSystem', Email::class);
        } else {
            // Check ownership/team access if needed.
            // For now, assuming user can send from accounts they own or team accounts they belong to.
            // Simplified check:
            if ($account->user_id !== $request->user()->id && $account->team_id !== $request->user()->current_team_id) {
                abort(403, 'Unauthorized to send from this account.');
            }
        }

        $email = $this->emailService->send(
            $request->user(),
            $account,
            $request->validated()
        );

        return response()->json($email, 201);
    }

    /**
     * Update an email (mark read, star, move).
     */
    public function update(Request $request, Email $email): JsonResponse
    {
        $this->authorize('update', $email);

        if ($request->has('is_read')) {
            $request->boolean('is_read')
                ? $this->emailService->markAsRead($email)
                : $this->emailService->markAsUnread($email);
        }

        if ($request->has('is_starred')) {
            $this->emailService->toggleStar($email);
        }

        if ($request->has('folder')) {
            $this->emailService->moveToFolder($email, $request->input('folder'));
        }

        return response()->json($email->fresh());
    }

    /**
     * Delete an email.
     */
    public function destroy(Email $email): JsonResponse
    {
        $this->authorize('delete', $email);

        $this->emailService->delete($email);

        return response()->json(['message' => 'Email deleted']);
    }

    /**
     * Bulk delete emails.
     */
    public function bulkDelete(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', 'exists:emails,id'],
        ]);

        $count = $this->emailService->bulkDelete($request->input('ids'), $request->user());

        return response()->json(['message' => "{$count} emails deleted"]);
    }

    /**
     * Get folder counts.
     */
    public function folderCounts(Request $request): JsonResponse
    {
        $counts = $this->emailService->getFolderCounts($request->user());

        return response()->json($counts);
    }

    /**
     * Export email as .eml file.
     */
    public function exportEml(Email $email)
    {
        $this->authorize('view', $email);

        // Build EML content
        $eml = $this->buildEmlContent($email);

        $filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $email->subject ?: 'email').'.eml';

        return response($eml, 200, [
            'Content-Type' => 'message/rfc822',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * Build EML content from Email model.
     */
    protected function buildEmlContent(Email $email): string
    {
        $boundary = '----=_Part_'.md5(uniqid());

        $headers = [
            'MIME-Version: 1.0',
            'Date: '.($email->received_at ?? now())->format('r'),
            'Subject: '.$email->subject,
            'From: '.($email->from_name ? '"'.$email->from_name.'" <'.$email->from_email.'>' : $email->from_email),
            'To: '.collect($email->to)->map(fn ($t) => $t['email'] ?? $t)->implode(', '),
            'Message-ID: '.($email->message_id ?: '<'.uniqid().'@coresync.local>'),
            'Content-Type: multipart/mixed; boundary="'.$boundary.'"',
        ];

        if (! empty($email->cc)) {
            $headers[] = 'Cc: '.collect($email->cc)->map(fn ($c) => $c['email'] ?? $c)->implode(', ');
        }

        $eml = implode("\r\n", $headers)."\r\n\r\n";

        // Body part
        $eml .= '--'.$boundary."\r\n";
        if ($email->body_html) {
            $eml .= "Content-Type: text/html; charset=UTF-8\r\n";
            $eml .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
            $eml .= quoted_printable_encode($email->body_raw ?: $email->body_html)."\r\n";
        } elseif ($email->body_plain) {
            $eml .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $eml .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
            $eml .= quoted_printable_encode($email->body_plain)."\r\n";
        }

        // Attachments
        foreach ($email->media as $attachment) {
            $eml .= '--'.$boundary."\r\n";
            $eml .= 'Content-Type: '.$attachment->mime_type.'; name="'.$attachment->file_name.'"'."\r\n";
            $eml .= 'Content-Disposition: attachment; filename="'.$attachment->file_name.'"'."\r\n";
            $eml .= "Content-Transfer-Encoding: base64\r\n";

            $contentId = $attachment->getCustomProperty('content_id');
            if ($contentId) {
                $eml .= 'Content-ID: <'.$contentId.'>'."\r\n";
            }

            $eml .= "\r\n";
            $eml .= chunk_split(base64_encode(file_get_contents($attachment->getPath())))."\r\n";
        }

        $eml .= '--'.$boundary."--\r\n";

        return $eml;
    }
}
