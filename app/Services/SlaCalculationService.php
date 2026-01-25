<?php

namespace App\Services;

use App\Models\Ticket;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class SlaCalculationService
{
    public function __construct(
        protected AppSettingsService $settingsService,
        protected HolidayService $holidayService
    ) {}

    /**
     * Calculate working hours between two dates considering business hours and holidays.
     */
    public function calculateWorkingHoursBetween(Carbon $start, Carbon $end): float
    {
        if (! $this->isBusinessHoursEnabled()) {
            return $start->floatDiffInHours($end);
        }

        $workingHours = 0;
        $current = $start->copy();

        $businessStart = $this->getBusinessHoursStart();
        $businessEnd = $this->getBusinessHoursEnd();
        $businessDays = $this->getBusinessDays();

        while ($current->lt($end)) {
            // Check if current day is a business day
            if (! in_array($current->dayOfWeek, $businessDays)) {
                $current->addDay()->startOfDay();

                continue;
            }

            // Check if current day is a holiday
            if ($this->isHoliday($current)) {
                $current->addDay()->startOfDay();

                continue;
            }

            // Calculate business hours for this day
            $dayStart = $current->copy()->setTimeFromTimeString($businessStart);
            $dayEnd = $current->copy()->setTimeFromTimeString($businessEnd);

            // Adjust start time if we're starting mid-day
            if ($current->gt($dayStart)) {
                $dayStart = $current->copy();
            }

            // Adjust end time if we're ending mid-day
            $actualEnd = $end->copy();
            if ($actualEnd->isSameDay($current) && $actualEnd->lt($dayEnd)) {
                $dayEnd = $actualEnd;
            }

            // Only count hours if we're within business hours
            if ($dayStart->lt($dayEnd)) {
                $workingHours += $dayStart->floatDiffInHours($dayEnd);
            }

            // Move to next day
            $current->addDay()->startOfDay();
        }

        return $workingHours;
    }

    /**
     * Check if a datetime falls within business hours.
     */
    public function isBusinessHour(Carbon $dateTime): bool
    {
        if (! $this->isBusinessHoursEnabled()) {
            return true;
        }

        // Check if it's a business day
        if (! in_array($dateTime->dayOfWeek, $this->getBusinessDays())) {
            return false;
        }

        // Check if it's a holiday
        if ($this->isHoliday($dateTime)) {
            return false;
        }

        // Check if time is within business hours
        $businessStart = $dateTime->copy()->setTimeFromTimeString($this->getBusinessHoursStart());
        $businessEnd = $dateTime->copy()->setTimeFromTimeString($this->getBusinessHoursEnd());

        return $dateTime->between($businessStart, $businessEnd);
    }

    /**
     * Check if a date is a holiday.
     */
    public function isHoliday(Carbon $date): bool
    {
        if (! $this->shouldExcludeHolidays()) {
            return false;
        }

        $countryCode = $this->getHolidayCountry();
        if (! $countryCode) {
            return false;
        }

        $cacheKey = "sla_holiday_{$countryCode}_{$date->year}_{$date->format('m-d')}";

        return Cache::remember($cacheKey, now()->addDays(30), function () use ($countryCode, $date) {
            $holidays = $this->holidayService->getHolidays($countryCode, $date->year);

            foreach ($holidays as $holiday) {
                if ($holiday['date'] === $date->format('Y-m-d')) {
                    return true;
                }
            }

            return false;
        });
    }

    /**
     * Add working hours to a start date.
     */
    public function addWorkingHours(Carbon $start, int $hours): Carbon
    {
        if (! $this->isBusinessHoursEnabled()) {
            return $start->copy()->addHours($hours);
        }

        $result = $start->copy();
        $hoursToAdd = $hours;

        $businessStart = $this->getBusinessHoursStart();
        $businessEnd = $this->getBusinessHoursEnd();
        $businessDays = $this->getBusinessDays();

        // Calculate hours in a business day
        $hoursPerDay = Carbon::parse($businessStart)->floatDiffInHours(Carbon::parse($businessEnd));

        while ($hoursToAdd > 0) {
            // Skip to next business day if current day is not a business day
            if (! in_array($result->dayOfWeek, $businessDays) || $this->isHoliday($result)) {
                $result->addDay()->startOfDay();

                continue;
            }

            // Set to business start if before business hours
            $dayStart = $result->copy()->setTimeFromTimeString($businessStart);
            $dayEnd = $result->copy()->setTimeFromTimeString($businessEnd);

            if ($result->lt($dayStart)) {
                $result = $dayStart->copy();
            }

            // If we're past business hours, move to next day
            if ($result->gte($dayEnd)) {
                $result->addDay()->startOfDay();

                continue;
            }

            // Calculate remaining hours in current day
            $remainingInDay = $result->floatDiffInHours($dayEnd);

            if ($hoursToAdd <= $remainingInDay) {
                // We can finish today
                $result->addHours($hoursToAdd);
                $hoursToAdd = 0;
            } else {
                // Use up this day and continue
                $hoursToAdd -= $remainingInDay;
                $result->addDay()->startOfDay();
            }
        }

        return $result;
    }

    /**
     * Get response SLA deadline for a ticket.
     */
    public function getResponseSlaDeadline(Ticket $ticket): ?Carbon
    {
        if (! $ticket->sla_response_hours) {
            return null;
        }

        return $this->addWorkingHours($ticket->created_at, $ticket->sla_response_hours);
    }

    /**
     * Get resolution SLA deadline for a ticket.
     */
    public function getResolutionSlaDeadline(Ticket $ticket): ?Carbon
    {
        if (! $ticket->sla_resolution_hours) {
            return null;
        }

        return $this->addWorkingHours($ticket->created_at, $ticket->sla_resolution_hours);
    }

    /**
     * Get SLA progress percentage (0-100+).
     */
    public function getSlaProgress(Ticket $ticket, string $type): float
    {
        if ($type === 'response') {
            if (! $ticket->sla_response_hours) {
                return 0;
            }

            $deadline = $this->getResponseSlaDeadline($ticket);
            $elapsed = $this->calculateWorkingHoursBetween($ticket->created_at, now());

            return ($elapsed / $ticket->sla_response_hours) * 100;
        }

        if ($type === 'resolution') {
            if (! $ticket->sla_resolution_hours) {
                return 0;
            }

            $deadline = $this->getResolutionSlaDeadline($ticket);
            $elapsed = $this->calculateWorkingHoursBetween($ticket->created_at, now());

            return ($elapsed / $ticket->sla_resolution_hours) * 100;
        }

        return 0;
    }

    /**
     * Check if warning threshold has been reached.
     */
    public function isWarningThresholdReached(Ticket $ticket, string $type): bool
    {
        $progress = $this->getSlaProgress($ticket, $type);
        $threshold = $this->getWarningThreshold();

        return $progress >= $threshold && $progress < 100;
    }

    /**
     * Check if SLA is enabled.
     */
    protected function isSlaEnabled(): bool
    {
        return (bool) $this->settingsService->get('tickets.sla.enabled', true);
    }

    /**
     * Check if business hours are enabled.
     */
    protected function isBusinessHoursEnabled(): bool
    {
        return (bool) $this->settingsService->get('tickets.sla.business_hours_enabled', false);
    }

    /**
     * Get business hours start time.
     */
    protected function getBusinessHoursStart(): string
    {
        return $this->settingsService->get('tickets.sla.business_hours_start', '09:00');
    }

    /**
     * Get business hours end time.
     */
    protected function getBusinessHoursEnd(): string
    {
        return $this->settingsService->get('tickets.sla.business_hours_end', '17:00');
    }

    /**
     * Get business days (1=Monday, 7=Sunday).
     */
    protected function getBusinessDays(): array
    {
        $days = $this->settingsService->get('tickets.sla.business_days', [1, 2, 3, 4, 5]);

        return is_array($days) ? $days : json_decode($days, true) ?? [1, 2, 3, 4, 5];
    }

    /**
     * Get holiday country code.
     */
    protected function getHolidayCountry(): ?string
    {
        return $this->settingsService->get('tickets.sla.holiday_country', 'US');
    }

    /**
     * Check if holidays should be excluded.
     */
    protected function shouldExcludeHolidays(): bool
    {
        return (bool) $this->settingsService->get('tickets.sla.exclude_holidays', false);
    }

    /**
     * Get warning threshold percentage.
     */
    protected function getWarningThreshold(): int
    {
        return (int) $this->settingsService->get('tickets.sla.warning_threshold', 80);
    }
}
