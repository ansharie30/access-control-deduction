

## Section A: Daily Swipe-Pairing Scenarios (core `compute()` logic)

The ACM's fundamental job: raw punches → (AM in, AM out, PM in, PM out) pairings → daily deduction figure.

### A1: Normal day — all four swipes on time
- **Inputs:** AM in, AM out (12:00–12:30), PM in (12:30–13:00), PM out all within expected windows, no tardiness/undertime
- **Expected output:** `tardiness_minutes = 0`, `undertime_minutes = 0`, `deduction_minutes = 0`, `half_day_flag = false`

### A2: Tardiness — late AM in and/or late PM in
- **Inputs:** AM in after scheduled start (e.g., 08:30 instead of 08:00), PM in after scheduled start (e.g., 13:05 instead of 13:00)
- **Expected output:** Each late punch increments `tardiness_minutes` accordingly (to the minute)
- **Variant:** Only one of the two is late; the other is on time.

### A3: Undertime — early AM out and/or early PM out
- **Inputs:** AM out before 12:00, or PM out before scheduled end (e.g., 16:30 instead of 17:00)
- **Expected output:** Each early exit increments `undertime_minutes` accordingly (to the minute)
- **Variant:** Only one of the two is early; the other is on time.

### A4: Missing swipe — any one of the four is absent
- **Inputs:** Three swipes present; one of (AM in, AM out, PM in, PM out) missing
  - A4a: Missing AM in (AM out + PM in + PM out only)
  - A4b: Missing AM out (AM in + PM in + PM out only)
  - A4c: Missing PM in (AM in + AM out + PM out only)
  - A4d: Missing PM out (AM in + AM out + PM in only)
- **Expected output:** `half_day_flag = true` when exactly one side (AM or PM) is incomplete; specific tardiness/undertime rules for the incomplete side per the policy (spec detail: not yet fully encoded)

### A5: Half-day — only AM pair or only PM pair present
- **Inputs:** Either (AM in + AM out) alone, or (PM in + PM out) alone
- **Expected output:** `half_day_flag = true`, deduction computed only for the missing half

### A6: Full absence — zero swipes on a working day
- **Inputs:** No swipes at all
- **Expected output:** Full-day deduction (e.g., 8.5 hours of undertime)

### A7: Lunch-window compliance — AM out and PM in must be in the allowed windows
- **Lunch windows:** AM out 12:00–12:30, PM in 12:30–13:00
- **Inputs — A7a (compliant):** AM out at 12:15, PM in at 12:45 → pass
- **Inputs — A7b (early AM out):** AM out at 11:55 → `lunch_window_compliant = false`; treat as undertime
- **Inputs — A7c (late AM out):** AM out at 12:35 → `lunch_window_compliant = false`; treat as undertime
- **Inputs — A7d (early PM in):** PM in at 12:20 → `lunch_window_compliant = false`; treat as tardiness
- **Inputs — A7e (late PM in):** PM in at 13:05 → `lunch_window_compliant = false`; treat as tardiness
- **Expected output:** `lunch_window_compliant` boolean; corresponding minute adjustments

### A8: Excess or ambiguous swipes — more than 4 punches in a day
- **Inputs:** 5+ swipes in the calendar day (e.g., nervous double-taps: 08:00, 08:01 AM in; 12:15, 12:16 AM out; 13:00 PM in; 17:00 PM out)
- **Expected output:** Pairing algorithm must be deterministic and handle the edge cases:
  - A8a: Consecutive duplicates (same minute, or within seconds) — treat as one punch
  - A8b: Non-consecutive extra punches — choose greedily (first in/out pair valid, later ones ignored or trigger a flag)
  - A8c: Result must be reproducible; `rule_trace` must show which swipes were selected and why

### A9: Out-of-order or ambiguous single punches
- **Inputs:** A single mid-day punch (e.g., 12:45) with no other swipes—could be AM out, could be PM in, could be neither
- **Expected output:** Must fail gracefully or apply a deterministic rule (e.g., treat as PM in if time > 12:00, AM out if < 12:00)

---

## Section B: Schedule-Type Scenarios

### B1: Friday Flexitime — `muslim_flag` employees
- **Policy (MO 0256):** Employees with `muslim_flag = true` are eligible; work envelope is 07:00–19:00, 40-hour workweek (slightly shortened week to accommodate Friday prayer).
- **Inputs — B1a (compliant flexitime):** Employee has `muslim_flag = true`; clocks in at 07:30 and out at 17:30 (40-hour week total) with standard lunch window
- **Expected output:** No tardiness/undertime deductions; lunch windows adjusted or waived per policy
- **Inputs — B1b (envelope violation):** Swipe before 07:00 or after 19:00
- **Expected output:** Deduction applied per standard rules

### B2: Non-working days — weekends, holidays, special non-working days
- **Inputs:** A swipe lands on a `calendar_days` record with `day_type = 'weekend'`, `'holiday'`, or `'special_non_working'`
- **Expected output:** No deduction computed, regardless of swipes present; typically no `DailyAttendanceRecord` written, or written with zero deduction and a notation
- **Variant:** Swipes on a holiday that is being worked (premium pay scenario — out of scope for deductions, but must not incorrectly penalize)

### B3: Calendar edge — missing date in calendar table
- **Inputs:** A swipe date is not found in `calendar_days` (e.g., table only covers 2026-07-01 to 2026-07-31, but a punch arrives 2026-08-01)
- **Expected output:** Should fail loudly (not default to `working` silently); raise an exception or log a dead-letter record; block recompute until calendar is populated

---

## Section C: Employment-Type Bifurcation (Regular)

### C1: Regular employee — full deduction on any absence/lateness
- **Inputs:** Employee with `employment_type = 'PERMANENT'`
- **Expected output:** Deviation minutes converted to deduction immediately (no buffer)
- **Related:** C1a (leave-credit deduction), C1b (salary deduction) — both follow the same rule; the choice between them is Manual Override module's job, not ACM's.


