<?php

namespace App\Enums;

enum TeamRole: string
{
    case TeamLead = 'team_lead';
    case SubjectMatterExpert = 'subject_matter_expert';
    case QualityAssessor = 'quality_assessor';
    case Operator = 'operator';

    /**
     * Get the human-readable label for the role.
     */
    public function label(): string
    {
        return match ($this) {
            self::TeamLead => 'Team Lead',
            self::SubjectMatterExpert => 'Subject Matter Expert',
            self::QualityAssessor => 'Quality Assessor',
            self::Operator => 'Operator',
        };
    }

    /**
     * Get the numeric level for comparison.
     */
    public function level(): int
    {
        return match ($this) {
            self::TeamLead => 100,
            self::SubjectMatterExpert => 75,
            self::QualityAssessor => 50,
            self::Operator => 25,
        };
    }

    /**
     * Get the color associated with the role.
     */
    public function color(): string
    {
        return match ($this) {
            self::TeamLead => 'purple',
            self::SubjectMatterExpert => 'blue',
            self::QualityAssessor => 'orange',
            self::Operator => 'green',
        };
    }

    /**
     * Check if this role can manage team members.
     */
    public function canManageMembers(): bool
    {
        return in_array($this, [self::TeamLead, self::SubjectMatterExpert]);
    }

    /**
     * Check if this role can manage team settings.
     */
    public function canManageSettings(): bool
    {
        return in_array($this, [self::TeamLead, self::SubjectMatterExpert]);
    }

    /**
     * Check if this role can delete the team.
     */
    public function canDeleteTeam(): bool
    {
        return $this === self::TeamLead;
    }

    /**
     * Check if this role has higher privilege than another.
     */
    public function isHigherThan(self $role): bool
    {
        return $this->level() > $role->level();
    }

    /**
     * Check if this role has at least the same privilege as another.
     */
    public function isAtLeast(self $role): bool
    {
        return $this->level() >= $role->level();
    }

    /**
     * Get all roles this role can assign to others.
     *
     * @return array<self>
     */
    public function assignableRoles(): array
    {
        return match ($this) {
            self::TeamLead => [self::SubjectMatterExpert, self::QualityAssessor, self::Operator],
            self::SubjectMatterExpert => [self::QualityAssessor, self::Operator],
            default => [],
        };
    }
}
