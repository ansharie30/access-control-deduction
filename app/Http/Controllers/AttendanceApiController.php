<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Attendance\DailyAttendanceCalculator;
use App\Domain\Attendance\Models\EmployeeSnapshot;
use App\Domain\Attendance\Models\Punch;
use App\Domain\Attendance\Policies\PermanentPolicy;
use App\Domain\Attendance\Contracts\ScheduleRule;
use App\Domain\Attendance\Rules\CompressedWorkWeekRule;
use App\Domain\Attendance\Rules\FridayFlexitimeRule;
use App\Domain\Attendance\Rules\RegularScheduleRule;
use App\Domain\Attendance\Support\WorkDay;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Http;

class AttendanceApiController extends Controller
{
    private const DEFAULT_API_URL = '';
    private const DEFAULT_API_TOKEN = '';

    private ?string $fetchError = null;
    

    public function test(): JsonResponse
    {
        return response()->json(['value' => random_int(1, 100)]);
    }

    public function calculate(Request $request): JsonResponse
    {
        $employeePin = trim((string) $request->query('employee_pin', ''));
        if ($employeePin === '') {
            return response()->json(['error' => 'employee_pin is required'], 400);
        }

        $salary = (float) $request->query('salary', 33000);
        $employmentType = (string) $request->query('employment_type', 'permanent');
        $muslimFlag = $this->parseBoolean($request->query('muslim_flag', 'true'));

        $employeeRef = $employeePin;
        $employee = new EmployeeSnapshot(
            $employeeRef,
            $employmentType,
            $muslimFlag,
            $salary,
        );

        $dateStr = trim((string) $request->query('date', ''));
        $date = $dateStr !== '' ? Carbon::parse($dateStr) : Carbon::today();
        $dayType = $this->resolveDayType((string) $request->query('day_type', 'auto'), $date);
        $workDay = new WorkDay($date, $dayType);

        $apiUrl = trim((string) $request->query('api_url')) ?: env('ATTENDANCE_API_URL', 'http://172.16.114.100:8000/api/v1/attendance');
        $limit = (int) $request->query('limit', 100);
        if ($limit < 1) {
            $limit = 100;
        }

        $records = $this->fetchAttendanceRecords($apiUrl, $date, $limit, $request);
        if ($records === null) {
            return response()->json([
                'error' => 'Failed to fetch attendance records from API',
                'details' => $this->fetchError,
            ], 502);
        }

        $punches = $this->resolvePunchesFromApi($records, $employeePin, $workDay);

        $scheduleRule = $this->resolveScheduleRule((string) $request->query('schedule', 'regular'));
        $calculator = new DailyAttendanceCalculator(
            $employee,
            $scheduleRule,
            new PermanentPolicy()
        );

        $leaveCredits = $this->resolveLeaveCredits($request);
        $result = $calculator->evaluate($workDay, $punches, $leaveCredits);

        $leaveCreditMinutes = $result->getLeaveCreditMinutes();
        $adjustedDeductionMinutes = $result->getAdjustedMinutes();
        $adjustedSalaryDeduction = $result->getSalaryDeduction();
        $adjustedDeductionFactor = $adjustedDeductionMinutes / DailyAttendanceCalculator::MONTHLY_BASELINE_MINUTES;

        return response()->json([
            'employee_pin' => $employeePin,
            'employee_ref' => $employee->getEmployeeRef(),
            'date' => $workDay->getDate()->toDateString(),
            'calendar_day_type' => $workDay->getDayType(),
            'schedule_rule' => (new \ReflectionClass($scheduleRule))->getShortName(),
            'rule_table' => $this->getRuleTableName($scheduleRule),
            'day_type' => $result->getDayType()->value,
            'half_day_flag' => $result->getHalfDayFlag(),
            'leave_credits' => $leaveCredits,
            'leave_credit_minutes' => $leaveCreditMinutes,
            'tardiness_minutes' => $result->getTardinessMinutes(),
            'undertime_minutes' => $result->getUndertimeMinutes(),
            'deduction_minutes_before_leave' => $result->getDeductionMinutes(),
            'adjusted_deduction_minutes' => $adjustedDeductionMinutes,
            'per_minute_rate' => round($result->getPerMinuteRate(), 6),
            'deduction_factor_before_leave' => round($result->getDeductionFactor(DailyAttendanceCalculator::MONTHLY_BASELINE_MINUTES), 6),
            'adjusted_deduction_factor' => round($adjustedDeductionFactor, 6),
            'monthly_baseline_minutes' => DailyAttendanceCalculator::MONTHLY_BASELINE_MINUTES,
            'monthly_basic_salary' => round($employee->getMonthlyBasicSalary(), 2),
            'salary_deduction' => $adjustedSalaryDeduction,
            'rule_trace' => implode("\n", $result->getRuleTrace()->getEntries()),
        ]);
    }

    private function fetchAttendanceRecords(string $apiUrl, Carbon $date, int $limit, Request $request): ?array
    {
        $http = Http::retry(3, 100);
        $apiToken = trim((string) $request->query('api_token', '')) ?: env('ATTENDANCE_API_TOKEN', '');
        $apiUsername = trim((string) $request->query('api_username', '')) ?: env('ATTENDANCE_API_USERNAME', '');
        $apiPassword = trim((string) $request->query('api_password', '')) ?: env('ATTENDANCE_API_PASSWORD', '');

        if ($apiToken !== '') {
            $http = $http->withToken($apiToken);
        }

        if ($apiUsername !== '' || $apiPassword !== '') {
            $http = $http->withBasicAuth($apiUsername, $apiPassword);
        }

        try {
            $response = $http->get($apiUrl, [
                'since' => $date->toDateString(),
                'limit' => $limit,
            ]);
        } catch (\Throwable $exception) {
            $this->fetchError = $exception->getMessage();
            return null;
        }

        if ($response->failed()) {
            $body = $response->body();
            $this->fetchError = trim($body) !== '' ? $body : 'HTTP '.$response->status();
            return null;
        }

        $payload = $response->json();
        if (!is_array($payload) || !isset($payload['records']) || !is_array($payload['records'])) {
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

    private function resolveDayType(string $override, Carbon $date): string
    {
        if ($override !== 'auto' && $override !== '') {
            return $override;
        }

        $dayOfWeek = (int) $date->dayOfWeekIso;
        if ($dayOfWeek === 6 || $dayOfWeek === 7) {
            return WorkDay::DAY_TYPE_WEEKEND;
        }

        return WorkDay::DAY_TYPE_WORKING;
    }

    private function resolveScheduleRule(string $choice): ScheduleRule
    {
        $choice = strtolower($choice);

        return match ($choice) {
            'friday_flexi', 'friday-flexi', 'flexi', 'friday' => new FridayFlexitimeRule(),
            'compressed', 'cww' => new CompressedWorkWeekRule(),
            default => new RegularScheduleRule(),
        };
    }

    private function resolveLeaveCredits(Request $request): float
    {
        $rawValue = $request->query('leave_credits', '0');
        if ($rawValue === null || $rawValue === '') {
            return 0.0;
        }

        return (float) $rawValue;
    }

    private function getRuleTableName(ScheduleRule $scheduleRule): string
    {
        if (! method_exists($scheduleRule, 'getRuleTable')) {
            return 'unknown';
        }

        $ruleTable = $scheduleRule->getRuleTable();
        return method_exists($ruleTable, 'getLabel') ? $ruleTable->getLabel() : 'unknown';
    }

    private function parseBoolean(string $value): bool
    {
        return in_array(strtolower($value), ['1', 'true', 'yes', 'y'], true);
    }
}
