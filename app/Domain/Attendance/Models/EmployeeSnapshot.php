<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Models;

class EmployeeSnapshot
{
    public function __construct(
        private string $employeeRef,
        private string $employmentTypeCode,
        private bool $muslimFlag = true,
        private float $monthlyBasicSalary = 0.0,
        private bool $excludeFromPayroll = false,
        private float $creditRufPerRemaining = 0.00,
        private ?CreditBuffer $creditBuffer = null,
    ) {
        $this->creditBuffer = $creditBuffer ?? new CreditBuffer();
    }

    protected function setEmployeeRef(string $employeeRef): void
    {
        $this->employeeRef = $employeeRef;
    }

    protected function setEmploymentTypeCode(string $employmentTypeCode): void
    {
        $this->employmentTypeCode = $employmentTypeCode;
    }

    protected function setMuslimFlag(bool $muslimFlag): void
    {
        $this->muslimFlag = $muslimFlag;
    }

    protected function setMonthlyBasicSalary(float $monthlyBasicSalary): void
    {
        $this->monthlyBasicSalary = $monthlyBasicSalary;
    }

    protected function setExcludeFromPayroll(bool $excludeFromPayroll): void
    {
        $this->excludeFromPayroll = $excludeFromPayroll;
    }

    protected function setCreditRufPerRemaining(float $creditRufPerRemaining): void
    {
        $this->creditRufPerRemaining = $creditRufPerRemaining;
    }

    protected function setCreditBuffer(CreditBuffer $creditBuffer): void
    {
        $this->creditBuffer = $creditBuffer;
    }

    public function getEmployeeRef(): string
    {
        return $this->employeeRef;
    }

    public function getEmploymentTypeCode(): string
    {
        return $this->employmentTypeCode;
    }

    public function getMuslimFlag(): bool
    {
        return $this->muslimFlag;
    }

    public function getMonthlyBasicSalary(): float
    {
        return $this->monthlyBasicSalary;
    }

    public function getExcludeFromPayroll(): bool
    {
        return $this->excludeFromPayroll;
    }

    public function getCreditRufPerRemaining(): float
    {
        return $this->creditRufPerRemaining;
    }

    public function getCreditBuffer(): CreditBuffer
    {
        return $this->creditBuffer;
    }
}
