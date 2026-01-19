<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PersonalTask;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class PersonalTaskController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $tasks = PersonalTask::forUser($request->user())
            ->orderBy('completed_at', 'asc') // Incomplete first
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['data' => $tasks]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'status' => 'nullable|string|in:todo,done',
        ]);

        $task = PersonalTask::create([
            'user_id' => $request->user()->id,
            'title' => $validated['title'],
            'status' => $validated['status'] ?? 'todo',
            'priority' => 3, // Default normal priority
        ]);

        return response()->json(['data' => $task], 201);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, PersonalTask $personalTask): JsonResponse
    {
        // Ensure user owns the task
        if ($personalTask->user_id !== $request->user()->id) {
            abort(403);
        }

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'status' => 'sometimes|string|in:todo,done',
            'description' => 'nullable|string',
            'due_date' => 'nullable|date',
            'priority' => 'sometimes|integer|min:1|max:4',
        ]);

        if (isset($validated['status']) && $validated['status'] === 'done' && $personalTask->status !== 'done') {
            $validated['completed_at'] = now();
        } elseif (isset($validated['status']) && $validated['status'] !== 'done') {
            $validated['completed_at'] = null;
        }

        $personalTask->update($validated);

        return response()->json(['data' => $personalTask]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, PersonalTask $personalTask): JsonResponse
    {
        if ($personalTask->user_id !== $request->user()->id) {
            abort(403);
        }

        $personalTask->delete();

        return response()->json(['message' => 'Task deleted']);
    }
}
