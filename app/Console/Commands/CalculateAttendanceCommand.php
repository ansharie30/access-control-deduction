<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Attendance\DailyAttendanceCalculator;
use App\Domain\Attendance\Fixtures\FakeEmployees;
use App\Domain\Attendance\Models\EmployeeSnapshot;
use App\Domain\Attendance\Models\Punch;
use App\Domain\Attendance\Policies\PermanentPolicy;
use App\Domain\Attendance\Rules\CompressedWorkWeekRule;
use App\Domain\Attendance\Rules\FridayFlexitimeRule;
use App\Domain\Attendance\Rules\RegularScheduleRule;

use App\Domain\Attendance\Support\WorkDay;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CalculateAttendanceCommand extends Command
{
    protected $signature = 'attendance:calculate
        {--employee_ref= : Employee ref from FakeEmployees (e.g. EMP-2026-0001)}
        {--salary=33000 : Monthly basic salary (defaults to 33000 if not provided)}
        {--employment_type=permanent : Employment type code (defaults to "permanent" if not provided)}
        {--muslim_flag=true : Muslim flag (defaults to true if not provided)}
        {--date= : Date to evaluate (Y-m-d, defaults to today)}
        {--punches= : Custom punches as comma-separated HH:MM (e.g. "08:05,12:00,13:00,16:45")}
        {--schedule=regular : Schedule rule: regular|friday_flexi|compressed}
        {--leave_credits=0 : Leave credits in equivalent workdays (defaults to 0s)}
        {--day_type=auto : Override day type: auto|working|weekend|holiday|special_non_working}';

    protected $description = 'Run the attendance deduction calculator using a fake employee reference';

    public function handle(): int
    {
        $employee = $this->resolveEmployee();
        if ($employee === null) {
            return self::FAILURE;
        }

        // Apply overrides / defaults for salary, employment type and muslim flag
        $salaryOpt = $this->option('salary');
        $employmentTypeOpt = $this->option('employment_type');
        $muslimFlagOpt = $this->option('muslim_flag');

        $salary = ($salaryOpt !== null && $salaryOpt !== '') ? (float) $salaryOpt : 33000.0;
        $employmentType = ($employmentTypeOpt !== null && $employmentTypeOpt !== '') ? (string) $employmentTypeOpt : 'permanent';

        if ($muslimFlagOpt === null || $muslimFlagOpt === '') {
            $muslimFlag = true;
        } else {
            $muslimFlag = in_array(strtolower((string) $muslimFlagOpt), ['1', 'true', 'yes', 'y'], true);
        }

        // Rebuild a snapshot with overrides applied so downstream code uses these values
        $employee = new \App\Domain\Attendance\Models\EmployeeSnapshot(
            $employee->getEmployeeRef(),
            $employmentType,
            $muslimFlag,
            $salary,
            $employee->getExcludeFromPayroll(),
            $employee->getCreditRufPerRemaining(),
            $employee->getCreditBuffer()
        );

        $dateStr = $this->option('date');
        $date = $dateStr ? Carbon::parse($dateStr) : Carbon::today();
        $dayType = $this->resolveDayType($date);
        $workDay = new WorkDay($date, $dayType);

        $punches = $this->resolvePunches($employee, $workDay);

        $scheduleRule = $this->resolveScheduleRule();

        $calculator = new DailyAttendanceCalculator(
            $employee,
            $scheduleRule,
            new PermanentPolicy()
        );

        $leaveCredits = $this->resolveLeaveCredits();
        $result = $calculator->evaluate($workDay, $punches);
        $leaveCreditMinutes = $this->convertLeaveCreditsToMinutes($leaveCredits, $scheduleRule);
        $adjustedDeductionMinutes = max(0, $result->getDeductionMinutes() - $leaveCreditMinutes);
        $perMinuteRate = $result->getPerMinuteRate();
        $adjustedSalaryDeduction = round($perMinuteRate * $adjustedDeductionMinutes, 2);
        $adjustedDeductionFactor = $adjustedDeductionMinutes / DailyAttendanceCalculator::MONTHLY_BASELINE_MINUTES;

        $deductionFactor = $result->getDeductionFactor(DailyAttendanceCalculator::MONTHLY_BASELINE_MINUTES);

        $rows = [
            ['employee_ref', $employee->getEmployeeRef()],
            ['date', $date->toDateString()],
            ['calendar_day_type', $workDay->getDayType()],
            ['schedule_rule', (new \ReflectionClass($scheduleRule))->getShortName()],
            ['rule_table', $this->getRuleTableName($scheduleRule)],
            ['day_type', $result->getDayType()->value],
            ['half_day_flag', $result->getHalfDayFlag() ? 'true' : 'false'],
            ['leave_credits', $leaveCredits],
            ['leave_credit_minutes', $leaveCreditMinutes],
            ['tardiness_minutes', $result->getTardinessMinutes()],
            ['undertime_minutes', $result->getUndertimeMinutes()],
            ['deduction_minutes_before_leave', $result->getDeductionMinutes()],
            ['adjusted_deduction_minutes', $adjustedDeductionMinutes],
            ['per_minute_rate', round($perMinuteRate, 6)],
            ['deduction_factor_before_leave', round($deductionFactor, 6)],
            ['adjusted_deduction_factor', round($adjustedDeductionFactor, 6)],
            ['monthly_baseline_minutes', DailyAttendanceCalculator::MONTHLY_BASELINE_MINUTES],
            ['monthly_basic_salary', round($employee->getMonthlyBasicSalary(), 2)],
            ['salary_deduction', $adjustedSalaryDeduction],
            ['rule_trace', implode("\n", $result->getRuleTrace()->getEntries())],
        ];

        $this->newLine();
        $this->table(['field', 'value'], $rows);

        return self::SUCCESS;
    }

    private function resolveLeaveCredits(): float
    {
        $rawValue = $this->option('leave_credits');
        if ($rawValue === null || $rawValue === '') {
            return 0.0;
        }

        return (float) $rawValue;
    }

    private function getRuleTableName($scheduleRule): string
    {
        if (! method_exists($scheduleRule, 'getRuleTable')) {
            return 'unknown';
        }

        $ruleTable = $scheduleRule->getRuleTable();
        return method_exists($ruleTable, 'getLabel') ? $ruleTable->getLabel() : 'unknown';
    }

    private function convertLeaveCreditsToMinutes(float $leaveCredits, $scheduleRule): int
    {
        if ($leaveCredits <= 0.0) {
            return 0;
        }

        if (! method_exists($scheduleRule, 'getRuleTable')) {
            $this->warn('Leave credits were provided, but the current schedule rule does not expose a rule table. Leave credits are ignored.');
            return 0;
        }

        $ruleTable = $scheduleRule->getRuleTable();
        if (! method_exists($ruleTable, 'getMinutesForEquivalentDay')) {
            $this->warn('The current rule table cannot convert leave credits to minutes. Leave credits are ignored.');
            return 0;
        }

        return $ruleTable->getMinutesForEquivalentDay($leaveCredits);
    }

    private function resolveDayType(Carbon $date): string
    {
        $override = (string) $this->option('day_type');
        if ($override !== 'auto' && $override !== '') {
            return $override;
        }

        $dayOfWeek = (int) $date->dayOfWeekIso;
        if ($dayOfWeek === 6 || $dayOfWeek === 7) {
            return WorkDay::DAY_TYPE_WEEKEND;
        }

        return WorkDay::DAY_TYPE_WORKING;
    }

    private function resolveScheduleRule(): \App\Domain\Attendance\Contracts\ScheduleRule
    {
        $choice = strtolower((string) $this->option('schedule'));

        return match ($choice) {
            'friday_flexi', 'friday-flexi', 'flexi', 'friday' => new FridayFlexitimeRule(),
            'compressed', 'cww' => new CompressedWorkWeekRule(),
            default => new RegularScheduleRule(),
        };
    }

    private function resolveEmployee(): ?EmployeeSnapshot
    {
        $ref = (string) $this->option('employee_ref');

        if ($ref !== '') {
            $found = FakeEmployees::findByRef($ref);
            if ($found !== null) {
                return $found;
            }
        }

        $employees = [];
        foreach (FakeEmployees::all() as $emp) {
            $employees[] = [
                'employee_ref' => $emp->getEmployeeRef(),
                'employment_type_code' => $emp->getEmploymentTypeCode(),
                'muslim_flag' => $emp->getMuslimFlag(),
                'monthly_basic_salary' => round($emp->getMonthlyBasicSalary(), 2),
            ];
        }

        $this->newLine();
        $this->line(json_encode(['available_employees' => $employees], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->components->warn('Provide --employee_ref="<REF>" to select an employee.');

        return null;
    }

    /**
     * @return Punch[]
     */
    private function resolvePunches(EmployeeSnapshot $employee, WorkDay $workDay): array
    {
        $rawPunches = $this->option('punches');
        if ($rawPunches !== null) {
            $times = $rawPunches === ''
                ? []
                : explode(',', (string) $rawPunches);
            $date = $workDay->getDate();
            $punches = [];
            foreach ($times as $time) {
                $trimmed = trim((string) $time);
                if ($trimmed === '') {
                    continue;
                }
                [$h, $m] = explode(':', $trimmed);
                $punches[] = new Punch($date->copy()->setTime((int) $h, (int) $m));
            }
            return $punches;
        }

        return FakeEmployees::defaultPunchesFor($employee->getEmployeeRef(), $workDay);
    }
}