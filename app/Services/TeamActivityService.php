<?php

namespace App\Services;

use App\Models\Team;

class TeamActivityService
{
    /**
     * Record activity for a team (updates last_activity_at).
     */
    public function recordActivity(Team $team): void
    {
        // Only update if not already updated today to avoid excessive DB writes
        if ($team->last_activity_at === null || $team->last_activity_at->lt(now()->startOfDay())) {
            $team->touchActivity();
        }
    }

    /**
     * Get the max teams a user can own.
     */
    public function getMaxTeamsOwned(): int
    {
        return (int) app(AppSettingsService::class)->get(
            'teams.max_owned',
            config('teams.limits.max_teams_owned', 5)
        );
    }

    /**
     * Get the max teams a user can join.
     */
    public function getMaxTeamsJoined(): int
    {
        return (int) app(AppSettingsService::class)->get(
            'teams.max_joined',
            config('teams.limits.max_teams_joined', 20)
        );
    }

    /**
     * Check if a user can create a new team.
     */
    public function canUserCreateTeam(\App\Models\User $user): bool
    {
        $ownedTeamsCount = Team::where('owner_id', $user->id)->count();

        return $ownedTeamsCount < $this->getMaxTeamsOwned();
    }

    /**
     * Get the number of teams a user owns.
     */
    public function getOwnedTeamsCount(\App\Models\User $user): int
    {
        return Team::where('owner_id', $user->id)->count();
    }

    /**
     * Get remaining team creation slots for a user.
     */
    public function getRemainingTeamSlots(\App\Models\User $user): int
    {
        return max(0, $this->getMaxTeamsOwned() - $this->getOwnedTeamsCount($user));
    }
}
