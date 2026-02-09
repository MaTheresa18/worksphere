<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectMemberResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'public_id' => $this->public_id,
            'name' => $this->name,
            'email' => $this->email,
            'username' => $this->username,
            'avatar_url' => $this->avatar_url,
            // Project-specific role from pivot
            'role' => $this->whenPivotLoaded('project_members', function () {
                return $this->pivot->role;
            }),
            'role_label' => $this->whenPivotLoaded('project_members', function () {
                return ucwords(str_replace(['_', '-'], ' ', $this->pivot->role));
            }),
            'joined_at' => $this->whenPivotLoaded('project_members', function () {
                return $this->pivot->joined_at ?? $this->pivot->created_at;
            }),
            'is_qa_eligible' => $this->when($this->relationLoaded('teams'), function () {
                // Get the team role from the loaded teams relation (should be only one if filtered correctly in controller)
                $team = $this->teams->first();
                if (! $team || ! $team->pivot) {
                    return false;
                }

                $role = $team->pivot->role;
                $rolePermissions = config("roles.team_role_permissions.{$role}", []);

                return in_array('tasks.qa_review', $rolePermissions);
            }, false),
        ];
    }
}
