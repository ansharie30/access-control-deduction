<?php

declare(strict_types=1);

namespace Tests\Unit\Attendance;

use App\Domain\Attendance\RuleTables\EightHourWorkdayRuleTable;
use App\Domain\Attendance\RuleTables\TenHourWorkdayRuleTable;
use App\Domain\Attendance\Rules\CompressedWorkWeekRule;
use App\Domain\Attendance\Rules\FridayFlexitimeRule;
use App\Domain\Attendance\Rules\RegularScheduleRule;
use PHPUnit\Framework\TestCase;

class RuleTablesTest extends TestCase
{
    public function test_regular_schedule_rule_can_expose_a_rule_table(): void
    {
        $rule = new RegularScheduleRule();
        $table = $rule->getRuleTable();

        $this->assertInstanceOf(EightHourWorkdayRuleTable::class, $table);
        $this->assertSame(0.125, $table->getEquivalentDayForHours(1));
        $this->assertSame(0.062, $table->getEquivalentDayForMinutes(30));
        $this->assertSame(0.187, $table->getEquivalentDayForDuration(1, 30));
    }

    public function test_friday_flexitime_rule_uses_eight_hour_rule_table_by_default(): void
    {
        $rule = new FridayFlexitimeRule();
        $this->assertInstanceOf(EightHourWorkdayRuleTable::class, $rule->getRuleTable());
    }

    public function test_compressed_work_week_rule_uses_ten_hour_rule_table_by_default(): void
    {
        $rule = new CompressedWorkWeekRule();
        $table = $rule->getRuleTable();

        $this->assertInstanceOf(TenHourWorkdayRuleTable::class, $table);
        $this->assertSame(1.0, $table->getEquivalentDayForHours(10));
        $this->assertSame(0.050, $table->getEquivalentDayForMinutes(30));
        $this->assertEqualsWithDelta(0.150, $table->getEquivalentDayForDuration(1, 30), 0.000001);
    }

    public function test_leave_credit_conversion_uses_rule_table_to_compute_minutes_for_eight_hour_table(): void
    {
        $table = new EightHourWorkdayRuleTable();

        $this->assertSame(480, $table->getMinutesPerDay());
        $this->assertSame(576, $table->getMinutesForEquivalentDay(1.2));
        $this->assertSame(30, $table->getMinutesForEquivalentDay(0.062));
    }

    public function test_leave_credit_conversion_uses_rule_table_to_compute_minutes_for_ten_hour_table(): void
    {
        $table = new TenHourWorkdayRuleTable();

        $this->assertSame(600, $table->getMinutesPerDay());
        $this->assertSame(720, $table->getMinutesForEquivalentDay(1.2));
        $this->assertSame(30, $table->getMinutesForEquivalentDay(0.050));
    }
}
