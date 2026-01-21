<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Team Creation Limits
    |--------------------------------------------------------------------------
    |
    | These settings control how many teams a user can own or join.
    |
    */

    'limits' => [
        'max_teams_owned' => env('TEAM_MAX_OWNED', 5),
        'max_teams_joined' => env('TEAM_MAX_JOINED', 20),
        'require_approval' => env('TEAM_REQUIRE_APPROVAL', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Team Health Monitoring
    |--------------------------------------------------------------------------
    |
    | These settings control the team dormancy detection and cleanup process.
    |
    */

    'health' => [
        // Number of days with no activity before a team is marked dormant
        'dormant_after_days' => env('TEAM_DORMANT_DAYS', 90),

        // Number of days after dormant warning before deletion is scheduled
        'deletion_grace_days' => env('TEAM_DELETION_GRACE_DAYS', 30),

        // Whether to automatically delete teams past the grace period
        'auto_delete_enabled' => env('TEAM_AUTO_DELETE', false),
    ],
];
