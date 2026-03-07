# CLAUDE.md — Bayal Distribution Backend

## Project Overview

**Bayal** is a financial-grade sales distribution management system for a field sales operation (West African market, currency: XOF). Salespersons travel in teams with vehicles loaded with products, sell to customers on routes, and settle accounts at the end of each car load cycle.

**This is a financial application. Errors in calculations directly translate to real money losses.**

---

## Tech Stack

| Layer        | Technology                              |
|--------------|-----------------------------------------|
| Backend      | Laravel 10, PHP 8.1+                    |
| Frontend     | Vue.js 3, Inertia.js, Vuetify 3         |
| Auth         | Laravel Breeze (web) + Sanctum (API)    |
| Database     | MySQL (production), SQLite (tests)      |
| PDF          | barryvdh/laravel-dompdf                 |
| JS Routing   | Ziggy                                   |
| Testing      | PHPUnit 10                              |

---

## Domain Model

### Core Entities

| Model                    | Purpose                                                                 |
|--------------------------|-------------------------------------------------------------------------|
| `Commercial`             | Salesperson, belongs to a Team, has a hashed secret code                |
| `Team`                   | Group of commercials sharing a CarLoad                                  |
| `CarLoad`                | Vehicle stocked with products — states: `LOADING → ACTIVE → UNLOADED`  |
| `CarLoadItem`            | One product line in a CarLoad; tracks `quantity_loaded` and `quantity_left` (FIFO) |
| `CarLoadInventory`       | Physical inventory count performed at end of a CarLoad                  |
| `CarLoadInventoryItem`   | One product line in an inventory count                                  |
| `Product`                | Has optional parent/variant relationship (e.g., case → units); has `cost_price` and `price` |
| `StockEntry`             | Records stock movements in the warehouse                                |
| `Customer`               | Client, organized by Zone → Ligne → Sector; has GPS coordinates         |
| `Vente`                  | Direct sale record (legacy/simple sales)                                |
| `Order` / `OrderItem`    | Structured order flow                                                   |
| `SalesInvoice`           | Invoice generated from orders; linked to payments                       |
| `Payment`                | Payment against an order or sales invoice; tracks profit                |
| `PurchaseInvoice`        | Supplier invoice; triggers stock entries when put in stock              |
| `Caisse`                 | Cash register / treasury; supports transfers between caisses            |
| `CaisseTransaction`      | Individual debit/credit in a caisse                                     |
| `Depense`                | Business expense, categorized by `TypeDepense`                          |
| `Investment`             | Capital investment tracking                                             |
| `VisitBatch`             | Organized customer visit campaign                                       |
| `CustomerVisit`          | Individual visit record within a VisitBatch                             |
| `DeliveryBatch`          | Batched delivery run with a Livreur                                     |
| `Zone` / `Ligne` / `Sector` | Geographic/route organization layers for customers                  |

### Product Variants (Critical)
Products can have a parent-child relationship. A parent product (e.g., a carton) can be split into variant units (e.g., individual packs). Quantities must be converted between parent and child units using `base_quantity`. Conversion logic lives in the `Product` model (`convertQuantityToParentQuantity`, `getFormattedDisplayOfCartonAndParquets`).

### Car Load Stock (Critical — FIFO)
`CarLoad::decreaseStockOfProduct()` consumes stock using FIFO across multiple `CarLoadItem` rows. `CarLoad::increaseStockOfProduct()` puts stock back into the latest item. Any bug here directly causes inventory discrepancies and financial losses.

---

## Architecture Patterns

