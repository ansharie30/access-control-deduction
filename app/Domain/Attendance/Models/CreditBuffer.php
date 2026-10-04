<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Models;

class CreditBuffer
{
    public function __construct(
        protected int $earnedMinutes = 0,
        protected int $usedMinutes = 0,
    ) {
    }

    protected function setEarnedMinutes(int $minutes): void
    {
        $this->earnedMinutes = $minutes;
    }

    protected function setUsedMinutes(int $minutes): void
    {
        $this->usedMinutes = $minutes;
    }

    public function getEarnedMinutes(): int
    {
        return $this->earnedMinutes;
    }

    public function getUsedMinutes(): int
    {
        return $this->usedMinutes;
    }

    public function getRemainingMinutes(): int
    {
        return max(0, $this->earnedMinutes - $this->usedMinutes);
    }
}
