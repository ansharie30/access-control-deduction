<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Models;

class PairedDay
{
    /** @var WorkSession[] */
    private array $workSessions;

    /**
     * @param WorkSession[] $workSessions
     */
    public function __construct(array $workSessions = [])
    {
        $this->workSessions = $workSessions;
    }

    /**
     * @param WorkSession[] $workSessions
     */
    protected function setWorkSessions(array $workSessions): void
    {
        $this->workSessions = $workSessions;
    }

    /**
     * @return WorkSession[]
     */
    public function getWorkSessions(): array
    {
        return $this->workSessions;
    }
}
