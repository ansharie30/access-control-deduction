<?php

declare(strict_types=1);

namespace App\Domain\Attendance;

use App\Domain\Attendance\Contracts\EmploymentTypePolicy;
use App\Domain\Attendance\Contracts\ScheduleRule;
use App\Domain\Attendance\Models\AttendanceDayType;
use App\Domain\Attendance\Models\DailyAttendanceResult;
use App\Domain\Attendance\Models\EmployeeSnapshot;
use App\Domain\Attendance\Models\PairedDay;
use App\Domain\Attendance\Models\Punch;
use App\Domain\Attendance\Models\RuleTrace;
use App\Domain\Attendance\Models\WorkSession;
use App\Domain\Attendance\Policies\PermanentPolicy;
use App\Domain\Attendance\Rules\RegularScheduleRule;
use App\Domain\Attendance\Support\WorkDay;
use Carbon\Carbon;

class DailyAttendanceCalculator
{
    /**
     * Monthly baseline: 22 working days x 8 hours x 60 minutes = 10,560 minutes.
     * Adjust if your organization's baseline differs.
     */
    public const MONTHLY_BASELINE_MINUTES = 10560;

    public const LUNCH_AM_OUT_START = '12:00';
    public const LUNCH_AM_OUT_END = '12:30';
    public const LUNCH_PM_IN_START = '12:30';
    public const LUNCH_PM_IN_END = '13:00';

    // Deduplication window: punches within this many minutes of each other are treated as duplicates.
    public const DEDUP_WINDOW_MINUTES = 2;

    /** @var Punch[] */
    private array $punches = [];

    /** @var ScheduleRule[] */
    private array $scheduleRules = [];

    public function __construct(
        private EmployeeSnapshot $employee,
        private ?ScheduleRule $scheduleRule = null,
        private ?EmploymentTypePolicy $employmentPolicy = null,
    ) {
        $this->scheduleRule = $this->scheduleRule ?? new RegularScheduleRule();
        $this->employmentPolicy = $this->employmentPolicy ?? new PermanentPolicy();
        $this->scheduleRules = [$this->scheduleRule];
    }

    public function getEmployee(): EmployeeSnapshot
    {
        return $this->employee;
    }

    /**
     * @return Punch[]
     */
    public function getPunches(): array
    {
        return $this->punches;
    }

    /**
     * @return ScheduleRule[]
     */
    public function getScheduleRules(): array
    {
        return $this->scheduleRules;
    }

