<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Models;

class ScheduleWindows
{
    /** @var WorkSession[] */
    private array $sessions;

    /**
     * @param WorkSession[] $sessions
     */
    public function __construct(array $sessions = [])
    {
        $this->sessions = $sessions;
    }

    /**
     * @param WorkSession[] $sessions
     */
    protected function setSessions(array $sessions): void
    {
        $this->sessions = $sessions;
    }

    /**
     * @return WorkSession[]
     */
    public function getSessions(): array
    {
        return $this->sessions;
    }
}
