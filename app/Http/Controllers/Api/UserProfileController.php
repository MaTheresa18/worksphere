<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class UserProfileController extends Controller
{
    /**
     * Display the specified user profile.
     */
    public function show(Request $request, User $user)
    {
        $this->authorize('viewProfile', $user);

        return response()->json([
            'data' => [
                'public_id' => $user->public_id,
                'name' => $user->name,
                'username' => $user->username,
                'avatar_url' => $user->avatar_url,
                'bio' => $user->bio,
                'job_title' => $user->title,
                'location' => $user->location,
                'website' => $user->website,
                'skills' => $user->skills,
                'joined_at' => $user->created_at->toIso8601String(),
                'role_level' => $user->role_level,
                'status' => $user->status,
                // Only show email if they are in the same team
                'email' => $user->email,
            ],
        ]);
    }

    /**
     * Get tasks assigned to the user (Team Work).
     */
    public function assignedTasks(Request $request, User $user)
    {
        // 1. Authorization: Can auth user view profile?
        $this->authorize('viewProfile', $user);

        // 2. Query Tasks
        // - Assigned to target user
        // - Created by SOMEONE ELSE (not self-assigned)
        // - Visible to AUTH user (via project membership)
        $query = \App\Models\Task::query()
            ->with(['project', 'creator', 'status'])
            ->where('assigned_to', $user->id)
            ->where('created_by', '!=', $user->id) // Filter out self-assigned
            ->whereHas('project', function ($q) use ($request) {
                // Ensure AUTH user is a member of the project
                $q->whereHas('members', function ($m) use ($request) {
                    $m->where('user_id', $request->user()->id);
                });
            })
            ->orderBy('due_date', 'asc')
            ->orderBy('priority', 'asc');

        $tasks = $query->paginate(20);

        // Use TaskResource if it exists, or simple mapping
        return \App\Http\Resources\TaskResource::collection($tasks);
    }
}
