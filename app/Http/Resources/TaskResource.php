<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $team = $this->project->team;
        $permService = app(\App\Services\PermissionService::class);

        return [
            'id' => $this->public_id,
            'public_id' => $this->public_id,
            'project_id' => $this->project?->public_id,
            'team_id' => $this->project?->team?->public_id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => [
                'value' => $this->status?->value,
                'label' => $this->status?->label(),
                'color' => $this->status?->color(),
            ],
            'priority' => $this->priority,
            'sort_order' => $this->sort_order,
            'due_date' => $this->due_date?->toDateString(),
            'estimated_hours' => $this->estimated_hours,
            'actual_hours' => $this->actual_hours,
            'checklist' => $this->checklist,
            'checklist_total' => $this->checklistItems()->count(),
            'checklist_done' => $this->checklistItems()->where('status', \App\Enums\TaskChecklistItemStatus::Done)->count(),
            'is_overdue' => $this->is_overdue,
            'days_until_due' => $this->days_until_due,
            'available_transitions' => $this->when($request->has('with_transitions'), function () {
                return $this->status->allowedTransitions();
            }),
            'project' => $this->whenLoaded('project', function () {
                return [
                    'id' => $this->project->public_id,
                    'team_id' => $this->project->team->public_id ?? null,
                    'name' => $this->project->name,
                    'slug' => $this->project->slug,
                    'client' => $this->project->client ? [
                        'id' => $this->project->client->public_id,
                        'name' => $this->project->client->name,
                    ] : null,
                ];
            }),
            'parent' => $this->whenLoaded('parent', function () {
                if (! $this->parent) {
                    return null;
                }

                return [
                    'id' => $this->parent->public_id,
                    'title' => $this->parent->title,
                ];
            }),
            'template' => $this->whenLoaded('template', function () {
                if (! $this->template) {
                    return null;
                }

                return [
                    'id' => $this->template->public_id,
                    'name' => $this->template->name,
                ];
            }),
            'assignee' => $this->whenLoaded('assignee', function () {
                if (! $this->assignee) {
                    return null;
                }

                return [
                    'id' => $this->assignee->public_id,
                    'name' => $this->assignee->name,
                    'email' => $this->assignee->email,
                    'avatar_url' => $this->assignee->avatar_url,
                ];
            }),
            'qa_user' => $this->whenLoaded('qaUser', function () {
                if (! $this->qaUser) {
                    return null;
                }

                return [
                    'id' => $this->qaUser->public_id,
                    'name' => $this->qaUser->name,
                    'email' => $this->qaUser->email,
                    'avatar_url' => $this->qaUser->avatar_url,
                ];
            }),
            'assigner' => $this->whenLoaded('assigner', function () {
                if (! $this->assigner) {
                    return null;
                }

                return [
                    'id' => $this->assigner->public_id,
                    'name' => $this->assigner->name,
                ];
            }),
            'creator' => $this->whenLoaded('creator', function () {
                return [
                    'id' => $this->creator->public_id,
                    'name' => $this->creator->name,
                    'email' => $this->creator->email,
                    'avatar_url' => $this->creator->avatar_url,
                ];
            }),
            'archiver' => $this->whenLoaded('archiver', function () {
                if (! $this->archiver) {
                    return null;
                }

                return [
                    'id' => $this->archiver->public_id,
                    'name' => $this->archiver->name,
                ];
            }),
            'subtasks' => TaskResource::collection($this->whenLoaded('subtasks')),
            'subtasks_count' => $this->whenCounted('subtasks', $this->subtasks_count),
            'comments_count' => $this->whenCounted('comments', $this->comments_count),
            'latest_qa_review' => $this->whenLoaded('qaReviews', function () {
                $latestReview = $this->qaReviews->sortByDesc('created_at')->first();
                if (! $latestReview) {
                    return null;
                }

                return [
                    'id' => $latestReview->id,
                    'status' => $latestReview->status,
                    'reviewer' => [
                        'id' => $latestReview->reviewer->public_id,
                        'name' => $latestReview->reviewer->name,
                    ],
                    'reviewed_at' => $latestReview->reviewed_at?->toIso8601String(),
                    'notes' => $latestReview->notes,
                ];
            }),
            'attachments' => $this->when($this->relationLoaded('media'), function () {
                return $this->getMedia('attachments')->map(function ($media) {
                    return [
                        'id' => $media->id,
                        'name' => $media->name,
                        'file_name' => $media->file_name,
                        'mime_type' => $media->mime_type,
                        'size' => $media->size,
                        // USE SIGNED URL
                        'url' => \Illuminate\Support\Facades\URL::temporarySignedRoute(
                            'media.show',
                            now()->addMinutes(60),
                            ['media' => $media->id]
                        ),
                        'created_at' => $media->created_at->toIso8601String(),
                    ];
                });
            }),
            'assigned_at' => $this->assigned_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'sent_to_client_at' => $this->sent_to_client_at?->toIso8601String(),
            'client_approved_at' => $this->client_approved_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'archived_at' => $this->archived_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'can' => [
                'edit' => $request->user()->can('update', $this->resource),
                'edit_metadata' => $permService->hasTeamPermission($user, $team, 'tasks.edit_all'),
                'manage_checklist' => $permService->hasTeamPermission($user, $team, 'tasks.manage_checklist'),
                'complete_items' => $permService->hasTeamPermission($user, $team, 'tasks.complete_items') || $this->assigned_to === $user->id,
                'assign' => $permService->hasTeamPermission($user, $team, 'tasks.assign'),
                'qa_review' => $permService->hasTeamPermission($user, $team, 'tasks.qa_review'),
                'add_comment' => $permService->hasTeamPermission($user, $team, 'tasks.comment'),
                'is_read_only' => in_array($this->status, [\App\Enums\TaskStatus::Submitted, \App\Enums\TaskStatus::InQa, \App\Enums\TaskStatus::PmReview]) && 
                                  !$permService->hasTeamPermission($user, $team, 'tasks.qa_review'),
                
                // Workflow Actions
                'start_task' => in_array($this->status, [\App\Enums\TaskStatus::Open, \App\Enums\TaskStatus::Draft]) && 
                                ! empty($this->assigned_to) &&
                                ($this->assigned_to === $user->id || $permService->hasTeamPermission($user, $team, 'tasks.update')),
                
                'submit_qa' => $this->status === \App\Enums\TaskStatus::InProgress && 
                               ($this->assigned_to === $user->id || $permService->hasTeamPermission($user, $team, 'tasks.update')),
                               
                'hold' => match(true) {
                    // Resume from Hold (Check both Operator and QA permissions)
                    $this->status === \App\Enums\TaskStatus::OnHold =>
                        ($this->assigned_to === $user->id || 
                         $permService->hasTeamPermission($user, $team, 'tasks.update') || 
                         $permService->hasTeamPermission($user, $team, 'tasks.qa_review')),

                    // Initial Stage: Operator or Lead can hold
                    $this->status === \App\Enums\TaskStatus::InProgress => 
                        ($this->assigned_to === $user->id || $permService->hasTeamPermission($user, $team, 'tasks.update')),
                    
                    // QA Stage: Only QA/Lead can hold
                    in_array($this->status, [\App\Enums\TaskStatus::Submitted, \App\Enums\TaskStatus::InQa]) => 
                        $permService->hasTeamPermission($user, $team, 'tasks.qa_review'),
                    
                    default => false,
                },

                'start_qa_review' => $this->status === \App\Enums\TaskStatus::Submitted && 
                                     ! empty($this->qa_user_id) &&
                                     $permService->hasTeamPermission($user, $team, 'tasks.qa_review'),
                                     
                'complete_qa_review' => $this->status === \App\Enums\TaskStatus::InQa && 
                                        $permService->hasTeamPermission($user, $team, 'tasks.qa_review'),
                                        
                'pm_review' => ($this->status === \App\Enums\TaskStatus::PmReview || $this->status === \App\Enums\TaskStatus::Approved) && 
                               $permService->hasTeamPermission($user, $team, 'tasks.approve'),
                               
                'client_review' => $this->status === \App\Enums\TaskStatus::SentToClient && 
                                   (
                                        // Is Client
                                        ($user->isClient() && $this->project->client_id === $user->linked_client?->id) ||
                                        // Or Internal Proxy
                                        $permService->hasTeamPermission($user, $team, 'tasks.client_response')
                                   ),
                
                'manage_media' => (in_array($this->status, [\App\Enums\TaskStatus::Draft, \App\Enums\TaskStatus::Open, \App\Enums\TaskStatus::InProgress, \App\Enums\TaskStatus::Rejected]) &&
                                  ($this->assigned_to === $user->id || $permService->hasTeamPermission($user, $team, 'tasks.update'))) ||
                                  $permService->hasTeamPermission($user, $team, 'tasks.manage_files'),

                'complete_task' => $this->status === \App\Enums\TaskStatus::ClientApproved && 
                                   ($permService->hasTeamPermission($user, $team, 'tasks.complete') || 
                                    $permService->hasTeamPermission($user, $team, 'tasks.edit_all') ||
                                    $this->assigned_to === $user->id),
                                   
                'restart_task' => ($this->status->isRejected()) && 
                                  ($this->assigned_to === $user->id || $permService->hasTeamPermission($user, $team, 'tasks.update')),
            ],
        ];
    }
}
