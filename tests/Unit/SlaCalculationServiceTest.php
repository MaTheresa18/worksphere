<?php

namespace Tests\Unit;

use App\Services\AppSettingsService;
use App\Services\HolidayService;
use App\Services\SlaCalculationService;
use Carbon\Carbon;
use Mockery;
use Tests\TestCase;

class SlaCalculationServiceTest extends TestCase
{
    protected $settingsService;

    protected $holidayService;

    protected $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settingsService = Mockery::mock(AppSettingsService::class);
        $this->holidayService = Mockery::mock(HolidayService::class);

        $this->service = new SlaCalculationService(
            $this->settingsService,
            $this->holidayService
        );
    }

    public function test_calculate_working_hours_disabled_business_hours()
    {
        $this->settingsService->shouldReceive('get')
            ->with('tickets.sla.business_hours_enabled', false)
            ->andReturn(false);

        $start = Carbon::parse('2023-01-01 10:00:00');
        $end = Carbon::parse('2023-01-02 10:00:00');

        $hours = $this->service->calculateWorkingHoursBetween($start, $end);

        $this->assertEquals(24.0, $hours);
    }

    public function test_calculate_working_hours_enabled_same_day()
    {
        $this->mockBusinessHours();

        $start = Carbon::parse('2023-06-05 10:00:00'); // Monday
        $end = Carbon::parse('2023-06-05 14:00:00');

        $hours = $this->service->calculateWorkingHoursBetween($start, $end);

        $this->assertEquals(4.0, $hours);
    }

    public function test_calculate_working_hours_excludes_non_business_hours()
    {
        $this->mockBusinessHours();

        // 9-17 business hours
        // Start: Monday 16:00
        // End: Tuesday 10:00
        // Expected: 1 hour (Mon 16-17) + 1 hour (Tue 9-10) = 2 hours

        $start = Carbon::parse('2023-06-05 16:00:00'); // Monday
        $end = Carbon::parse('2023-06-06 10:00:00'); // Tuesday

        $hours = $this->service->calculateWorkingHoursBetween($start, $end);

        $this->assertEquals(2.0, $hours);
    }

    public function test_calculate_working_hours_excludes_weekends()
    {
        $this->mockBusinessHours();

        // Start: Friday 16:00
        // End: Monday 10:00
        // Expected: 1 hour (Fri 16-17) + 0 (Sat) + 0 (Sun) + 1 hour (Mon 9-10) = 2 hours

        $start = Carbon::parse('2023-06-09 16:00:00'); // Friday
        $end = Carbon::parse('2023-06-12 10:00:00'); // Monday

        $hours = $this->service->calculateWorkingHoursBetween($start, $end);

        $this->assertEquals(2.0, $hours);
    }

    public function test_calculate_working_hours_excludes_holidays()
    {
        $this->mockBusinessHours(true);

        // Holiday on Tuesday
        $this->settingsService->shouldReceive('get')
            ->with('tickets.sla.holiday_country', 'US')
            ->andReturn('US');

        // Mock holiday service to return a holiday on Tuesday 2023-06-06
        $this->holidayService->shouldReceive('getHolidays')
            ->andReturn([
                ['date' => '2023-06-06', 'name' => 'Test Holiday'],
            ]);

        // Start: Monday 16:00
        // End: Wednesday 10:00
        // Expected: 1h (Mon) + 0 (Tue Holiday) + 1h (Wed) = 2 hours

        $start = Carbon::parse('2023-06-05 16:00:00'); // Monday
        $end = Carbon::parse('2023-06-07 10:00:00'); // Wednesday

        $hours = $this->service->calculateWorkingHoursBetween($start, $end);

        $this->assertEquals(2.0, $hours);
    }

    public function test_add_working_hours()
    {
        $this->mockBusinessHours();

        // Start: Friday 16:00
        // Add 3 working hours
        // Expected: Fri 16-17 (1h) -> Wknd -> Mon 9-11 (2h) -> Result: Mon 11:00

        $start = Carbon::parse('2023-06-09 16:00:00'); // Friday
        $hoursToAdd = 3;

        $result = $this->service->addWorkingHours($start, $hoursToAdd);

        $this->assertEquals('2023-06-12 11:00:00', $result->format('Y-m-d H:i:s'));
    }

    protected function mockBusinessHours($excludeHolidays = false)
    {
        $this->settingsService->shouldReceive('get')
            ->with('tickets.sla.business_hours_enabled', false)
            ->andReturn(true);

        $this->settingsService->shouldReceive('get')
            ->with('tickets.sla.business_hours_start', '09:00')
            ->andReturn('09:00');

        $this->settingsService->shouldReceive('get')
            ->with('tickets.sla.business_hours_end', '17:00')
            ->andReturn('17:00');

        $this->settingsService->shouldReceive('get')
            ->with('tickets.sla.business_days', [1, 2, 3, 4, 5])
            ->andReturn([1, 2, 3, 4, 5]);

        $this->settingsService->shouldReceive('get')
            ->with('tickets.sla.exclude_holidays', false)
            ->andReturn($excludeHolidays);
    }
}
