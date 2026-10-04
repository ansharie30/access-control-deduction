<?php

declare(strict_types=1);

namespace App\Domain\Attendance\RuleTables;

final class TenHourWorkdayRuleTable implements RuleTable
{
    private const TABLE_LABEL = '10-hour workday';

    private const HOURS = [
        0 => 0.0,
        1 => 0.10,
        2 => 0.20,
        3 => 0.30,
        4 => 0.40,
        5 => 0.50,
        6 => 0.60,
        7 => 0.70,
        8 => 0.80,
        9 => 0.90,
        10 => 1.0,
    ];

    private const MINUTES = [
        0 => 0.0,
        1 => 0.002,
        2 => 0.003,
        3 => 0.005,
        4 => 0.007,
        5 => 0.008,
        6 => 0.010,
        7 => 0.012,
        8 => 0.013,
        9 => 0.015,
        10 => 0.017,
        11 => 0.018,
        12 => 0.020,
        13 => 0.022,
        14 => 0.023,
        15 => 0.025,
        16 => 0.027,
        17 => 0.028,
        18 => 0.030,
        19 => 0.032,
        20 => 0.033,
        21 => 0.035,
        22 => 0.037,
        23 => 0.038,
        24 => 0.040,
        25 => 0.042,
        26 => 0.043,
        27 => 0.045,
        28 => 0.047,
        29 => 0.048,
        30 => 0.050,
        31 => 0.052,
        32 => 0.053,
        33 => 0.055,
        34 => 0.057,
        35 => 0.058,
        36 => 0.060,
        37 => 0.062,
        38 => 0.063,
        39 => 0.065,
        40 => 0.067,
        41 => 0.068,
        42 => 0.070,
        43 => 0.072,
        44 => 0.073,
        45 => 0.075,
        46 => 0.077,
        47 => 0.078,
        48 => 0.080,
        49 => 0.082,
        50 => 0.083,
        51 => 0.085,
        52 => 0.087,
        53 => 0.088,
        54 => 0.090,
        55 => 0.092,
        56 => 0.093,
        57 => 0.095,
        58 => 0.097,
        59 => 0.098,
        60 => 0.100,
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
        return 10 * 60;
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
