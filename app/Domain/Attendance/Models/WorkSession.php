<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Models;

use Carbon\Carbon;

class WorkSession
{
    public function __construct(
        private Carbon $sessionIn,
        private ?Carbon $sessionOut = null
    ) {
    }

    protected function setSessionIn(Carbon $sessionIn): void
    {
        $this->sessionIn = $sessionIn;
    }

    protected function setSessionOut(?Carbon $sessionOut): void
    {
        $this->sessionOut = $sessionOut;
    }

    public function getSessionIn(): Carbon
    {
        return $this->sessionIn;
    }

    public function getSessionOut(): ?Carbon
    {
        return $this->sessionOut;
    }

    /**
     * Minute-accurate duration. Zero for an open session with no clock-out yet.
     */
    public function durationMinutes(): int
    {
        if ($this->sessionOut === null) {
            return 0;
        }

        return (int) $this->sessionIn->diffInMinutes($this->sessionOut, true);
    }
}
