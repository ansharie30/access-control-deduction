<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Rules;

use App\Domain\Attendance\Models\EmployeeSnapshot;
use App\Domain\Attendance\Models\ScheduleWindows;
use App\Domain\Attendance\Models\WorkSession;
use App\Domain\Attendance\RuleTables\EightHourWorkdayRuleTable;
use App\Domain\Attendance\RuleTables\RuleTable;
use App\Domain\Attendance\Rules\RuleTableAwareScheduleRule;
use App\Domain\Attendance\Support\WorkDay;

class FridayFlexitimeRule extends RuleTableAwareScheduleRule
{
    public function __construct(?RuleTable $ruleTable = null)
    {
        parent::__construct($ruleTable ?? new EightHourWorkdayRuleTable());
    }

    public function expectedWorkWindows(WorkDay $day, ?EmployeeSnapshot $employee = null): ScheduleWindows
    {
        $date = $day->getDate();
        $muslim = $employee?->getMuslimFlag() ?? false;

        $isFriday = (int) $date->dayOfWeekIso === 5;

        if (! $isFriday) {
            return $this->regularSchedule($date);
        }

        if ($muslim) {
            return $this->muslimFridaySchedule($date);
        }

        return $this->nonMuslimFridaySchedule($date);
    }

    /**
     * Non-Friday: standard schedule identical to RegularScheduleRule.
     */
    private function regularSchedule(\Carbon\CarbonImmutable|\Carbon\Carbon $date): ScheduleWindows
    {
        return new ScheduleWindows([
            new WorkSession(
                $date->copy()->setTime(8, 0),
                $date->copy()->setTime(12, 0)
            ),
            new WorkSession(
                $date->copy()->setTime(13, 0),
                $date->copy()->setTime(17, 0)
            ),
        ]);
    }

    /**
     * Muslim Friday schedule (MO 0256): shorter PM window to accommodate Friday prayers.
     * AM : 08:00 – 12:00 (unchanged)
     * PM : 13:00 – 16:00 (ends 1 hour earlier)
     * Work envelope: 07:00–19:00 (handled via schedule windows; swipes outside are deducted by standard tardiness/undertime logic)
     */
    private function muslimFridaySchedule(\Carbon\CarbonImmutable|\Carbon\Carbon $date): ScheduleWindows
    {
        return new ScheduleWindows([
            new WorkSession(
                $date->copy()->setTime(8, 0),
                $date->copy()->setTime(12, 0)
            ),
            new WorkSession(
                $date->copy()->setTime(13, 0),
                $date->copy()->setTime(16, 0)
            ),
        ]);
    }

    /**
     * Non-Muslim Friday: standard 08:00–12:00 / 13:00–17:00 windows (no Friday prayer accommodation).
     */
    private function nonMuslimFridaySchedule(\Carbon\CarbonImmutable|\Carbon\Carbon $date): ScheduleWindows
    {
        return new ScheduleWindows([
            new WorkSession(
                $date->copy()->setTime(8, 0),
                $date->copy()->setTime(12, 0)
            ),
            new WorkSession(
                $date->copy()->setTime(13, 0),
                $date->copy()->setTime(17, 0)
            ),
        ]);
    }
}

