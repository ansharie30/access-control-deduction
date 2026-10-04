<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Support;

use Carbon\Carbon;

/**
 * Represents the calendar date a DailyAttendanceCalculator is evaluating.
 * Not present as its own box on the diagram, but referenced as the `day : WorkDay`
 * parameter on DailyAttendanceCalculator::evaluate() and ScheduleRule::expectedWorkWindows().
 */
class WorkDay
{
    public const DAY_TYPE_WORKING = 'working';
    public const DAY_TYPE_WEEKEND = 'weekend';
    public const DAY_TYPE_HOLIDAY = 'holiday';
    public const DAY_TYPE_SPECIAL_NON_WORKING = 'special_non_working';

    public function __construct(
        private Carbon $date,
        private string $dayType = self::DAY_TYPE_WORKING,
    ) {
    }

    public function getDate(): Carbon
    {
        return $this->date->copy()->startOfDay();
    }

    public function getDayType(): string
    {
        return $this->dayType;
    }

    public function isWorkingDay(): bool
    {
        return $this->dayType === self::DAY_TYPE_WORKING;
    }
}