    /**
     * @param Punch[] $punches
     */
    public function evaluate(WorkDay $day, array $punches, float $leaveCredits = 0.0): DailyAttendanceResult
    {
        $this->punches = $punches;
        $traceEntries = [];

        if (! $day->isWorkingDay()) {
            $traceEntries[] = "Day type {$day->getDayType()}: skipping deduction computation";
            $paired = $this->pairedPunches($punches, $traceEntries);

            [$adjustedMinutes, $leaveCreditMinutes] = $this->applyLeaveCredits($leaveCredits, 0, $traceEntries);

            $result = new DailyAttendanceResult(
                paired: $paired,
                tardinessMinutes: 0,
                undertimeMinutes: 0,
                deductionMinutes: 0,
                dayType: AttendanceDayType::PERFECT,
                perMinuteRate: 0.0,
                salaryDeduction: 0.0,
                lunchWindowCompliant: true,
                excussedAbsence: false,
                adjustedMinutes: $adjustedMinutes,
                leaveCreditMinutes: $leaveCreditMinutes,
                ruleTrace: new RuleTrace($traceEntries),
            );

            return $this->employmentPolicy->applyPolicy($this->employee, $result);
        }

        $paired = $this->pairedPunches($punches, $traceEntries);
        $expectedSessions = $this->scheduleRule
            ->expectedWorkWindows($day, $this->employee)
            ->getSessions();
        $actualSessions = $paired->getWorkSessions();

        $actualSessions = $this->resolveAmbiguousSinglePunch($day, $actualSessions, $traceEntries);

        $tardinessMinutes = 0;
        $undertimeMinutes = 0;
        $missedSessionCount = 0;
        $totalExpectedSessions = count($expectedSessions);
        $usedActualSessionKeys = [];

        foreach ($expectedSessions as $idx => $expected) {
            $actual = $this->findOverlappingSession($expected, $actualSessions, $usedActualSessionKeys);
            $windowLabel = $idx === 0 ? 'AM' : 'PM';

            if ($actual === null || $actual->getSessionOut() === null) {
                $missed = $expected->durationMinutes();
                $undertimeMinutes += $missed;
                $missedSessionCount++;
                $traceEntries[] = "{$windowLabel} session missed: +{$missed} min undertime";
                continue;
            }

            if ($actual->getSessionIn()->greaterThan($expected->getSessionIn())) {
                $late = (int) $expected->getSessionIn()
                    ->diffInMinutes($actual->getSessionIn(), true);
                $tardinessMinutes += $late;
                $traceEntries[] = "Session {$idx} ({$windowLabel}): {$late} min tardiness";
            }

            if ($actual->getSessionOut()->lessThan($expected->getSessionOut())) {
                $early = (int) $actual->getSessionOut()
                    ->diffInMinutes($expected->getSessionOut(), true);
                $undertimeMinutes += $early;
                $traceEntries[] = "Session {$idx} ({$windowLabel}): {$early} min undertime";
            }
        }

        $lunchWindowCompliant = $this->checkLunchWindowCompliance($day, $actualSessions, $tardinessMinutes, $undertimeMinutes, $traceEntries);

        $deductionMinutes = $tardinessMinutes + $undertimeMinutes;
        $adjustedMinutes = 0;
        $excussedAbsence = false;

        [$adjustedMinutes, $leaveCreditMinutes] = $this->applyLeaveCredits($leaveCredits, $deductionMinutes, $traceEntries);

        $perMinuteRate = self::MONTHLY_BASELINE_MINUTES > 0
            ? round($this->employee->getMonthlyBasicSalary() / self::MONTHLY_BASELINE_MINUTES, 2)
            : 0.0;
        $salaryDeduction = $perMinuteRate * $adjustedMinutes;
        $traceEntries[] = sprintf(
            'Per-minute rate (rounded to 2 decimals): salary ₱%.2f ÷ %d min baseline = ₱%.2f',
            $this->employee->getMonthlyBasicSalary(),
            self::MONTHLY_BASELINE_MINUTES,
            $perMinuteRate
        );
        if ($adjustedMinutes !== 0) {
            $traceEntries[] = "Salary deduction: ₱{$perMinuteRate} × {$adjustedMinutes} min = ₱{$salaryDeduction}";
        }

        $dayType = $this->classifyDay($totalExpectedSessions, $missedSessionCount, $deductionMinutes);
        $traceEntries[] = "Day classified as: {$dayType->value}";

        $result = new DailyAttendanceResult(
            paired: $paired,
            tardinessMinutes: $tardinessMinutes,
            undertimeMinutes: $undertimeMinutes,
            deductionMinutes: $deductionMinutes,
            dayType: $dayType,
            perMinuteRate: $perMinuteRate,
            salaryDeduction: $salaryDeduction,
            lunchWindowCompliant: $lunchWindowCompliant,
            excussedAbsence: $excussedAbsence,
            adjustedMinutes: $adjustedMinutes,
            leaveCreditMinutes: $leaveCreditMinutes,
            ruleTrace: new RuleTrace($traceEntries),
        );

        return $this->employmentPolicy->applyPolicy($this->employee, $result);
    }

    private function classifyDay(int $totalExpectedSessions, int $missedSessionCount, int $deductionMinutes): AttendanceDayType
    {
        if ($totalExpectedSessions > 0 && $missedSessionCount === $totalExpectedSessions) {
            return AttendanceDayType::FULL_DAY_ABSENT;
        }

        if ($totalExpectedSessions === 2 && $missedSessionCount === 1) {
            return AttendanceDayType::HALF_DAY;
        }

        if ($deductionMinutes === 0) {
            return AttendanceDayType::PERFECT;
        }

        return AttendanceDayType::PARTIAL;
    }

    private function applyLeaveCredits(float $leaveCredits, int $deductionMinutes, array &$traceEntries): array
    {
        $leaveCreditMinutes = 0;
        if ($leaveCredits <= 0.0) {
            return [$deductionMinutes, $leaveCreditMinutes];
        }

        if (! method_exists($this->scheduleRule, 'getRuleTable')) {
            $traceEntries[] = "Leave credits {$leaveCredits} day(s) ignored because the current schedule rule does not expose a rule table.";
            return [$deductionMinutes, 0];
        }

        $ruleTable = $this->scheduleRule->getRuleTable();
        if (! method_exists($ruleTable, 'getMinutesForEquivalentDay')) {
            $traceEntries[] = "Leave credits {$leaveCredits} day(s) ignored because the rule table cannot convert equivalent days to minutes.";
            return [$deductionMinutes, 0];
        }

        $leaveCreditMinutes = $ruleTable->getMinutesForEquivalentDay($leaveCredits);
        $traceEntries[] = sprintf(
            'Leave credits: %.2f equivalent day(s) -> %d minute(s) using %s',
            $leaveCredits,
            $leaveCreditMinutes,
            method_exists($ruleTable, 'getLabel') ? $ruleTable->getLabel() : 'rule table'
        );

        $adjustedMinutes = max(0, $deductionMinutes - $leaveCreditMinutes);
        $traceEntries[] = sprintf(
            'Adjusted deduction: %d - %d = %d minute(s)',
            $deductionMinutes,
            $leaveCreditMinutes,
            $adjustedMinutes
        );

        return [$adjustedMinutes, $leaveCreditMinutes];
    }

