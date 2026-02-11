<?php

namespace App\Enums;

enum TaskChecklistItemStatus: string
{
    case Todo = 'todo';
    case InProgress = 'in_progress';
    case OnHold = 'on_hold';
    case Done = 'done';

    /**
     * Get the human-readable label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Todo => 'To Do',
            self::InProgress => 'In Progress',
            self::OnHold => 'On Hold',
            self::Done => 'Done',
        };
    }

    /**
     * Check if this is a completed status.
     */
    public function isCompleted(): bool
    {
        return $this === self::Done;
    }
}
