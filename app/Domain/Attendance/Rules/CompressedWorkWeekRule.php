<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Rules;

use App\Domain\Attendance\Models\EmployeeSnapshot;
use App\Domain\Attendance\Models\ScheduleWindows;
use App\Domain\Attendance\Models\WorkSession;
use App\Domain\Attendance\RuleTables\RuleTable;
use App\Domain\Attendance\RuleTables\TenHourWorkdayRuleTable;
use App\Domain\Attendance\Rules\RuleTableAwareScheduleRule;
use App\Domain\Attendance\Support\WorkDay;

class CompressedWorkWeekRule extends RuleTableAwareScheduleRule
{
    public function __construct(?RuleTable $ruleTable = null)
    {
        parent::__construct($ruleTable ?? new TenHourWorkdayRuleTable());
    }

    public function expectedWorkWindows(WorkDay $day, ?EmployeeSnapshot $employee = null): ScheduleWindows
    {
        unset($employee);
        $date = $day->getDate();

        return new ScheduleWindows([
            new WorkSession(
                $date->copy()->setTime(7, 30),
                $date->copy()->setTime(12, 00)
            ),
            new WorkSession(
                $date->copy()->setTime(13, 00),
                $date->copy()->setTime(18, 30)
            ),
        ]);
    }
}
