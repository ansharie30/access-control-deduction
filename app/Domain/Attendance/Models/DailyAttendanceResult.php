<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Models;

class DailyAttendanceResult
{
    public function __construct(
        private PairedDay $paired,
        private int $tardinessMinutes,
        private int $undertimeMinutes,
        private int $deductionMinutes,
        private AttendanceDayType $dayType,
        private float $perMinuteRate = 0.0,
        private float $salaryDeduction = 0.0,
        private bool $lunchWindowCompliant = true,
        private bool $excussedAbsence = false,
        private int $adjustedMinutes = 0,
        private int $leaveCreditMinutes = 0,
        private RuleTrace $ruleTrace = new RuleTrace(),
    ) {
    }

    protected function setPaired(PairedDay $paired): void
    {
        $this->paired = $paired;
    }

    protected function setTardinessMinutes(int $tardinessMinutes): void
    {
        $this->tardinessMinutes = $tardinessMinutes;
    }

    protected function setUndertimeMinutes(int $undertimeMinutes): void
    {
        $this->undertimeMinutes = $undertimeMinutes;
    }

    protected function setDeductionMinutes(int $deductionMinutes): void
    {
        $this->deductionMinutes = $deductionMinutes;
    }

    protected function setDayType(AttendanceDayType $dayType): void
    {
        $this->dayType = $dayType;
    }

    protected function setPerMinuteRate(float $perMinuteRate): void
    {
        $this->perMinuteRate = $perMinuteRate;
    }

    protected function setSalaryDeduction(float $salaryDeduction): void
    {
        $this->salaryDeduction = $salaryDeduction;
    }

    protected function setLunchWindowCompliant(bool $lunchWindowCompliant): void
    {
        $this->lunchWindowCompliant = $lunchWindowCompliant;
    }

    protected function setExcussedAbsence(bool $excussedAbsence): void
    {
        $this->excussedAbsence = $excussedAbsence;
    }

    protected function setAdjustedMinutes(int $adjustedMinutes): void
    {
        $this->adjustedMinutes = $adjustedMinutes;
    }

    protected function setLeaveCreditMinutes(int $leaveCreditMinutes): void
    {
        $this->leaveCreditMinutes = $leaveCreditMinutes;
    }

    protected function setRuleTrace(RuleTrace $ruleTrace): void
    {
        $this->ruleTrace = $ruleTrace;
    }

    public function getPaired(): PairedDay
    {
        return $this->paired;
    }

    public function getTardinessMinutes(): int
    {
        return $this->tardinessMinutes;
    }

    public function getUndertimeMinutes(): int
    {
        return $this->undertimeMinutes;
    }

    public function getDeductionMinutes(): int
    {
        return $this->deductionMinutes;
    }

    public function getDayType(): AttendanceDayType
    {
        return $this->dayType;
    }

    public function getPerMinuteRate(): float
    {
        return $this->perMinuteRate;
    }

    public function getSalaryDeduction(): float
    {
        return $this->salaryDeduction;
    }

    public function getLunchWindowCompliant(): bool
    {
        return $this->lunchWindowCompliant;
    }

    public function getExcussedAbsence(): bool
    {
        return $this->excussedAbsence;
    }

    public function getAdjustedMinutes(): int
    {
        return $this->adjustedMinutes;
    }

    public function getRuleTrace(): RuleTrace
    {
        return $this->ruleTrace;
    }

    public function getLeaveCreditMinutes(): int
    {
        return $this->leaveCreditMinutes;
    }

    /**
     * Deduction factor proportioned against the monthly baseline (10,560 minutes).
     * Multiply by monthlyBasicSalary to get the peso deduction.
     */
    public function getDeductionFactor(int $monthlyBaselineMinutes): float
    {
        if ($monthlyBaselineMinutes <= 0) {
            return 0.0;
        }

        return $this->deductionMinutes / $monthlyBaselineMinutes;
    }

    public function getHalfDayFlag(): bool
    {
        return $this->dayType === AttendanceDayType::HALF_DAY;
    }
}
