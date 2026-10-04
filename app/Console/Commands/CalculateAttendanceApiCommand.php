<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Attendance\DailyAttendanceCalculator;
use App\Domain\Attendance\Models\EmployeeSnapshot;
use App\Domain\Attendance\Models\Punch;
use App\Domain\Attendance\Policies\PermanentPolicy;
use App\Domain\Attendance\Rules\CompressedWorkWeekRule;
use App\Domain\Attendance\Rules\FridayFlexitimeRule;
use App\Domain\Attendance\Rules\RegularScheduleRule;
use App\Domain\Attendance\Support\WorkDay;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class CalculateAttendanceApiCommand extends Command
{

    protected $signature = 'attendance:api
        {--employee_pin= : Employee pin to filter (required)}
        {--date= : Date to evaluate (Y-m-d, defaults to today)}
        {--limit=100 : Attendance API limit}
        {--api_url= : Attendance API base URL (defaults to configured URL)}
        {--api_token= : Bearer token to authenticate with the attendance API}
        {--api_username= : Basic auth username for the attendance API}
        {--api_password= : Basic auth password for the attendance API}
        {--salary=33000 : Monthly basic salary (defaults to 33000 if not provided)}
        {--employment_type=permanent : Employment type code (defaults to "permanent" if not provided)}
        {--muslim_flag=true : Muslim flag (defaults to true if not provided)}
        {--schedule=regular : Schedule rule: regular|friday_flexi|compressed}
        {--leave_credits=0 : Leave credits in equivalent workdays (defaults to 0)}
        {--day_type=auto : Override day type: auto|working|weekend|holiday|special_non_working}';

    protected $description = 'Run the attendance deduction calculator using punches from an external attendance API';

    public function handle(): int
    {
        $employeePin = trim((string) $this->option('employee_pin'));
        if ($employeePin === '') {
            $this->error('The --employee_pin option is required.');
            return self::FAILURE;
        }

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

        $employeeRef = $employeePin;
        $employee = new EmployeeSnapshot(
            $employeeRef,
            $employmentType,
            $muslimFlag,
            $salary,
        );

        $dateStr = trim((string) $this->option('date'));
        $date = $dateStr !== '' ? Carbon::parse($dateStr) : Carbon::today();
        $dayType = $this->resolveDayType($date);
        $workDay = new WorkDay($date, $dayType);

        $apiUrl = trim((string) $this->option('api_url')) ?: env('ATTENDANCE_API_URL', 'http://172.16.114.100:8000/api/v1/attendance');
        $limit = (int) $this->option('limit');
        if ($limit < 1) {
            $limit = 100;
        }

        $records = $this->fetchAttendanceRecords($apiUrl, $date, $limit);
        if ($records === null) {
            return self::FAILURE;
        }

        $punches = $this->resolvePunchesFromApi($records, $employeePin, $workDay);
        if (count($punches) === 0) {
            $this->warn('No attendance punches found for employee_pin='.$employeePin.' on '.$workDay->getDate()->toDateString());
        }

        $scheduleRule = $this->resolveScheduleRule();
        $calculator = new DailyAttendanceCalculator(
            $employee,
            $scheduleRule,
            new PermanentPolicy()
        );

        $leaveCredits = $this->resolveLeaveCredits();
        $result = $calculator->evaluate($workDay, $punches, $leaveCredits);
        $leaveCreditMinutes = $result->getLeaveCreditMinutes();
        $adjustedDeductionMinutes = $result->getAdjustedMinutes();
        $adjustedSalaryDeduction = $result->getSalaryDeduction();
        $adjustedDeductionFactor = $adjustedDeductionMinutes / DailyAttendanceCalculator::MONTHLY_BASELINE_MINUTES;

        $deductionFactor = $result->getDeductionFactor(DailyAttendanceCalculator::MONTHLY_BASELINE_MINUTES);

        $rows = [
            ['employee_pin', $employeePin],
            ['employee_ref', $employee->getEmployeeRef()],
            ['date', $workDay->getDate()->toDateString()],
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
            ['per_minute_rate', round($result->getPerMinuteRate(), 6)],
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

    private function fetchAttendanceRecords(string $apiUrl, Carbon $date, int $limit): ?array
    {
        $request = Http::retry(3, 100);

        $apiToken = trim((string) $this->option('api_token')) ?: env('ATTENDANCE_API_TOKEN', '');

        $apiUsername = trim((string) $this->option('api_username')) ?: env('ATTENDANCE_API_USERNAME', '');
        $apiPassword = trim((string) $this->option('api_password')) ?: env('ATTENDANCE_API_PASSWORD', '');

        if ($apiToken !== '') {
            $request = $request->withToken($apiToken);
        }

        if ($apiUsername !== '' || $apiPassword !== '') {
            $request = $request->withBasicAuth($apiUsername, $apiPassword);
        }

        try {
            $response = $request->get($apiUrl, [
                'since' => $date->toDateString(),
                'limit' => $limit,
            ]);
        } catch (\Throwable $exception) {
            $this->error('Failed to fetch attendance records from API: '.$exception->getMessage());
            return null;
        }

        if ($response->failed()) {
            $message = $response->body() ?: 'HTTP '.$response->status();
            $this->error('Failed to fetch attendance records from API: '.$message);
            return null;
        }

        $payload = $response->json();
        if (!is_array($payload) || !isset($payload['records']) || !is_array($payload['records'])) {
            $this->error('Unexpected attendance API response structure.');
            return null;
        }

        return $payload['records'];
    }

    /**
     * @param array<int, mixed> $records
     * @return Punch[]
     */
    private function resolvePunchesFromApi(array $records, string $employeePin, WorkDay $workDay): array
    {
        $dateString = $workDay->getDate()->toDateString();
        $punches = [];

        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }

            $recordPin = isset($record['employeePin']) ? (string) $record['employeePin'] : '';
            if ($recordPin !== $employeePin) {
                continue;
            }

            if (!isset($record['timestamp'])) {
                continue;
            }

            try {
                $timestamp = Carbon::parse((string) $record['timestamp']);
            } catch (\Throwable $exception) {
                continue;
            }

            $timestamp = Carbon::createFromFormat(
                'Y-m-d H:i:s',
                sprintf('%s %s', $dateString, $timestamp->format('H:i:s'))
            );

            if ($timestamp->toDateString() !== $dateString) {
                continue;
            }

            $punches[] = new Punch($timestamp);
        }

        usort($punches, static fn (Punch $first, Punch $second): int => $first->getTimeStamp()->getTimestamp() <=> $second->getTimeStamp()->getTimestamp());

        return $punches;
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
            'regular', 'standard', '' => new RegularScheduleRule(),
            default => new RegularScheduleRule(),
        };
    }
}
