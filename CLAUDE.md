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

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4.4
- inertiajs/inertia-laravel (INERTIA) - v2
- laravel/framework (LARAVEL) - v12
- laravel/prompts (PROMPTS) - v0
- laravel/sanctum (SANCTUM) - v4
- tightenco/ziggy (ZIGGY) - v2
- laravel/breeze (BREEZE) - v2
- laravel/mcp (MCP) - v0
- laravel/pint (PINT) - v1
- laravel/sail (SAIL) - v1
- phpunit/phpunit (PHPUNIT) - v11
- @inertiajs/vue3 (INERTIA) - v1
- tailwindcss (TAILWINDCSS) - v3
- vue (VUE) - v3

## Skills Activation

This project has domain-specific skills available. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

- `inertia-vue-development` — Develops Inertia.js v1 Vue client-side applications. Activates when creating Vue pages, forms, or navigation; using Link or router; or when user mentions Vue with Inertia, Vue pages, Vue forms, or Vue navigation.
- `tailwindcss-development` — Styles applications using Tailwind CSS v3 utilities. Activates when adding styles, restyling components, working with gradients, spacing, layout, flex, grid, responsive design, dark mode, colors, typography, or borders; or when the user mentions CSS, styling, classes, Tailwind, restyle, hero section, cards, buttons, or any visual/UI changes.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

- Laravel Boost is an MCP server that comes with powerful tools designed specifically for this application. Use them.

## Artisan

- Use the `list-artisan-commands` tool when you need to call an Artisan command to double-check the available parameters.

## URLs

- Whenever you share a project URL with the user, you should use the `get-absolute-url` tool to ensure you're using the correct scheme, domain/IP, and port.

## Tinker / Debugging

- You should use the `tinker` tool when you need to execute PHP to debug code or query Eloquent models directly.
- Use the `database-query` tool when you only need to read from the database.
- Use the `database-schema` tool to inspect table structure before writing migrations or models.

## Reading Browser Logs With the `browser-logs` Tool

- You can read browser logs, errors, and exceptions using the `browser-logs` tool from Boost.
- Only recent browser logs will be useful - ignore old logs.

## Searching Documentation (Critically Important)

- Boost comes with a powerful `search-docs` tool you should use before trying other approaches when working with Laravel or Laravel ecosystem packages. This tool automatically passes a list of installed packages and their versions to the remote Boost API, so it returns only version-specific documentation for the user's circumstance. You should pass an array of packages to filter on if you know you need docs for particular packages.
- Search the documentation before making code changes to ensure we are taking the correct approach.
- Use multiple, broad, simple, topic-based queries at once. For example: `['rate limiting', 'routing rate limiting', 'routing']`. The most relevant results will be returned first.
- Do not add package names to queries; package information is already shared. For example, use `test resource table`, not `filament 4 test resource table`.

### Available Search Syntax

1. Simple Word Searches with auto-stemming - query=authentication - finds 'authenticate' and 'auth'.
2. Multiple Words (AND Logic) - query=rate limit - finds knowledge containing both "rate" AND "limit".
3. Quoted Phrases (Exact Position) - query="infinite scroll" - words must be adjacent and in that order.
4. Mixed Queries - query=middleware "rate limit" - "middleware" AND exact phrase "rate limit".
5. Multiple Queries - queries=["authentication", "middleware"] - ANY of these terms.

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.

## Constructors

- Use PHP 8 constructor property promotion in `__construct()`.
    - `public function __construct(public GitHub $github) { }`
- Do not allow empty `__construct()` methods with zero parameters unless the constructor is private.

## Type Declarations

- Always use explicit return type declarations for methods and functions.
- Use appropriate PHP type hints for method parameters.

<!-- Explicit Return Types and Method Params -->
```php
protected function isAccessible(User $user, ?string $path = null): bool
{
    ...
}
```

## Enums

- Typically, keys in an Enum should be TitleCase. For example: `FavoritePerson`, `BestLake`, `Monthly`.

## Comments

- Prefer PHPDoc blocks over inline comments. Never use comments within the code itself unless the logic is exceptionally complex.

## PHPDoc Blocks

