<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Rules;

use App\Domain\Attendance\Contracts\ScheduleRule;
use App\Domain\Attendance\Models\EmployeeSnapshot;
use App\Domain\Attendance\Models\ScheduleWindows;
use App\Domain\Attendance\RuleTables\EightHourWorkdayRuleTable;
use App\Domain\Attendance\RuleTables\RuleTable;
use App\Domain\Attendance\Support\WorkDay;

abstract class RuleTableAwareScheduleRule implements ScheduleRule
{
    protected RuleTable $ruleTable;

    public function __construct(?RuleTable $ruleTable = null)
    {
        $this->ruleTable = $ruleTable ?? new EightHourWorkdayRuleTable();
    }

    public function getRuleTable(): RuleTable
    {
        return $this->ruleTable;
    }

    public function setRuleTable(RuleTable $ruleTable): self
    {
        $this->ruleTable = $ruleTable;

        return $this;
    }

    abstract public function expectedWorkWindows(WorkDay $day, ?EmployeeSnapshot $employee = null): ScheduleWindows;
}
