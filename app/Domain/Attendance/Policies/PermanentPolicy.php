<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Policies;

use App\Domain\Attendance\Contracts\EmploymentTypePolicy;
use App\Domain\Attendance\Models\DailyAttendanceResult;
use App\Domain\Attendance\Models\EmployeeSnapshot;

/**
 * Permanent employees: the computed deduction applies as-is, no special treatment.
 */
class PermanentPolicy implements EmploymentTypePolicy
{
    public function applyPolicy(EmployeeSnapshot $employee, DailyAttendanceResult $result): DailyAttendanceResult
    {
        return $result;
    }
}
