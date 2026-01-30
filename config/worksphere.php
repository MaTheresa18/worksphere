<?php

return [
    'task' => [
        'auto_archive' => [
            'enabled' => env('TASK_AUTO_ARCHIVE_ENABLED', true),
            'days_after_completion' => env('TASK_AUTO_ARCHIVE_DAYS', 30),
        ],
    ],
];
