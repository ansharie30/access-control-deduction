<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Contracts;

use App\Domain\Attendance\Models\DailyAttendanceResult;
use App\Domain\Attendance\Models\EmployeeSnapshot;

/**
 * NOTE: deviates slightly from the diagram. The diagram shows
 * applyPolicy(employee) : DailyAttendanceResult — built from scratch.
 * Here it instead adjusts an already-computed result, so the deduction
 * math stays in one place (DailyAttendanceCalculator) and policies only
 * apply employment-type-specific rules on top (e.g. zeroing deductions
 * for payroll-excluded staff).
 */
interface EmploymentTypePolicy
{
    public function applyPolicy(EmployeeSnapshot $employee, DailyAttendanceResult $result): DailyAttendanceResult;
}
