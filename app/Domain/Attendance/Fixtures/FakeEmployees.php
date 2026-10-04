<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Fixtures;

use App\Domain\Attendance\Models\EmployeeSnapshot;
use App\Domain\Attendance\Models\Punch;
use App\Domain\Attendance\Support\WorkDay;

class FakeEmployees
{
    /**
     * Plain hardcoded employee rows (like a relational table).
     * Each row carries a single default sample punch list under 'punches',
     * expressed as HH:MM strings. You are free to edit these manually.
     *
     * Employees 0001–0002 are general-purpose samples.
     * Employees A1–C1 are pre-seeded to match ACM_Goal.md scenarios so they
     * can be tested quickly via:
     *   php artisan attendance:calculate --employee_ref=<REF> [--date=...]
     *
     * Scenario refs that are DAY-sensitive (B1, B2):
     *   - B1 (Friday flexi): pair with --date=<any-Friday>, e.g. 2026-07-31
     *   - B2 (weekend/holiday): pair with --date=<Sat|Sun> and/or --day_type=holiday
     *
     * @return array<int, array{
     *     employee_ref: string,
     *     employment_type_code: string,
     *     muslim_flag: bool,
     *     monthly_basic_salary: float,
     *     punches: list<string>
     * }>
     */
    public static function rows(): array
    {
        return [
            [
                'employee_ref'           => 'EMP-2026-0001',
                'employment_type_code'   => 'PERMANENT',
                'muslim_flag'            => true,
                'monthly_basic_salary'   => 33000.00,
                'punches'                => ['08:00', '12:00', '13:00', '17:00'],
            ],
            [
                'employee_ref'           => 'EMP-2026-0002',
                'employment_type_code'   => 'PERMANENT',
                'muslim_flag'            => false,
                'monthly_basic_salary'   => 51832.00,
                'punches'                => ['8:00', '8:35', '12:30', '13:00', '17:00'],
            ],

            
        ];
    }

    /**
     * @return EmployeeSnapshot[]
     */
    public static function all(): array
    {
        return array_map(
            static fn (array $row): EmployeeSnapshot => self::rowToSnapshot($row),
            self::rows()
        );
    }

    public static function findByRef(string $employeeRef): ?EmployeeSnapshot
    {
        foreach (self::rows() as $row) {
            if ($row['employee_ref'] === $employeeRef) {
                return self::rowToSnapshot($row);
            }
        }

        return null;
    }

    /**
     * Hydrated Punch[] using the default sample punches stored on the employee row.
     *
     * @return Punch[]
     */
    public static function defaultPunchesFor(string $employeeRef, WorkDay $day): array
    {
        foreach (self::rows() as $row) {
            if ($row['employee_ref'] !== $employeeRef) {
                continue;
            }

            $date = $day->getDate();
            $punches = [];
            foreach ($row['punches'] as $timeStr) {
                [$h, $m] = explode(':', $timeStr);
                $punches[] = new Punch($date->copy()->setTime((int) $h, (int) $m));
            }

            return $punches;
        }

        return [];
    }

    /**
     * @param array{
     *     employee_ref: string,
     *     employment_type_code: string,
     *     muslim_flag: bool,
     *     monthly_basic_salary: float,
     *     punches: list<string>
     * } $row
     */
    private static function rowToSnapshot(array $row): EmployeeSnapshot
    {
        return new EmployeeSnapshot(
            employeeRef: $row['employee_ref'],
            employmentTypeCode: $row['employment_type_code'],
            muslimFlag: $row['muslim_flag'],
            monthlyBasicSalary: $row['monthly_basic_salary'],
        );
    }
}
