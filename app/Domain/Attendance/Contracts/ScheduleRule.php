<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Contracts;

use App\Domain\Attendance\Models\EmployeeSnapshot;
use App\Domain\Attendance\Models\ScheduleWindows;
use App\Domain\Attendance\Support\WorkDay;

interface ScheduleRule
{
    public function expectedWorkWindows(WorkDay $day, ?EmployeeSnapshot $employee = null): ScheduleWindows;
}