- Add useful array shape type definitions when appropriate.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/Pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

=== inertia-laravel/v2 rules ===

# Inertia v2

- Use all Inertia features from v1 and v2. Check the documentation before making changes to ensure the correct approach.
- New features: deferred props, infinite scrolling (merging props + `WhenVisible`), lazy loading on scroll, polling, prefetching.
- When using deferred props, add an empty state with a pulsing or animated skeleton.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using the `list-artisan-commands` tool.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

## Database

- Always use proper Eloquent relationship methods with return type hints. Prefer relationship methods over raw queries or manual joins.
- Use Eloquent models and relationships before suggesting raw database queries.
- Avoid `DB::`; prefer `Model::query()`. Generate code that leverages Laravel's ORM capabilities rather than bypassing them.
- Generate code that prevents N+1 query problems by using eager loading.
- Use Laravel's query builder for very complex database operations.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `list-artisan-commands` to check the available options to `php artisan make:model`.

### APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## Controllers & Validation

- Always create Form Request classes for validation rather than inline validation in controllers. Include both validation rules and custom error messages.
- Check sibling Form Requests to see if the application uses array or string based validation rules.

## Authentication & Authorization

- Use Laravel's built-in authentication and authorization features (gates, policies, Sanctum, etc.).

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Queues

- Use queued jobs for time-consuming operations with the `ShouldQueue` interface.

## Configuration

- Use environment variables only in configuration files - never use the `env()` function directly outside of config files. Always use `config('app.name')`, not `env('APP_NAME')`.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== laravel/v12 rules ===

# Laravel 12

- CRITICAL: ALWAYS use `search-docs` tool for version-specific Laravel documentation and updated code examples.
- Since Laravel 11, Laravel has a new streamlined file structure which this project uses.

## Laravel 12 Structure

- In Laravel 12, middleware are no longer registered in `app/Http/Kernel.php`.
- Middleware are configured declaratively in `bootstrap/app.php` using `Application::configure()->withMiddleware()`.
- `bootstrap/app.php` is the file to register middleware, exceptions, and routing files.
- `bootstrap/providers.php` contains application specific service providers.
- The `app\Console\Kernel.php` file no longer exists; use `bootstrap/app.php` or `routes/console.php` for console configuration.
- Console commands in `app/Console/Commands/` are automatically available and do not require manual registration.

## Database

- When modifying a column, the migration must include all of the attributes that were previously defined on the column. Otherwise, they will be dropped and lost.
- Laravel 12 allows limiting eagerly loaded records natively, without external packages: `$query->latest()->limit(10);`.

### Models

- Casts can and likely should be set in a `casts()` method on a model rather than the `$casts` property. Follow existing conventions from other models.

=== pint/core rules ===

# Laravel Pint Code Formatter

- You must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This application uses PHPUnit for testing. All tests must be written as PHPUnit classes. Use `php artisan make:test --phpunit {name}` to create a new test.
- If you see a test using "Pest", convert it to PHPUnit.
- Every time a test has been updated, run that singular test.
- When the tests relating to your feature are passing, ask the user if they would like to also run the entire test suite to make sure everything is still passing.
- Tests should cover all happy paths, failure paths, and edge cases.
- You must not remove any tests or test files from the tests directory without approval. These are not temporary or helper files; these are core to the application.

## Running Tests

- Run the minimal number of tests, using an appropriate filter, before finalizing.
- To run all tests: `php artisan test --compact`.
- To run all tests in a file: `php artisan test --compact tests/Feature/ExampleTest.php`.
- To filter on a particular test name: `php artisan test --compact --filter=testName` (recommended after making a change to a related file).

=== inertia-vue/core rules ===

# Inertia + Vue

Vue components must have a single root element.
- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

=== tailwindcss/core rules ===

# Tailwind CSS

- Always use existing Tailwind conventions; check project patterns before adding new ones.
- IMPORTANT: Always use `search-docs` tool for version-specific Tailwind CSS documentation and updated code examples. Never rely on training data.
- IMPORTANT: Activate `tailwindcss-development` every time you're working with a Tailwind CSS or styling-related task.
</laravel-boost-guidelines>
