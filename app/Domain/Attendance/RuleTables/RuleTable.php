<?php

declare(strict_types=1);

namespace App\Domain\Attendance\RuleTables;

interface RuleTable
{
    public function getLabel(): string;

    public function getEquivalentDayForHours(int $hours): float;

    public function getEquivalentDayForMinutes(int $minutes): float;

    public function getEquivalentDayForDuration(int $hours, int $minutes): float;

    public function getMinutesPerDay(): int;

    public function getMinutesForEquivalentDay(float $equivalentDay): int;

    public function hasEquivalentDayForHours(int $hours): bool;

    public function hasEquivalentDayForMinutes(int $minutes): bool;
}
