<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Models;

/**
 * Not on the original diagram, but added so a half-day absence can be reported
 * distinctly from ordinary tardiness/undertime and from a full-day absence,
 * per this ticket's acceptance criteria.
 */
enum AttendanceDayType: string
{
    /** No tardiness, no undertime, nothing missed. */
    case PERFECT = 'perfect';

    /** Ordinary minute-level tardiness/undertime; no whole session missed. */
    case PARTIAL = 'partial';

    /** Exactly one whole expected session (AM or PM) has zero punches. */
    case HALF_DAY = 'half_day';

    /** Every expected session for the day has zero punches. */
    case FULL_DAY_ABSENT = 'full_day_absent';
}
