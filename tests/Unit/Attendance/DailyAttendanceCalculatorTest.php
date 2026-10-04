<?php

declare(strict_types=1);

namespace Tests\Unit\Attendance;

use App\Domain\Attendance\DailyAttendanceCalculator;
use App\Domain\Attendance\Fixtures\FakeEmployees;
use App\Domain\Attendance\Models\AttendanceDayType;
use App\Domain\Attendance\Models\Punch;
use App\Domain\Attendance\Policies\PermanentPolicy;
use App\Domain\Attendance\Rules\FridayFlexitimeRule;
use App\Domain\Attendance\Rules\RegularScheduleRule;
use App\Domain\Attendance\Support\WorkDay;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class DailyAttendanceCalculatorTest extends TestCase
{
    private const EMP_REF = 'EMP-2026-0001';

    private function makeCalculator(?string $empRef = null, ?RegularScheduleRule $rule = null): DailyAttendanceCalculator
    {
        return new DailyAttendanceCalculator(
            FakeEmployees::findByRef($empRef ?? self::EMP_REF),
            $rule ?? new RegularScheduleRule(),
            new PermanentPolicy()
        );
    }

    private function sampleWorkDay(): WorkDay
    {
        return new WorkDay(Carbon::parse('2026-07-27'));
    }

    private function friday(): WorkDay
    {
        return new WorkDay(Carbon::parse('2026-07-31'));
    }

    private function saturday(): WorkDay
    {
        return new WorkDay(Carbon::parse('2026-08-01'), WorkDay::DAY_TYPE_WEEKEND);
    }

    private function holiday(): WorkDay
    {
        return new WorkDay(Carbon::parse('2026-08-26'), WorkDay::DAY_TYPE_HOLIDAY);
    }

    /**
     * @param list<string> $hhmm
     * @return Punch[]
     */
    private function makePunches(WorkDay $day, array $hhmm): array
    {
        $date = $day->getDate();
        $punches = [];
        foreach ($hhmm as $timeStr) {
            [$h, $m] = explode(':', $timeStr);
            $punches[] = new Punch($date->copy()->setTime((int) $h, (int) $m));
        }
        return $punches;
    }

    // ----- A1: Normal day -----

    public function test_one_minute_tardiness_is_computed_to_the_minute(): void
    {
        $day = $this->sampleWorkDay();
        $punches = $this->makePunches($day, ['08:01', '12:00', '13:00', '17:00']);
        $result = $this->makeCalculator()->evaluate($day, $punches);

        $this->assertSame(1, $result->getTardinessMinutes());
        $this->assertSame(0, $result->getUndertimeMinutes());
        $this->assertSame(1, $result->getDeductionMinutes());
        $this->assertSame(AttendanceDayType::PARTIAL, $result->getDayType());

        $this->assertEqualsWithDelta(
            1 / DailyAttendanceCalculator::MONTHLY_BASELINE_MINUTES,
            $result->getDeductionFactor(DailyAttendanceCalculator::MONTHLY_BASELINE_MINUTES),
            0.0000001
        );

        $expectedRate = round(33000.0 / DailyAttendanceCalculator::MONTHLY_BASELINE_MINUTES, 2);
        $this->assertEqualsWithDelta($expectedRate, $result->getPerMinuteRate(), 0.0000001);
        $this->assertEqualsWithDelta($expectedRate * 1, $result->getSalaryDeduction(), 0.005);
    }

    public function test_leave_credits_are_applied_by_daily_attendance_calculator(): void
    {
        $day = $this->sampleWorkDay();
        $punches = $this->makePunches($day, ['08:01', '12:00', '13:00', '17:00']);
        $result = $this->makeCalculator()->evaluate($day, $punches, 1.0);

        $this->assertSame(1, $result->getTardinessMinutes());
        $this->assertSame(0, $result->getUndertimeMinutes());
        $this->assertSame(1, $result->getDeductionMinutes());
        $this->assertSame(480, $result->getLeaveCreditMinutes());
        $this->assertSame(0, $result->getAdjustedMinutes());
        $this->assertSame(0.0, $result->getSalaryDeduction());
    }

    // ----- A2: Tardiness variants -----

    public function test_a2_pm_tardiness_only(): void
    {
        $day = $this->sampleWorkDay();
        $punches = $this->makePunches($day, ['08:00', '12:15', '13:05', '17:00']);
        $result = $this->makeCalculator()->evaluate($day, $punches);

        $this->assertSame(5, $result->getTardinessMinutes());
    }

    public function test_a2_am_and_pm_both_late(): void
    {
        $day = $this->sampleWorkDay();
        $punches = $this->makePunches($day, ['08:30', '12:15', '13:10', '17:00']);
        $result = $this->makeCalculator()->evaluate($day, $punches);

        $this->assertSame(40, $result->getTardinessMinutes());
    }

    // ----- A3: Undertime -----

    public function test_a3_early_am_out(): void
    {
        $day = $this->sampleWorkDay();
        $punches = $this->makePunches($day, ['08:00', '11:45', '13:00', '17:00']);
        $result = $this->makeCalculator()->evaluate($day, $punches);

        $this->assertGreaterThanOrEqual(15, $result->getUndertimeMinutes());
    }

    public function test_a3_early_pm_out(): void
    {
        $day = $this->sampleWorkDay();
        $punches = $this->makePunches($day, ['08:00', '12:15', '12:45', '16:30']);
        $result = $this->makeCalculator()->evaluate($day, $punches);

        $this->assertGreaterThanOrEqual(30, $result->getUndertimeMinutes());
    }

    public function test_a3_am_and_pm_both_early_out(): void
    {
        $day = $this->sampleWorkDay();
        $punches = $this->makePunches($day, ['08:00', '11:30', '13:00', '16:00']);
        $result = $this->makeCalculator()->evaluate($day, $punches);

        $this->assertGreaterThanOrEqual(90, $result->getUndertimeMinutes());
    }

    // ----- A4: Missing one swipe (3 punches) -> half_day_flag -----

    public function test_a4a_missing_am_in_three_punches(): void
    {
        $day = $this->sampleWorkDay();
        $punches = $this->makePunches($day, ['12:15', '13:00', '17:00']);
        $result = $this->makeCalculator()->evaluate($day, $punches);

        $this->assertTrue($result->getHalfDayFlag());
        $this->assertSame(AttendanceDayType::HALF_DAY, $result->getDayType());
    }

    public function test_a4b_missing_am_out_three_punches(): void
    {
        $day = $this->sampleWorkDay();
        $punches = $this->makePunches($day, ['08:00', '13:00', '17:00']);
        $result = $this->makeCalculator()->evaluate($day, $punches);

        $this->assertTrue($result->getHalfDayFlag());
    }

    public function test_a4c_missing_pm_in_three_punches(): void
    {
        $day = $this->sampleWorkDay();
        $punches = $this->makePunches($day, ['08:00', '12:15', '17:00']);
        $result = $this->makeCalculator()->evaluate($day, $punches);

        $this->assertTrue($result->getHalfDayFlag());
    }

    public function test_a4d_missing_pm_out_three_punches(): void
    {
        $day = $this->sampleWorkDay();
        $punches = $this->makePunches($day, ['08:00', '12:15', '13:00']);
        $result = $this->makeCalculator()->evaluate($day, $punches);

        $this->assertTrue($result->getHalfDayFlag());
    }

    // ----- A5: Half-day (AM only or PM only) -----

    public function test_full_missed_am_half_is_classified_as_half_day(): void
    {
        $day = $this->sampleWorkDay();
        $punches = $this->makePunches($day, ['13:00', '17:00']);
        $result = $this->makeCalculator()->evaluate($day, $punches);

        $this->assertSame(AttendanceDayType::HALF_DAY, $result->getDayType());
        $this->assertTrue($result->getHalfDayFlag());
        $this->assertSame(0, $result->getTardinessMinutes());
        $this->assertSame(240, $result->getUndertimeMinutes());
        $this->assertSame(240, $result->getDeductionMinutes());

        $expectedRate = round(33000.0 / DailyAttendanceCalculator::MONTHLY_BASELINE_MINUTES, 2);
        $this->assertEqualsWithDelta($expectedRate * 240, $result->getSalaryDeduction(), 0.005);
    }

    public function test_full_missed_pm_half_is_classified_as_half_day(): void
    {
        $day = $this->sampleWorkDay();
        $punches = $this->makePunches($day, ['08:00', '12:00']);
        $result = $this->makeCalculator()->evaluate($day, $punches);

        $this->assertSame(AttendanceDayType::HALF_DAY, $result->getDayType());
        $this->assertTrue($result->getHalfDayFlag());
        $this->assertSame(0, $result->getTardinessMinutes());
        $this->assertSame(240, $result->getUndertimeMinutes());
        $this->assertSame(240, $result->getDeductionMinutes());

        $expectedRate = round(33000.0 / DailyAttendanceCalculator::MONTHLY_BASELINE_MINUTES, 2);
        $this->assertEqualsWithDelta($expectedRate * 240, $result->getSalaryDeduction(), 0.005);
    }

    public function test_a5_am_only_pair(): void
    {
        $day = $this->sampleWorkDay();
        $punches = $this->makePunches($day, ['08:00', '12:15']);
        $result = $this->makeCalculator()->evaluate($day, $punches);

        $this->assertTrue($result->getHalfDayFlag());
        $this->assertSame(AttendanceDayType::HALF_DAY, $result->getDayType());
    }

    // ----- A6: Full absence -----

    public function test_full_day_absence_deducts_the_entire_scheduled_day(): void
    {
        $day = $this->sampleWorkDay();
        $punches = $this->makePunches($day, []);
        $result = $this->makeCalculator()->evaluate($day, $punches);

        $this->assertSame(AttendanceDayType::FULL_DAY_ABSENT, $result->getDayType());
        $this->assertSame(480, $result->getDeductionMinutes());

        $expectedRate = round(33000.0 / DailyAttendanceCalculator::MONTHLY_BASELINE_MINUTES, 2);
        $this->assertEqualsWithDelta($expectedRate * 480, $result->getSalaryDeduction(), 0.005);
    }

    // ----- A1 (already): Perfect -----

    public function test_perfect_attendance_produces_zero_deduction_minutes(): void
    {
        $day = $this->sampleWorkDay();
        $punches = $this->makePunches($day, ['08:00', '12:00', '13:00', '17:00']);
        $result = $this->makeCalculator()->evaluate($day, $punches);

        $this->assertSame(0, $result->getDeductionMinutes());
        $this->assertSame(AttendanceDayType::PERFECT, $result->getDayType());
        $this->assertSame(0.0, $result->getDeductionFactor(DailyAttendanceCalculator::MONTHLY_BASELINE_MINUTES));
        $this->assertTrue($result->getLunchWindowCompliant());

        $expectedRate = round(33000.0 / DailyAttendanceCalculator::MONTHLY_BASELINE_MINUTES, 2);
        $this->assertEqualsWithDelta($expectedRate, $result->getPerMinuteRate(), 0.0000001);
        $this->assertEqualsWithDelta(0.0, $result->getSalaryDeduction(), 0.005);
    }

    // ----- A7: Lunch window compliance -----

    public function test_a7a_compliant_lunch_windows(): void
    {
        $day = $this->sampleWorkDay();
        $punches = $this->makePunches($day, ['08:00', '12:15', '12:45', '17:00']);
        $result = $this->makeCalculator()->evaluate($day, $punches);

        $this->assertTrue($result->getLunchWindowCompliant());
    }

    public function test_a7b_early_am_out_triggers_undertime(): void
    {
        $day = $this->sampleWorkDay();
        $punches = $this->makePunches($day, ['08:00', '11:55', '13:00', '17:00']);
        $result = $this->makeCalculator()->evaluate($day, $punches);

        $this->assertFalse($result->getLunchWindowCompliant());
        $this->assertGreaterThanOrEqual(5, $result->getUndertimeMinutes());
    }

    public function test_a7c_late_am_out_triggers_undertime(): void
    {
        $day = $this->sampleWorkDay();
        $punches = $this->makePunches($day, ['08:00', '12:35', '13:00', '17:00']);
        $result = $this->makeCalculator()->evaluate($day, $punches);

        $this->assertFalse($result->getLunchWindowCompliant());
        $this->assertGreaterThanOrEqual(5, $result->getUndertimeMinutes());
    }

    public function test_a7d_early_pm_in_triggers_tardiness(): void
    {
        $day = $this->sampleWorkDay();
        $punches = $this->makePunches($day, ['08:00', '12:15', '12:20', '17:00']);
        $result = $this->makeCalculator()->evaluate($day, $punches);

        $this->assertFalse($result->getLunchWindowCompliant());
        $this->assertGreaterThanOrEqual(10, $result->getTardinessMinutes());
    }

    public function test_a7e_late_pm_in_triggers_tardiness(): void
    {
        $day = $this->sampleWorkDay();
        $punches = $this->makePunches($day, ['08:00', '12:15', '13:05', '17:00']);
        $result = $this->makeCalculator()->evaluate($day, $punches);

        $this->assertFalse($result->getLunchWindowCompliant());
        $this->assertGreaterThanOrEqual(5, $result->getTardinessMinutes());
    }

    // ----- A8: Excess swipes — dedup near-duplicates -----

    public function test_a8a_consecutive_duplicates_are_deduped(): void
    {
        $day = $this->sampleWorkDay();
        $punches = $this->makePunches($day, ['08:00', '08:01', '12:15', '12:16', '13:00', '17:00']);
        $calc = $this->makeCalculator();
        $result = $calc->evaluate($day, $punches);

        $this->assertSame(0, $result->getTardinessMinutes());
        $this->assertSame(AttendanceDayType::PERFECT, $result->getDayType());
        $trace = implode("\n", $result->getRuleTrace()->getEntries());
        $this->assertStringContainsString('Dedup', $trace);
    }

    public function test_a8c_rule_trace_records_paired_sessions(): void
    {
        $day = $this->sampleWorkDay();
        $punches = $this->makePunches($day, ['08:00', '12:00', '13:00', '17:00']);
        $result = $this->makeCalculator()->evaluate($day, $punches);

        $trace = $result->getRuleTrace()->getEntries();
        $joined = implode("\n", $trace);
        $this->assertStringContainsString('Paired session 1', $joined);
        $this->assertStringContainsString('Paired session 2', $joined);
    }

    // ----- A9: Single ambiguous punch -----

    public function test_a9_single_midday_punch_after_noon_is_treated_as_pm_in(): void
    {
        $day = $this->sampleWorkDay();
        $punches = $this->makePunches($day, ['12:45']);
        $result = $this->makeCalculator()->evaluate($day, $punches);

        $trace = implode("\n", $result->getRuleTrace()->getEntries());
        $this->assertStringContainsString('PM in', $trace);
    }

    public function test_a9_single_punch_before_noon_is_treated_as_am_out(): void
    {
        $day = $this->sampleWorkDay();
        $punches = $this->makePunches($day, ['11:30']);
        $result = $this->makeCalculator()->evaluate($day, $punches);

        $trace = implode("\n", $result->getRuleTrace()->getEntries());
        $this->assertStringContainsString('AM out', $trace);
    }

    // ----- B1: Friday Flexitime muslim -----

    public function test_b1_friday_muslim_uses_shorter_pm_window(): void
    {
        $fri = $this->friday();
        $punches = $this->makePunches($fri, ['08:00', '12:00', '13:00', '16:00']);
        $empMuslim = FakeEmployees::findByRef(self::EMP_REF);
        $calc = new DailyAttendanceCalculator($empMuslim, new FridayFlexitimeRule(), new PermanentPolicy());
        $result = $calc->evaluate($fri, $punches);

        $this->assertSame(AttendanceDayType::PERFECT, $result->getDayType());
        $this->assertSame(0, $result->getDeductionMinutes());
    }

    public function test_b1_friday_muslim_leaves_at_1601_gets_undertime(): void
    {
        $fri = $this->friday();
        $punches = $this->makePunches($fri, ['08:00', '12:00', '13:00', '15:30']);
        $empMuslim = FakeEmployees::findByRef(self::EMP_REF);
        $calc = new DailyAttendanceCalculator($empMuslim, new FridayFlexitimeRule(), new PermanentPolicy());
        $result = $calc->evaluate($fri, $punches);

        $this->assertGreaterThanOrEqual(30, $result->getUndertimeMinutes());
    }

    public function test_b1_non_friday_uses_regular_schedule_even_with_friday_rule(): void
    {
        $mon = $this->sampleWorkDay();
        $punches = $this->makePunches($mon, ['08:00', '12:00', '13:00', '17:00']);
        $empMuslim = FakeEmployees::findByRef(self::EMP_REF);
        $calc = new DailyAttendanceCalculator($empMuslim, new FridayFlexitimeRule(), new PermanentPolicy());
        $result = $calc->evaluate($mon, $punches);

        $this->assertSame(AttendanceDayType::PERFECT, $result->getDayType());
    }

    // ----- B2: Non-working days -----

    public function test_b2_weekend_skips_deduction_regardless_of_punches(): void
    {
        $sat = $this->saturday();
        $punches = $this->makePunches($sat, ['09:00', '12:00']);
        $result = $this->makeCalculator()->evaluate($sat, $punches);

        $this->assertSame(0, $result->getDeductionMinutes());
        $this->assertSame(0, $result->getTardinessMinutes());
        $this->assertSame(0, $result->getUndertimeMinutes());
        $this->assertSame(0.0, $result->getSalaryDeduction());
    }

    public function test_b2_holiday_skips_deduction(): void
    {
        $hol = $this->holiday();
        $punches = $this->makePunches($hol, []);
        $result = $this->makeCalculator()->evaluate($hol, $punches);

        $this->assertSame(0, $result->getDeductionMinutes());
        $this->assertSame(0.0, $result->getSalaryDeduction());
    }

    // ----- C1: Permanent policy -----

    public function test_c1_permanent_policy_applies_deduction_immediately(): void
    {
        $day = $this->sampleWorkDay();
        $punches = $this->makePunches($day, ['08:30', '12:00', '13:00', '17:00']);
        $result = $this->makeCalculator()->evaluate($day, $punches);

        $this->assertSame(30, $result->getTardinessMinutes());
        $this->assertSame(30, $result->getDeductionMinutes());
        $expectedRate = round(33000.0 / DailyAttendanceCalculator::MONTHLY_BASELINE_MINUTES, 2);
        $this->assertEqualsWithDelta($expectedRate * 30, $result->getSalaryDeduction(), 0.005);
    }
}