- **Services layer:** `CarLoadService`, `SalesInvoiceService`, `PaymentService`, `CustomerVisitService` — all business logic lives in services, not controllers.
- **DTO / Data classes:** `app/Data/CarLoadInventory/` — structured result objects for inventory computation. Use DTOs instead of raw arrays for any complex computed result.
- **Custom exceptions:** `InsufficientStockException` for stock failures.
- **Dual interface:** Web (Inertia) for back-office, REST API (`/api/salesperson/`) under Sanctum for the mobile salesperson app.
- **API controllers** live in `App\Http\Controllers\Api\` namespace, separate from web controllers.
- **Transactions:** All multi-step writes must be wrapped in `DB::transaction()`.

---

## ⚠️ TESTING IS NON-NEGOTIABLE

**This application handles real money. A bug in a sum, a conversion, or a stock count is not just a code defect — it is a financial loss.** Tests are the primary safety net.

### Rules

1. **Every feature must have tests.** No feature is complete without a corresponding test. No exceptions.
2. **All calculation logic must be exhaustively tested.** Every formula, every aggregation, every conversion must have dedicated test cases covering:
   - The normal/happy path
   - Zero values
   - Boundary values (e.g., exactly enough stock, exactly zero remaining)
   - Multiple items/iterations (e.g., FIFO across several CarLoadItems)
   - Rounding and decimal precision edge cases
   - Mixed parent + child unit scenarios
   - Scenarios where the result should be exactly zero
   - Negative result prevention / exception throwing
3. **Stock operations must be tested at the unit level.** FIFO decrease, stock increase, insufficient stock exception — each scenario as its own test method.
4. **Inventory aggregation must be tested for every combination.** Parent-only items, child-only items, mixed parent+child items, items from previous car loads, zero quantities, all variants converted.
5. **Financial totals (revenue, profit, payments, caisse balances) must be tested with multiple records** to catch summation errors.
6. **Never trust manual QA alone** for anything involving: stock quantities, money amounts, payment reconciliation, inventory results, or unit conversions.

### Test Structure

```
tests/
├── Unit/
│   └── CarLoadStockTest.php       ← FIFO stock logic
├── Feature/
│   ├── Inventory/
│   │   ├── InventoryAggregationTest.php
│   │   ├── SalesIntegrationTest.php
│   │   ├── InventoryPdfViewTest.php
│   │   └── FullFlowInventoryPdfTest.php
│   └── PurchaseInvoicePutInStockTest.php
```

- Use `DatabaseTransactions` trait to roll back after each test.
- Use the SQLite test database (`.env.testing`).
- Use factories and direct model creation to set up precise scenarios.
- Name test methods descriptively: `test_fifo_decrease_across_multiple_items_and_prevent_oversell()`.

### What Always Needs Tests

| Area                                   | Priority   |
|----------------------------------------|------------|
| CarLoad FIFO stock decrease/increase   | CRITICAL   |
| Product quantity conversion (parent↔child) | CRITICAL |
| Inventory aggregation totals           | CRITICAL   |
| Sales invoice total / profit           | CRITICAL   |
| Payment reconciliation and balances    | CRITICAL   |
| Caisse transaction balances            | CRITICAL   |
| Stock entry on purchase invoice        | HIGH       |
| CarLoad creation from inventory        | HIGH       |
| Vente profit computation               | HIGH       |
| Order total computation                | HIGH       |

---

## Code Conventions

### Backend
- Use descriptive variable and function names, even if longer.
- All multi-step writes wrapped in `DB::transaction()`.
- Throw domain-specific exceptions with French error messages (user-facing) and English ones (developer-facing) where applicable.
- Use DTOs for complex computed results — never return raw associative arrays from services.
- Controllers are thin: validate input, call service, return Inertia/JSON response.

### Frontend
- Vue 3 Composition API.
- Vuetify 3 components.
- Currency formatted as XOF with no decimals.
- Dates formatted as `fr-FR`.
- Descriptive variable and function names.

### Financial Precision
- Prices and amounts are integers (XOF has no sub-unit in practice) unless explicitly required otherwise.
- Quantities involving parent/child conversion may be floats (decimal parent quantities).
- Always use `decimal_parent_quantity` from conversion results for aggregation — never cast to int prematurely.

---

## Key Business Flows

### Car Load Lifecycle
```
Create (LOADING) → Add items (deducts warehouse stock) → Activate (ACTIVE)
→ Salesperson sells (decreases CarLoadItem.quantity_left via FIFO)
→ Inventory count → Close inventory → Unload (UNLOADED)
→ Optionally create next CarLoad from inventory
```

### Sales Flow
```
CarLoad (field stock) → Vente / Order → SalesInvoice → Payment
```

### Purchase Flow
```
PurchaseInvoice (from supplier) → Put in stock → StockEntry → warehouse stock increases
```

### Stock Arithmetic (Inventory Result)
```
result = total_sold + total_returned - total_loaded
```
This result should be ≈ 0. Any non-zero result is a discrepancy that must be investigated. This formula must never change silently — any modification requires updating tests first.

---

## Running Tests

```bash
php artisan test
php artisan test --filter=CarLoadStockTest
php artisan test tests/Feature/Inventory/
```

Test database: `database/database_testing.sqlite` (configured in `.env.testing`).