    /**
     * @param WorkSession[] $actualSessions
     * @param array<int,int> $usedActualSessionKeys indexes of actual sessions already matched to an expected session
     */
    private function findOverlappingSession(WorkSession $expected, array $actualSessions, array &$usedActualSessionKeys = []): ?WorkSession
    {
        foreach ($actualSessions as $key => $actual) {
            if (in_array($key, $usedActualSessionKeys, true)) {
                continue;
            }
            $actualEnd = $actual->getSessionOut() ?? $actual->getSessionIn();

            $overlaps = $actual->getSessionIn()->lessThanOrEqualTo($expected->getSessionOut())
                && $actualEnd->greaterThanOrEqualTo($expected->getSessionIn());

            if ($overlaps) {
                $usedActualSessionKeys[] = $key;
                return $actual;
            }
        }

        return null;
    }

    /**
     * A7: Lunch-window compliance checks.
     * NOTE: Standard session overlap logic already handles:
     *   - AM out < 12:00  → undertime via "actual out < expected out"
     *   - PM in  > 13:00  → tardiness via "actual in > expected in"
     * So this method penalizes ONLY the non-standard cases that session logic misses:
     *   - AM out > 12:30 (overstayed AM past lunch start; effectively missed lunch window) → undertime
     *   - PM in  < 12:30 (started PM early before lunch ended)                           → tardiness
     * Non-compliance flag is flipped for any deviation outside [12:00–12:30] / [12:30–13:00].
     *
     * @param WorkSession[] $actualSessions
     */
    private function checkLunchWindowCompliance(
        WorkDay $day,
        array $actualSessions,
        int &$tardinessMinutes,
        int &$undertimeMinutes,
        array &$traceEntries,
    ): bool {
        $date = $day->getDate();
        $amOutMin = $date->copy()->setTime(12, 0);
        $amOutMax = $date->copy()->setTime(12, 30);
        $pmInMin = $date->copy()->setTime(12, 30);
        $pmInMax = $date->copy()->setTime(13, 0);

        $compliant = true;

        if (count($actualSessions) >= 2) {
            $amOut = $actualSessions[0]->getSessionOut();
            $pmIn = $actualSessions[1]->getSessionIn();

            if ($amOut !== null) {
                if ($amOut->lessThan($amOutMin)) {
                    $compliant = false;
                    $traceEntries[] = "Lunch: early AM out ({$amOut->format('H:i')} < 12:00) — undertime already applied via session check";
                } elseif ($amOut->greaterThan($amOutMax)) {
                    $late = (int) $amOutMax->diffInMinutes($amOut, true);
                    $undertimeMinutes += $late;
                    $traceEntries[] = "Lunch: late AM out ({$amOut->format('H:i')} > 12:30): +{$late} min undertime (missed lunch window start)";
                    $compliant = false;
                }
            }

            if ($pmIn !== null) {
                if ($pmIn->lessThan($pmInMin)) {
                    $early = (int) $pmIn->diffInMinutes($pmInMin, true);
                    $tardinessMinutes += $early;
                    $traceEntries[] = "Lunch: early PM in ({$pmIn->format('H:i')} < 12:30): +{$early} min tardiness (started before lunch ended)";
                    $compliant = false;
                } elseif ($pmIn->greaterThan($pmInMax)) {
                    $compliant = false;
                    $traceEntries[] = "Lunch: late PM in ({$pmIn->format('H:i')} > 13:00) — tardiness already applied via session check";
                }
            }

            if ($amOut !== null && $pmIn !== null) {
                $gap = (int) $amOut->diffInMinutes($pmIn, true);
                if ($gap < 0 || $gap > 60) {
                    $traceEntries[] = "Lunch: gap between AM out and PM in is {$gap} min (expected 0–60 min for valid windows)";
                    $compliant = false;
                }
            }
        }

        return $compliant;
    }

