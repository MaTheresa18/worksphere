<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeamMemberResource extends JsonResource
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
            'username' => $this->username,
            'display_name' => $this->display_name,
            'initials' => $this->initials,
            'avatar_url' => $this->avatar_url,
            'has_avatar' => $this->has_avatar,
            'role' => $this->whenPivotLoaded('team_user', function () {
                return $this->pivot->role;
            }),
            'role_label' => $this->whenPivotLoaded('team_user', function () {
                return ucwords(str_replace(['_', '-'], ' ', $this->pivot->role));
            }),
            'is_qa_eligible' => $this->whenPivotLoaded('team_user', function () {
                $rolePermissions = config("roles.team_role_permissions.{$this->pivot->role}", []);

                return in_array('tasks.qa_review', $rolePermissions);
            }),
            'joined_at' => $this->whenPivotLoaded('team_user', function () {
                return $this->pivot->created_at;
            }),
            'can' => [
                'manage' => $request->user()?->can('update', $this->resource instanceof \App\Models\Team ? $this->resource : (isset($this->pivot->team_id) ? \App\Models\Team::find($this->pivot->team_id) : null)),
            ],
        ];
    }
}
