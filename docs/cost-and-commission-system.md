# Cost & Commission System — Complete Reference

> **Currency:** All monetary values are integers in **XOF** (no sub-unit).
> **Rates:** Commission rates are stored as decimals, e.g. `0.0150` = 1.5 %.
> **Rounding:** All intermediate divisions use `round()`. Never `floor()` or `ceil()` unless explicitly noted (break-even revenue uses `ceil()`).

---

## Table of Contents

1. [Vehicle Costs](#1-vehicle-costs)
2. [Monthly Fixed Costs](#2-monthly-fixed-costs)
3. [ABC Cost Summary (Exploitation Page)](#3-abc-cost-summary-exploitation-page)
4. [CarLoad ABC Profitability](#4-carload-abc-profitability)
5. [Commission System](#5-commission-system)
6. [How the Three Systems Relate](#6-how-the-three-systems-relate)
7. [Class & File Index](#7-class--file-index)

---

## 1. Vehicle Costs

### 1.1 Purpose

Each vehicle has a **static monthly cost profile** (insurance, maintenance, etc.) that is configured once and does not vary month to month unless manually updated. From this monthly profile, two derived values are computed on the fly:

- `total_monthly_fixed_cost` — the full monthly cost for planning and budget summaries.
- `daily_fixed_cost` — used to prorate cost across individual CarLoad trips.

Fuel is **not** part of the vehicle profile. It is tracked per trip via `CarLoadFuelEntry` (actual receipts).

---

### 1.2 Model: `Vehicle`

**File:** `app/Models/Vehicle.php`

| Column | Type | Description |
|---|---|---|
| `name` | string | Display name (e.g. "Ford Transit - Équipe A") |
| `plate_number` | string\|null | Vehicle registration plate |
| `insurance_monthly` | integer (XOF) | Monthly insurance premium |
| `maintenance_monthly` | integer (XOF) | Scheduled maintenance reserve |
| `repair_reserve_monthly` | integer (XOF) | Unplanned repair reserve |
| `depreciation_monthly` | integer (XOF) | Capital depreciation (purchase price ÷ useful life ÷ 12) |
| `driver_salary_monthly` | integer (XOF) | Driver's fixed monthly salary |
| `working_days_per_month` | integer | How many days per month this vehicle operates |
| `notes` | string\|null | Free-text notes |

**Computed attributes (not stored in DB):**

```
total_monthly_fixed_cost = insurance_monthly
                         + maintenance_monthly
                         + repair_reserve_monthly
                         + depreciation_monthly
                         + driver_salary_monthly

daily_fixed_cost = round(total_monthly_fixed_cost / working_days_per_month)
                 = 0 when working_days_per_month = 0
```

---

### 1.3 Service: `AbcVehicleCostService`

**File:** `app/Services/Abc/AbcVehicleCostService.php`

This service operates on **CarLoads**, not on the Vehicle model directly. It answers: *"How much did this specific trip cost?"*

#### `computeDailyFixedCost(Vehicle $vehicle): int`
Returns `Vehicle::daily_fixed_cost`. Provided as a service method for testability — delegates to the model attribute.

#### `computeFixedCostForCarLoad(CarLoad $carLoad): int`
```
fixed_cost = daily_rate × trip_duration_days
```
- `daily_rate` comes from `CarLoad::fixed_daily_cost` (a **snapshot** frozen when the vehicle was assigned to the CarLoad). This means historical costs are accurate even if the vehicle's monthly rates change later.
- Falls back to live `computeDailyFixedCost($carLoad->vehicle)` if no snapshot exists.
- `trip_duration_days = max(1, diffInDays(load_date, return_date ?? today))`.

> **Why snapshot?** Vehicle cost rates may be updated at any time. Locking the daily rate at assignment time prevents retroactive changes to closed CarLoads.

#### `computeFuelCostForCarLoad(CarLoad $carLoad): int`
```
fuel_cost = SUM(CarLoadFuelEntry.amount) for this CarLoad
```
Actual receipts entered during the trip. No proration or estimate involved.

#### `computeTotalVehicleCostForCarLoad(CarLoad $carLoad): int`
```
total_vehicle_cost = fixed_cost + fuel_cost
```

#### `computeDailyTotalCostForCarLoad(CarLoad $carLoad): int`
```
daily_total = round(total_vehicle_cost / trip_duration_days)
```
Useful for comparing cost efficiency across trips of different lengths.

---

## 2. Monthly Fixed Costs

### 2.1 Purpose

Monthly fixed costs are the **business overhead** that exists regardless of how many trips are run. They are entered manually each month and then **distributed equally across all active vehicles** when the month is finalized.

Two cost pools exist:

| Pool | Description | Sub-categories |
|---|---|---|
| `storage` | Warehouse occupancy costs | Loyer dépôt, Électricité, Wifi, Gardiennage |
| `overhead` | Company-wide overhead | Salaire manager, Intérêts bancaires, Pertes/casse, Autre |

---

### 2.2 Enums

**`MonthlyFixedCostPool`** (`app/Enums/MonthlyFixedCostPool.php`)

| Case | Value | Label |
|---|---|---|
| `Storage` | `storage` | Stockage |
| `Overhead` | `overhead` | Frais généraux |

**`MonthlyFixedCostSubCategory`** (`app/Enums/MonthlyFixedCostSubCategory.php`)

Each sub-category belongs to exactly one pool via `pool(): MonthlyFixedCostPool`.

| Case | Value | Pool | Label |
|---|---|---|---|
| `WarehouseRent` | `warehouse_rent` | storage | Loyer dépôt |
| `Electricity` | `electricity` | storage | Électricité |
| `Wifi` | `wifi` | storage | Wifi |
| `Security` | `security` | storage | Gardiennage |
| `ManagerSalary` | `manager_salary` | overhead | Salaire manager |
| `BankInterest` | `bank_interest` | overhead | Intérêts bancaires |
| `LossesBreakage` | `losses_breakage` | overhead | Pertes / casse |
| `Other` | `other` | overhead | Autre |

> **Rule:** Sub-category determines pool automatically. There is no UI field for pool — it is derived from the selected sub-category. The controller auto-populates `cost_pool` from `sub_category` before persisting.

---

### 2.3 Model: `MonthlyFixedCost`

**File:** `app/Models/MonthlyFixedCost.php`

| Column | Type | Description |
|---|---|---|
| `cost_pool` | enum (MonthlyFixedCostPool) | Derived from sub_category |
| `sub_category` | enum (MonthlyFixedCostSubCategory) | The specific cost type |
| `amount` | integer (XOF) | Total amount for the month |
| `label` | string\|null | Optional free-text label |
| `period_year` | integer | e.g. `2026` |
| `period_month` | integer | 1–12 |
| `per_vehicle_amount` | integer\|null | Set during finalization: `round(amount / active_vehicle_count)` |
| `active_vehicle_count` | integer\|null | How many vehicles were active that month (set at finalization) |
| `finalized_at` | datetime\|null | Non-null = this entry is locked |
| `notes` | string\|null | Internal notes |

**Key rule — immutability after finalization:** `isFinalized()` returns `true` when `finalized_at` is set. The controller rejects all update and delete attempts on finalized records.

---

### 2.4 Service: `AbcFixedCostDistributionService`

**File:** `app/Services/Abc/AbcFixedCostDistributionService.php`

This service handles two responsibilities:

1. **Finalization** — stamping `per_vehicle_amount` on each entry for a given month.
2. **Allocation** — answering *"how much fixed cost burden does this CarLoad carry?"*

#### Finalization — `finalizeMonth(int $year, int $month): void`

```
active_vehicle_count = COUNT(DISTINCT vehicle_id) from CarLoads
                       where vehicle_id IS NOT NULL
                       AND load_date in (year, month)

per_vehicle_amount = round(amount / active_vehicle_count)   (for each entry)
```

After finalization:
- `per_vehicle_amount` and `active_vehicle_count` are stored on each `MonthlyFixedCost` row.
- `finalized_at` is set to `now()`.
- These values are **frozen** — adding new CarLoads or changing the vehicle fleet does not affect them retroactively.
- Only entries with `finalized_at = null` are processed (safe to call multiple times).

#### Allocation — `computeAllocatedFixedCostsForCarLoad(CarLoad $carLoad): CarLoadFixedCostAllocationDTO`

Distributes the vehicle's share of each pool across the vehicle's CarLoads in that month:

```
per_vehicle_total (storage) = SUM(per_vehicle_amount) for finalized storage entries in month

carload_allocation (storage) = round(per_vehicle_total / carload_count_for_vehicle_this_month)
```

Same formula for `overhead` pool.

**Fallback:** If the current month is not yet finalized, the previous month's `per_vehicle_amount` is used as an estimate. The `CarLoadProfitabilityDTO.isMonthFinalized` flag communicates this uncertainty to the UI.

Returns a `CarLoadFixedCostAllocationDTO` with:
- `storageAllocation` — XOF share of storage costs
- `overheadAllocation` — XOF share of overhead costs
- `total()` — sum of both

---

## 3. ABC Cost Summary (Exploitation Page)

### 3.1 Purpose

The **"Coûts d'exploitation"** page gives management a monthly overview of **all running costs** combined:

1. Monthly fixed costs (from `MonthlyFixedCost` entries for the selected period)
2. Commercial salaries (current values from all `Commercial` records)
3. Vehicle monthly costs (current values from all `Vehicle` records)

And from those, it computes:
- Daily cost per category
- Daily total cost across all categories
- **Break-even CA** — the minimum daily revenue the business must achieve to cover its costs, derived from the historical average gross margin

> **Critical design decision:** All arithmetic lives in `AbcCostSummaryService` (PHP). The Vue frontend performs **zero calculations** — it only renders pre-computed values from the server. This ensures that numbers shown in the UI can be fully tested and verified independently of the browser.

---

### 3.2 Service: `AbcCostSummaryService`

**File:** `app/Services/Abc/AbcCostSummaryService.php`

Single public method: `computeForPeriod(int $year, int $month): AbcMonthlyCostSummaryDTO`

#### Step 1 — Monthly fixed costs for the period
```
fixed_costs_total = SUM(amount) from MonthlyFixedCost
                    WHERE period_year = $year AND period_month = $month
```

#### Step 2 — Commercial salaries (current, all commercials)
```
commercial_salaries_total = SUM(salary) from commercials
```
This is always the **current** salary, not a historical snapshot. It represents the monthly salary burden regardless of period.

#### Step 3 — Vehicle aggregates
```
vehicle_costs_total         = SUM(vehicle.total_monthly_fixed_cost)  for all vehicles
daily_vehicle_costs         = SUM(vehicle.daily_fixed_cost)          for all vehicles
total_working_days          = SUM(vehicle.working_days_per_month)     for all vehicles
average_working_days        = round(total_working_days / vehicle_count)
```

> **Important:** `daily_vehicle_costs` is computed as the **sum of each vehicle's own daily rate**, not `vehicle_costs_total / average_working_days`. This is because each vehicle divides by its own `working_days_per_month`, so vehicles that work fewer days correctly show a higher daily cost.

**Fallback:** When no vehicles exist, `average_working_days_per_month = 26`.

#### Step 4 — Daily costs for fixed costs and salaries
```
daily_fixed_costs          = round(fixed_costs_total / average_working_days)
daily_commercial_salaries  = round(commercial_salaries_total / average_working_days)
```

Fixed costs and salaries are monthly totals without their own "working days" field, so the vehicle fleet's average is used as a proxy for the number of billable working days in the month.

#### Step 5 — Grand total and daily total
```
grand_total              = fixed_costs_total + commercial_salaries_total + vehicle_costs_total
daily_total_overall_cost = daily_fixed_costs + daily_commercial_salaries + daily_vehicle_costs
```

#### Step 6 — Break-even (all-time invoice data)
```
total_invoiced_revenue   = SUM(total_amount)            from sales_invoices (ALL TIME)
total_estimated_profit   = SUM(total_estimated_profit)  from sales_invoices (ALL TIME)

average_gross_margin_rate = round(total_estimated_profit / total_invoiced_revenue, 4)

daily_sales_required     = round(daily_total_overall_cost / average_gross_margin_rate)
```

`daily_sales_required_to_cover_costs` is `null` when:
- No invoices exist yet (`total_invoiced_revenue = 0`)
- The margin rate is 0 (impossible to break even by selling more)

---

### 3.3 DTOs

#### `AbcMonthlyCostSummaryDTO`
**File:** `app/Data/Abc/AbcMonthlyCostSummaryDTO.php`

| Property | Type | Description |
|---|---|---|
| `periodYear` | int | The requested year |
| `periodMonth` | int | The requested month (1–12) |
| `fixedCostsTotal` | int | Sum of MonthlyFixedCost.amount for period |
| `commercialSalariesTotal` | int | Sum of all Commercial.salary |
| `vehicleCostsTotal` | int | Sum of all Vehicle.total_monthly_fixed_cost |
| `dailyBreakdown` | AbcDailyCostBreakdownDTO | Daily cost breakdown |
| `breakEven` | AbcBreakEvenDTO | Break-even analysis |

Methods: `grandTotal(): int` = sum of three monthly totals.

#### `AbcDailyCostBreakdownDTO`
**File:** `app/Data/Abc/AbcDailyCostBreakdownDTO.php`

| Property | Type | Description |
|---|---|---|
| `dailyFixedCosts` | int | fixed_costs_total ÷ average_working_days |
| `dailyCommercialSalaries` | int | commercial_salaries_total ÷ average_working_days |
| `dailyVehicleCosts` | int | SUM(vehicle.daily_fixed_cost) |
| `averageWorkingDaysPerMonth` | int | Average of vehicle.working_days_per_month, fallback 26 |

Methods: `dailyTotalOverallCost(): int` = sum of three daily fields.

#### `AbcBreakEvenDTO`
**File:** `app/Data/Abc/AbcBreakEvenDTO.php`

| Property | Type | Description |
|---|---|---|
| `averageGrossMarginRate` | float | SUM(profit) / SUM(revenue) across all invoices. 0.0 if no data. |
| `dailySalesRequiredToCoverCosts` | int\|null | daily_total ÷ margin_rate. null if margin = 0 or no data. |
| `totalInvoicedRevenue` | int | Raw SUM(total_amount) for context |
| `totalEstimatedProfit` | int | Raw SUM(total_estimated_profit) for context |

Methods: `hasEnoughDataForBreakEven(): bool` — true only when rate > 0 and result is non-null.

---

### 3.4 Controller: `MonthlyFixedCostController`

**File:** `app/Http/Controllers/MonthlyFixedCostController.php`

The `index(Request $request)` action:
1. Reads `year` and `month` from query params (defaults to `now()->year` / `now()->month`).
2. Calls `AbcCostSummaryService::computeForPeriod($year, $month)` and passes `$costSummary->toArray()` to Inertia.
3. Also passes the raw `costs`, `commerciaux`, and `vehicles` arrays for the CRUD tabs (Coûts fixes, Salaires, Véhicules).

When the user changes the period selector in the Vue page, `router.get()` is called with `{ year, month }` query params, triggering a full Inertia reload and fresh server-side computation.

---

## 4. CarLoad ABC Profitability

### 4.1 Purpose

Beyond the business-wide monthly summary, each **individual CarLoad** has its own profitability analysis showing how revenue, gross margin, and all allocated costs combine into a net profit figure.

---

### 4.2 Service: `AbcCarLoadProfitabilityService`

**File:** `app/Services/Abc/AbcCarLoadProfitabilityService.php`

Single public method: `computeProfitability(CarLoad $carLoad): CarLoadProfitabilityDTO`

```
total_revenue      = SUM(sales_invoices.total_amount)             WHERE car_load_id = $id
total_gross_profit = SUM(sales_invoices.total_estimated_profit)   WHERE car_load_id = $id
vehicle_fixed_cost = AbcVehicleCostService::computeFixedCostForCarLoad($carLoad)
vehicle_fuel_cost  = AbcVehicleCostService::computeFuelCostForCarLoad($carLoad)
storage_allocation = AbcFixedCostDistributionService::...storageAllocation
overhead_allocation= AbcFixedCostDistributionService::...overheadAllocation
```

---

### 4.3 DTO: `CarLoadProfitabilityDTO`

**File:** `app/Data/Abc/CarLoadProfitabilityDTO.php`

| Property / Method | Type | Formula |
|---|---|---|
| `totalRevenue` | int | SUM(invoices.total_amount) |
| `totalGrossProfit` | int | SUM(invoices.total_estimated_profit) |
| `vehicleFixedCost` | int | daily_rate × trip_days |
| `vehicleFuelCost` | int | SUM(fuel_entries.amount) |
| `storageAllocation` | int | per_vehicle_amount / carloads_this_month |
| `overheadAllocation` | int | per_vehicle_amount / carloads_this_month |
| `isMonthFinalized` | bool | Whether fixed costs for this month are locked |
| `totalVehicleCost()` | int | vehicleFixedCost + vehicleFuelCost |
| `totalFixedCostBurden()` | int | totalVehicleCost + storage + overhead |
| `netProfit()` | int | totalGrossProfit − totalFixedCostBurden |
| `grossMarginPercent()` | float | totalGrossProfit / totalRevenue × 100 |
| `netMarginPercent()` | float | netProfit / totalRevenue × 100 |
| `breakEvenRevenue()` | int | ceil(totalFixedCostBurden / gross_margin_rate) |
| `remainingRevenueToBreakEven()` | int | max(0, breakEvenRevenue − totalRevenue) |
| `isDeficit()` | bool | netProfit < 0 |

---

### 4.4 DTO: `CarLoadFixedCostAllocationDTO`

**File:** `app/Data/Abc/CarLoadFixedCostAllocationDTO.php`

| Property | Type | Description |
|---|---|---|
| `storageAllocation` | int | XOF share of storage pool |
| `overheadAllocation` | int | XOF share of overhead pool |

Method: `total(): int` = sum of both. Static factory: `zero()` used when no load_date.

---

## 5. Commission System

### 5.1 Overview

The commission system computes how much each **commercial** earns for a given **work period** (a date range). The computation has four components that are summed together:

```
net_commission = base_commission + basket_bonus + objective_bonus − total_penalties
               (minimum 0 — commission cannot go negative)
```

---

### 5.2 Rate Resolution Priority Chain

**Service:** `CommissionRateResolverService` (`app/Services/Commission/CommissionRateResolverService.php`)

When determining the commission rate for a `(commercial, product)` pair, the following priority chain is applied (first match wins):

| Priority | Source | Model | Specificity |
|---|---|---|---|
| 1 | Commercial × product override | `CommercialProductCommissionRate` | Most specific |
| 2 | Commercial × category override | `CommercialCategoryCommissionRate` | Per commercial per category |
| 3 | Category default rate | `ProductCategory.commission_rate` | Applies to all commercials |
| 4 | Fallback | — | `0.0` — no commission earned |

**Key method:** `resolveRateForCommercialAndProduct(Commercial, Product): float`

Returns a float, e.g. `0.0150` for 1.5 %. If the product has no `product_category_id`, priorities 2 and 3 are skipped and it falls straight to 0.0.

> **N+1 prevention:** When `$product->productCategory` is already eager-loaded, the resolver uses the cached relation via `$product->relationLoaded('productCategory')` instead of issuing an additional query.

---

### 5.3 Rate Models

#### `CommercialProductCommissionRate`
`app/Models/CommercialProductCommissionRate.php`

| Column | Description |
|---|---|
| `commercial_id` | FK → commercials |
| `product_id` | FK → products |
| `rate` | decimal(6,4) — e.g. `0.0200` |

One row per (commercial, product) pair. Most specific override.

#### `CommercialCategoryCommissionRate`
`app/Models/CommercialCategoryCommissionRate.php`

| Column | Description |
|---|---|
| `commercial_id` | FK → commercials |
| `product_category_id` | FK → product_categories |
| `rate` | decimal(6,4) |

One row per (commercial, category) pair.

#### `ProductCategory.commission_rate`
`app/Models/ProductCategory.php`

Column `commission_rate decimal(6,4) nullable` — the default rate for all commercials who have no specific override for this category. `null` means no default commission configured.

---

### 5.4 Base Commission Calculation

**Service:** `CommissionCalculatorService` (`app/Services/Commission/CommissionCalculatorService.php`)

**Per payment, per product:**

```
item_subtotal         = invoice_item.price × invoice_item.quantity
product_share         = item_subtotal / invoice.total_amount
allocated_amount      = round(payment.amount × product_share)
commission_amount     = round(allocated_amount × resolved_rate)
```

**Why allocate proportionally?** A single payment may partially pay a multi-product invoice. Rather than assigning the full payment to every product, the payment is split proportionally by each product's revenue share within the invoice. This correctly attributes commission to the products that generated the revenue being paid.

**Output:** `CommissionPaymentLineData[]` — one entry per invoice item, even if `commission_amount = 0` (so the product can still count towards basket checks).

**`CommissionPaymentLine` model** (`app/Models/CommissionPaymentLine.php`):

| Column | Description |
|---|---|
| `commission_id` | FK → commissions |
| `payment_id` | FK → payments |
| `product_id` | FK → products |
| `rate_applied` | The resolved rate at computation time (snapshot) |
| `payment_amount_allocated` | XOF portion of this payment allocated to this product |
| `commission_amount` | XOF commission earned from this line |

---

### 5.5 Work Period

**Model:** `CommercialWorkPeriod` (`app/Models/CommercialWorkPeriod.php`)

A work period is the time window within which a commercial's activity is measured. It is the **hub** that all commission-related records point to.

| Column | Description |
|---|---|
| `commercial_id` | FK → commercials |
| `period_start_date` | First day of the period (stored at midnight) |
| `period_end_date` | Last day of the period (stored at midnight) |

**Relationships:**
- `hasOne(Commission)` — the computed result
- `hasMany(CommercialObjectiveTier)` — CA thresholds configured for this period
- `hasMany(CommercialPenalty)` — deductions applied during this period

**Overlap guard:** `hasOverlappingPeriodForCommercial(commercialId, period)` prevents two periods for the same commercial from sharing any dates. Two periods overlap iff `existing_start <= new_end AND existing_end >= new_start`.

---

### 5.6 Basket Bonus

Configured via `CommissionPeriodSetting` which holds:
- `required_category_ids` — array of `ProductCategory` IDs that must all be sold
- `basket_multiplier` — multiplier applied to `base_commission` (e.g. `1.30` = 30 % bonus)

**Logic:**
```
basket_achieved = all required_category_ids are in the set of sold category IDs for the period
basket_bonus    = round(base_commission × (basket_multiplier − 1))  if basket_achieved
                = 0                                                   otherwise
```

The sold category IDs are collected while iterating over payment lines — a category is considered sold if any `CommissionPaymentLine` in the period references a product belonging to that category.

> **Example:** basket_multiplier = 1.30, base_commission = 100 000 XOF
> basket_bonus = round(100 000 × 0.30) = 30 000 XOF
> total = 130 000 XOF

---

### 5.7 Objective Tiers (CA-based bonus)

**Model:** `CommercialObjectiveTier` (`app/Models/CommercialObjectiveTier.php`)

| Column | Type | Description |
|---|---|---|
| `commercial_work_period_id` | FK | Belongs to a specific work period |
| `tier_level` | integer | Ordering key (1 = lowest, higher = better) |
| `ca_threshold` | integer (XOF) | Minimum total encaissement to unlock this tier |
| `bonus_amount` | integer (XOF) | Fixed bonus paid if this tier is the highest achieved |

**Rule — non-cumulative, winner-takes-all:**

```
total_encaissement = SUM(payments.amount) for the commercial in the period date range

highest_achieved_tier = tier where ca_threshold <= total_encaissement
                        ORDER BY tier_level DESC  LIMIT 1

objective_bonus = highest_achieved_tier.bonus_amount ?? 0
```

Only **one tier** pays out — the highest tier whose threshold the commercial has crossed. Lower tiers are not summed.

> **Example:** Tiers are T1 (CA ≥ 500 000, bonus 10 000), T2 (CA ≥ 1 000 000, bonus 25 000), T3 (CA ≥ 2 000 000, bonus 60 000).
> If total_encaissement = 1 500 000 → T2 is the highest achieved → objective_bonus = 25 000 XOF.
> T1's 10 000 is **not** added on top.

---

### 5.8 Penalties

**Model:** `CommercialPenalty` (`app/Models/CommercialPenalty.php`)

| Column | Type | Description |
|---|---|---|
| `commercial_work_period_id` | FK | Belongs to a specific work period |
| `amount` | integer (XOF) | Amount to deduct |
| `reason` | string | Explanation (displayed in UI) |
| `created_by_user_id` | FK\|null | Who created the penalty |

```
total_penalties = SUM(CommercialPenalty.amount) for the work period
```

---

### 5.9 Full Commission Formula

**Service:** `CommercialWorkPeriodService` (`app/Services/Commission/CommercialWorkPeriodService.php`)

```
net_commission = max(0,
    base_commission          (sum of all CommissionPaymentLine.commission_amount)
  + basket_bonus             (base × (multiplier − 1) if all categories sold, else 0)
  + objective_bonus          (highest achieved tier bonus, or 0)
  − total_penalties          (sum of CommercialPenalty.amount)
)
```

#### `computeOrRefreshCommissionForPeriod(Commercial, CommissionPeriodData): Commission`

Full computation/recomputation flow:

1. Find or create `CommercialWorkPeriod` for (commercial, start_date, end_date).
2. **Guard:** Reject if existing commission is finalized.
3. **Guard:** Reject if dates overlap with another existing period (only on new creation).
4. Inside `DB::transaction()`:
   - Delete existing `CommissionPaymentLine` rows (clean recompute).
   - Collect all `Payment` records for the commercial within the period.
   - For each payment, call `CommissionCalculatorService::computePaymentLinesForCommercial()`.
   - Accumulate `base_commission` and `sold_category_ids`.
   - Evaluate basket bonus using `CommissionPeriodSetting`.
   - Evaluate objective bonus using `CommercialObjectiveTier`.
   - Sum penalties.
   - `updateOrCreate` the `Commission` record.
   - Bulk-insert all `CommissionPaymentLine` rows.
5. Return the fresh `Commission` model.

#### `finalizeCommission(Commission): Commission`

Sets `is_finalized = true` and `finalized_at = now()`. Throws `RuntimeException` if already finalized. **Irreversible** — finalized commissions cannot be recomputed.

---

### 5.10 Commission Model

**Model:** `Commission` (`app/Models/Commission.php`)

| Column | Type | Description |
|---|---|---|
| `commercial_work_period_id` | FK | Belongs to one work period |
| `base_commission` | integer (XOF) | Sum of all payment line commission amounts |
| `basket_bonus` | integer (XOF) | 0 or round(base × (multiplier − 1)) |
| `objective_bonus` | integer (XOF) | Highest achieved tier bonus or 0 |
| `total_penalties` | integer (XOF) | Sum of penalties for the period |
| `net_commission` | integer (XOF) | max(0, base + basket + objective − penalties) |
| `basket_achieved` | boolean | Whether all required categories were sold |
| `basket_multiplier_applied` | decimal(3,2)\|null | The multiplier used (e.g. 1.30) |
| `achieved_tier_level` | integer\|null | The tier_level of the highest achieved tier |
| `is_finalized` | boolean | Whether this record is locked |
| `finalized_at` | datetime\|null | When it was finalized |

---

### 5.11 Artisan Command

**`bayal:calculate-commissions`** (`app/Console/Commands/CalculateCommissions.php`)

```bash
php artisan bayal:calculate-commissions [YYYY-MM] [--commercial=ID]
```

Manual trigger for commission computation. Commissions are **never computed automatically** on payment save — they are always triggered manually via this command or via the UI (Commissions page → Calculer).

---

## 6. How the Three Systems Relate

```
┌─────────────────────────────────────────────────────┐
│                   Monthly Fixed Costs               │
│  MonthlyFixedCost (storage, overhead entries)       │
│  ── finalize ──▶ per_vehicle_amount frozen          │
│                          │                          │
│                          ▼                          │
│          AbcFixedCostDistributionService             │
│          allocates to each CarLoad                  │
└──────────────────────────┬──────────────────────────┘
                           │ storage + overhead allocation
                           ▼
┌─────────────────────────────────────────────────────┐
│                 Vehicle Costs                       │
│  Vehicle (5 monthly cost fields + working_days)     │
│  ── snapshot ──▶ CarLoad.fixed_daily_cost           │
│                          │                          │
│                          ▼                          │
│          AbcVehicleCostService                      │
│          fixed + fuel per CarLoad                   │
└──────────────────────────┬──────────────────────────┘
                           │ vehicle fixed + fuel
                           ▼
┌─────────────────────────────────────────────────────┐
│            CarLoad ABC Profitability                │
│  Revenue + Gross Profit (from SalesInvoices)        │
│  − Vehicle Cost                                     │
│  − Fixed Cost Burden (storage + overhead)           │
│  = Net Profit  ──▶  CarLoadProfitabilityDTO         │
└─────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────┐
│           ABC Cost Summary (Business-wide)          │
│  AbcCostSummaryService::computeForPeriod()          │
│  = Fixed Costs (period) + Salaries + Vehicles       │
│  → daily costs + break-even CA                     │
│  → AbcMonthlyCostSummaryDTO (no math in frontend)  │
└─────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────┐
│                 Commission System                   │
│  CommercialWorkPeriod (date range per commercial)   │
│  + Payments → CommissionCalculatorService           │
│    → base_commission (rate × allocated amount)      │
│  + BasketBonus (all required categories sold?)      │
│  + ObjectiveBonus (highest CA tier achieved)        │
│  − Penalties                                        │
│  = net_commission  ──▶  Commission model            │
│                                                     │
│  Rate chain: product override > category override   │
│              > category default > 0                 │
└─────────────────────────────────────────────────────┘
```

**Key separations:**

- **Vehicle costs** feed into *per-CarLoad* profitability (trip-level analysis).
- **Monthly fixed costs** also feed into *per-CarLoad* profitability via distribution, AND into the *business-wide* summary via `AbcCostSummaryService`.
- **Commissions** are entirely separate from cost analysis — they concern what to *pay out* to commercials, not the cost of running the business. However, commercial salaries (fixed, not commission) appear in the cost summary.
- **Salaries** (`Commercial.salary`) are **fixed monthly costs** included in the cost summary. The **commission** (`Commission.net_commission`) is a *variable* payout computed separately and is NOT included in the cost summary.

---

## 7. Class & File Index

### Models

| Model | File | Purpose |
|---|---|---|
| `Vehicle` | `app/Models/Vehicle.php` | Vehicle with monthly cost profile |
| `MonthlyFixedCost` | `app/Models/MonthlyFixedCost.php` | One overhead cost entry per month |
| `Commercial` | `app/Models/Commercial.php` | Salesperson with fixed salary |
| `CommercialWorkPeriod` | `app/Models/CommercialWorkPeriod.php` | Date range for one commission computation |
| `Commission` | `app/Models/Commission.php` | Computed commission result for a work period |
| `CommissionPaymentLine` | `app/Models/CommissionPaymentLine.php` | Per-product line detail of a commission |
| `CommercialObjectiveTier` | `app/Models/CommercialObjectiveTier.php` | CA threshold → fixed bonus tier |
| `CommercialPenalty` | `app/Models/CommercialPenalty.php` | Deduction applied to a work period |
| `CommercialProductCommissionRate` | `app/Models/CommercialProductCommissionRate.php` | Rate override for (commercial, product) |
| `CommercialCategoryCommissionRate` | `app/Models/CommercialCategoryCommissionRate.php` | Rate override for (commercial, category) |
| `ProductCategory` | `app/Models/ProductCategory.php` | Category with optional default commission_rate |

### Services

| Service | File | Purpose |
|---|---|---|
| `AbcVehicleCostService` | `app/Services/Abc/AbcVehicleCostService.php` | Per-CarLoad vehicle cost (fixed + fuel) |
| `AbcFixedCostDistributionService` | `app/Services/Abc/AbcFixedCostDistributionService.php` | Finalize month, allocate to CarLoads |
| `AbcCostSummaryService` | `app/Services/Abc/AbcCostSummaryService.php` | Business-wide monthly cost summary |
| `AbcCarLoadProfitabilityService` | `app/Services/Abc/AbcCarLoadProfitabilityService.php` | Full profitability for one CarLoad |
| `CommissionRateResolverService` | `app/Services/Commission/CommissionRateResolverService.php` | Resolve rate for (commercial, product) |
| `CommissionCalculatorService` | `app/Services/Commission/CommissionCalculatorService.php` | Payment lines → commission amounts |
| `CommercialWorkPeriodService` | `app/Services/Commission/CommercialWorkPeriodService.php` | Full commission computation + finalization |

### DTOs

| DTO | File | Purpose |
|---|---|---|
| `AbcMonthlyCostSummaryDTO` | `app/Data/Abc/AbcMonthlyCostSummaryDTO.php` | Top-level monthly cost summary |
| `AbcDailyCostBreakdownDTO` | `app/Data/Abc/AbcDailyCostBreakdownDTO.php` | Daily cost per category + total |
| `AbcBreakEvenDTO` | `app/Data/Abc/AbcBreakEvenDTO.php` | Break-even analysis from invoice history |
| `CarLoadProfitabilityDTO` | `app/Data/Abc/CarLoadProfitabilityDTO.php` | Per-CarLoad net profitability |
| `CarLoadFixedCostAllocationDTO` | `app/Data/Abc/CarLoadFixedCostAllocationDTO.php` | Storage + overhead share for one CarLoad |

### Enums

| Enum | File | Values |
|---|---|---|
| `MonthlyFixedCostPool` | `app/Enums/MonthlyFixedCostPool.php` | `storage`, `overhead` |
| `MonthlyFixedCostSubCategory` | `app/Enums/MonthlyFixedCostSubCategory.php` | 8 values across 2 pools |

### Tests

| Test | File | Covers |
|---|---|---|
| `AbcCostSummaryServiceTest` | `tests/Feature/Abc/AbcCostSummaryServiceTest.php` | 28 tests — all arithmetic in AbcCostSummaryService |
| `CommissionRateResolverServiceTest` | `tests/Feature/Commission/CommissionRateResolverServiceTest.php` | Rate priority chain (12 tests) |
| `CommissionCalculatorServiceTest` | `tests/Feature/Commission/CommissionCalculatorServiceTest.php` | Payment line allocation |
| `MonthlyCommissionServiceTest` | `tests/Feature/Commission/MonthlyCommissionServiceTest.php` | Full commission computation flow |
| `CommissionPeriodValidationTest` | `tests/Feature/Commission/CommissionPeriodValidationTest.php` | Overlap guards, finalization guards |