    /**
     * A9: Resolve a single open/ambiguous punch.
     * - If only one punch exists and it's a mid-day punch (no pair):
     *   - time < 12:00 → treat as AM out only (open AM session)
     *   - time >= 12:00 → treat as PM in only (open PM session)
     * This is deterministic per the spec fallback.
     *
     * @param WorkSession[] $actualSessions
     * @return WorkSession[]
     */
    private function resolveAmbiguousSinglePunch(WorkDay $day, array $actualSessions, array &$traceEntries): array
    {
        if (count($actualSessions) !== 1) {
            return $actualSessions;
        }

        $single = $actualSessions[0];
        if ($single->getSessionOut() !== null) {
            return $actualSessions;
        }

        $onlyTime = $single->getSessionIn();
        $noon = $day->getDate()->copy()->setTime(12, 0);

        if ($onlyTime->lessThan($noon)) {
            $traceEntries[] = "Single punch @ {$onlyTime->format('H:i')} (< 12:00): treated as AM out (open AM session)";
            return [new WorkSession($onlyTime, null)];
        }

        $traceEntries[] = "Single punch @ {$onlyTime->format('H:i')} (>= 12:00): treated as PM in (open PM session)";
        return [new WorkSession($onlyTime, null)];
    }

    /**
     * Pairs a flat, chronological list of punches into in/out WorkSessions.
     *
     * Pairing strategy:
     * - 0 punches: empty
     * - 1 punch:  ambiguous open session (handled by resolveAmbiguousSinglePunch later)
     * - 2 punches: normal chronological pair → (p1 in, p2 out)
     * - 3 punches: "missing one swipe" scenario (A4a-d).
     *     Instead of naive (p1+p2, p3=open), split around the LUNCH WINDOW
     *     so the resulting sessions line up with expected AM/PM slots:
     *       - Find a split point near 12:30 (lunch midpoint) using 12:00–13:00
     *         as the lunch corridor. Any punch in that corridor is a natural
     *         boundary candidate (AM out / PM in).
     *       - Split list -> AM punches | PM punches.
     *       - Assign: odd-count side -> 1 open session (its last punch is the
     *         "orphan" from the missing swipe), even-count side -> closed pair.
     * - 4+ punches: chronological pair (p1+p2, p3+p4, …). The 2-min dedup
     *     pass above already collapsed nervous double-taps, so extras
     *     produce open trailing sessions deterministically.
     *
     * A8a: consecutive near-duplicates (within DEDUP_WINDOW_MINUTES) are collapsed first.
     * An odd trailing punch after pairing is treated as an open session with no clock-out.
     *
     * @param Punch[] $punches
     * @param string[] $traceEntries
     */
    public function pairedPunches(array $punches, array &$traceEntries = []): PairedDay
    {
        $sorted = $punches;
        usort($sorted, static fn (Punch $a, Punch $b) => $a->getTimeStamp() <=> $b->getTimeStamp());

        $deduped = [];
        $rawCount = count($sorted);
        foreach ($sorted as $p) {
            $lastIndex = count($deduped) - 1;
            $last = $lastIndex >= 0 ? $deduped[$lastIndex] : null;
            if ($last !== null && (int) $last->getTimeStamp()->diffInMinutes($p->getTimeStamp(), true) <= self::DEDUP_WINDOW_MINUTES) {
                $traceEntries[] = "Dedup swipe @ {$p->getTimeStamp()->format('H:i')} (within " . self::DEDUP_WINDOW_MINUTES . " min of {$last->getTimeStamp()->format('H:i')}) — kept earlier";
                continue;
            }
            $deduped[] = $p;
        }
        if (count($deduped) !== $rawCount) {
            $traceEntries[] = 'After dedup: ' . count($deduped) . ' swipes retained (from ' . $rawCount . ' raw)';
        }

        $count = count($deduped);
        $sessions = [];

        if ($count === 3) {
            $sessions = $this->pairThreePunchesAroundLunch($deduped, $traceEntries);
        } else {
            for ($i = 0; $i < $count; $i += 2) {
                $in = $deduped[$i]->getTimeStamp();
                $out = isset($deduped[$i + 1]) ? $deduped[$i + 1]->getTimeStamp() : null;
                if ($out !== null) {
                    $traceEntries[] = "Paired session " . (count($sessions) + 1) . ": in={$in->format('H:i')} out={$out->format('H:i')}";
                } else {
                    $traceEntries[] = "Open session " . (count($sessions) + 1) . ": in={$in->format('H:i')} (no out yet)";
                }
                $sessions[] = new WorkSession($in, $out);
            }
        }

        return new PairedDay($sessions);
    }

