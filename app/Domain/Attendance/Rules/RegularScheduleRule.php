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

/**
 * Standard schedule: 08:00–12:00 (AM) and 13:00–17:00 (PM), 8 paid hours/day.
 * Adjust the hardcoded times below to match your actual company policy,
 * or make them constructor-configurable if different teams need different hours.
 */
class RegularScheduleRule extends RuleTableAwareScheduleRule
{
    public function __construct(?RuleTable $ruleTable = null)
    {
        parent::__construct($ruleTable ?? new EightHourWorkdayRuleTable());
    }

    public function expectedWorkWindows(WorkDay $day, ?EmployeeSnapshot $employee = null): ScheduleWindows
    {
        unset($employee);
        $date = $day->getDate();

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
