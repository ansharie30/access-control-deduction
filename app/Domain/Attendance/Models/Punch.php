<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Models;

use Carbon\Carbon;

class Punch
{
    public function __construct(private Carbon $timestamp)
    {
    }

    public function getTimeStamp(): Carbon
    {
        return $this->timestamp;
    }
}