    /**
     * A4 helper: pair 3 punches by splitting at the lunch corridor so AM/PM
     * sessions line up with expected windows. Exactly one side (AM or PM) will
     * have 2 punches (complete pair) and the other 1 punch (open/incomplete).
     * The incomplete side is what triggers half_day_flag on the matching
     * expected session.
     *
     * Split rules (applied in order):
     *   1. If any punch >= 12:30 exists, split BEFORE the first such punch.
     *      This way 12:15 (AM out) stays on AM side, 13:00 (PM in) goes to PM.
     *   2. Otherwise all punches are before 12:30: split near the 12:00 boundary
     *      (punch closest to 12:00 at position i>=1 is the boundary).
     *   3. Fallback: split closest to the 12:30 midpoint.
     *
     * @param Punch[] $threePunches sorted array of 3 punches after dedup
     * @param string[] $traceEntries
     * @return WorkSession[]
     */
    private function pairThreePunchesAroundLunch(array $threePunches, array &$traceEntries): array
    {
        $date = $threePunches[0]->getTimeStamp()->copy()->startOfDay();
        $lunchAmOutMin = $date->copy()->setTime(12, 0);
        $lunchMid = $date->copy()->setTime(12, 30);
        $lunchPmInMax = $date->copy()->setTime(13, 0);

        $splitIndex = null;

        for ($i = 1; $i < 3; $i++) {
            $ts = $threePunches[$i]->getTimeStamp();
            if ($ts->greaterThanOrEqualTo($lunchMid)) {
                $splitIndex = $i;
                break;
            }
        }

        if ($splitIndex === null) {
            for ($i = 1; $i < 3; $i++) {
                $ts = $threePunches[$i]->getTimeStamp();
                if ($ts->greaterThanOrEqualTo($lunchAmOutMin)) {
                    $splitIndex = $i;
                }
            }
        }

        if ($splitIndex === null) {
            $minGap = null;
            for ($i = 1; $i < 3; $i++) {
                $gap = (int) $lunchMid->diffInMinutes($threePunches[$i]->getTimeStamp(), true);
                if ($minGap === null || $gap < $minGap) {
                    $minGap = $gap;
                    $splitIndex = $i;
                }
            }
        }

        $amPunches = array_slice($threePunches, 0, $splitIndex);
        $pmPunches = array_slice($threePunches, $splitIndex);

        $traceEntries[] = "3-punch day: lunch-window split @ index {$splitIndex} -> AM swipes=" . count($amPunches) . ", PM swipes=" . count($pmPunches);

        $sessions = [];

        if (count($amPunches) >= 1) {
            if (count($amPunches) >= 2) {
                $in = $amPunches[0]->getTimeStamp();
                $out = $amPunches[1]->getTimeStamp();
                $traceEntries[] = "Paired AM session: in={$in->format('H:i')} out={$out->format('H:i')}";
                $sessions[] = new WorkSession($in, $out);
            } else {
                $orphan = $amPunches[0]->getTimeStamp();
                if ($orphan->greaterThanOrEqualTo($lunchAmOutMin) && $orphan->lessThanOrEqualTo($lunchPmInMax)) {
                    $traceEntries[] = "Open AM session: out={$orphan->format('H:i')} only (AM out missing its in — AM side incomplete)";
                    $dummyIn = $orphan->copy()->subMinutes(1);
                    $sessions[] = new WorkSession($dummyIn, null);
                } else {
                    $traceEntries[] = "Open AM session: in={$orphan->format('H:i')} only (AM in missing its out — AM side incomplete)";
                    $sessions[] = new WorkSession($orphan, null);
                }
            }
        }

        if (count($pmPunches) >= 1) {
            if (count($pmPunches) >= 2) {
                $in = $pmPunches[0]->getTimeStamp();
                $out = $pmPunches[1]->getTimeStamp();
                $traceEntries[] = "Paired PM session: in={$in->format('H:i')} out={$out->format('H:i')}";
                $sessions[] = new WorkSession($in, $out);
            } else {
                $orphan = $pmPunches[0]->getTimeStamp();
                if ($orphan->greaterThanOrEqualTo($lunchAmOutMin) && $orphan->lessThanOrEqualTo($lunchPmInMax)) {
                    $traceEntries[] = "Open PM session: in={$orphan->format('H:i')} only (PM in missing its out — PM side incomplete)";
                    $sessions[] = new WorkSession($orphan, null);
                } else {
                    $traceEntries[] = "Open PM session: out={$orphan->format('H:i')} only (PM out missing its in — PM side incomplete)";
                    $dummyIn = $orphan->copy()->subMinutes(1);
                    $sessions[] = new WorkSession($dummyIn, null);
                }
            }
        }

        return $sessions;
    }
}

