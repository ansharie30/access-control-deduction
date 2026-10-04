<?php

declare(strict_types=1);

namespace App\Domain\Attendance\RuleTables;

final class EightHourWorkdayRuleTable implements RuleTable
{
    private const TABLE_LABEL = '8-hour workday';

    private const HOURS = [
        0 => 0.0,
        1 => 0.125,
        2 => 0.25,
        3 => 0.375,
        4 => 0.5,
        5 => 0.625,
        6 => 0.75,
        7 => 0.875,
        8 => 1.0,
    ];

    private const MINUTES = [
        0 => 0.0,
        1 => 0.002,
        2 => 0.004,
        3 => 0.006,
        4 => 0.008,
        5 => 0.010,
        6 => 0.012,
        7 => 0.015,
        8 => 0.017,
        9 => 0.019,
        10 => 0.021,
        11 => 0.023,
        12 => 0.025,
        13 => 0.027,
        14 => 0.029,
        15 => 0.031,
        16 => 0.033,
        17 => 0.035,
        18 => 0.037,
        19 => 0.040,
        20 => 0.042,
        21 => 0.044,
        22 => 0.046,
        23 => 0.048,
        24 => 0.050,
        25 => 0.052,
        26 => 0.054,
        27 => 0.056,
        28 => 0.058,
        29 => 0.060,
        30 => 0.062,
        31 => 0.065,
        32 => 0.067,
        33 => 0.069,
        34 => 0.071,
        35 => 0.073,
        36 => 0.075,
        37 => 0.077,
        38 => 0.079,
        39 => 0.081,
        40 => 0.083,
        41 => 0.085,
        42 => 0.087,
        43 => 0.090,
        44 => 0.092,
        45 => 0.094,
        46 => 0.096,
        47 => 0.098,
        48 => 0.100,
        49 => 0.102,
        50 => 0.104,
        51 => 0.106,
        52 => 0.108,
        53 => 0.110,
        54 => 0.112,
        55 => 0.115,
        56 => 0.117,
        57 => 0.119,
        58 => 0.121,
        59 => 0.123,
        60 => 0.125,
    ];

    public function getLabel(): string
    {
        return self::TABLE_LABEL;
    }

    public function getEquivalentDayForHours(int $hours): float
    {
        if (! $this->hasEquivalentDayForHours($hours)) {
            throw new \InvalidArgumentException("No equivalent day value for {$hours} hour(s) in {$this->getLabel()}.");
        }

        return self::HOURS[$hours];
    }

    public function getEquivalentDayForMinutes(int $minutes): float
    {
        if (! $this->hasEquivalentDayForMinutes($minutes)) {
            throw new \InvalidArgumentException("No equivalent day value for {$minutes} minute(s) in {$this->getLabel()}.");
        }

        return self::MINUTES[$minutes];
    }

    public function getEquivalentDayForDuration(int $hours, int $minutes): float
    {
        if ($hours < 0 || $minutes < 0) {
            throw new \InvalidArgumentException('Hours and minutes must be non-negative.');
        }

        if (! $this->hasEquivalentDayForHours($hours) || ! $this->hasEquivalentDayForMinutes($minutes)) {
            throw new \InvalidArgumentException(
                sprintf('No equivalent day value for %d hour(s) and %d minute(s) in %s.', $hours, $minutes, $this->getLabel())
            );
        }

        return self::HOURS[$hours] + self::MINUTES[$minutes];
    }

    public function getMinutesPerDay(): int
    {
        return 8 * 60;
    }

    public function getMinutesForEquivalentDay(float $equivalentDay): int
    {
        if ($equivalentDay <= 0.0) {
            return 0;
        }

        $fullDays = (int) floor($equivalentDay);
        $remainingDay = $equivalentDay - $fullDays;
        $minutes = $fullDays * $this->getMinutesPerDay();

        if ($remainingDay <= 0.0) {
            return $minutes;
        }

        $minutes += $this->getMinutesForFractionalDay($remainingDay);

        return $minutes;
    }

    private function getMinutesForFractionalDay(float $fractionalDay): int
    {
        if ($fractionalDay <= 0.0) {
            return 0;
        }

        $hour = 0;
        for ($h = array_key_last(self::HOURS); $h >= 1; $h--) {
            if (self::HOURS[$h] <= $fractionalDay + 1e-9) {
                $hour = $h;
                break;
            }
        }

        $remaining = $fractionalDay - self::HOURS[$hour];
        if ($remaining <= 0.0) {
            return $hour * 60;
        }

        $closestMinute = 0;
        $bestDiff = INF;
        foreach (self::MINUTES as $minute => $value) {
            $diff = abs($value - $remaining);
            if ($diff < $bestDiff) {
                $bestDiff = $diff;
                $closestMinute = $minute;
            }
        }

        return $hour * 60 + $closestMinute;
    }

    public function hasEquivalentDayForHours(int $hours): bool
    {
        return array_key_exists($hours, self::HOURS);
    }

    public function hasEquivalentDayForMinutes(int $minutes): bool
    {
        return array_key_exists($minutes, self::MINUTES);
    }
}
