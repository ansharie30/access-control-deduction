<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Models;

class RuleTrace
{
    /** @var string[] */
    private array $entries;

    /**
     * @param string[] $entries
     */
    public function __construct(array $entries = [])
    {
        $this->entries = $entries;
    }

    protected function setEntries(array $entries): void
    {
        $this->entries = $entries;
    }

    /**
     * @return string[]
     */
    public function getEntries(): array
    {
        return $this->entries;
    }
}
