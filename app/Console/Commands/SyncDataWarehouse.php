<?php

namespace App\Console\Commands;

use Carbon\CarbonPeriod;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

class SyncDataWarehouse extends Command
{
    protected $signature = 'bayal:sync-datawarehouse
                            {--fresh : Drop and recreate all DW tables before syncing}';

    protected $description = 'Sync OLTP data to the Bayal data warehouse for business intelligence';

    private string $sourceDb;

    private string $dwDb = 'bayal_datawarehouse';

    public function handle(): int
    {
        $this->sourceDb = config('database.connections.mysql.database');

        $this->info("Syncing [{$this->sourceDb}] → [{$this->dwDb}]");
        $this->newLine();

        if ($this->option('fresh')) {
            $this->warn('Dropping all DW tables...');
            $this->dropAllTables();
        }

        $steps = [
            'Creating tables' => fn () => $this->createTables(),
            'dim_date' => fn () => $this->populateDimDate(),
            'dim_team' => fn () => $this->syncDimTeam(),
            'dim_vehicle' => fn () => $this->syncDimVehicle(),
            'dim_commercial' => fn () => $this->syncDimCommercial(),
            'dim_supplier' => fn () => $this->syncDimSupplier(),
            'dim_warehouse' => fn () => $this->syncDimWarehouse(),
            'dim_expense_type' => fn () => $this->syncDimExpenseType(),
            'dim_caisse' => fn () => $this->syncDimCaisse(),
            'dim_product' => fn () => $this->syncDimProduct(),
            'dim_customer' => fn () => $this->syncDimCustomer(),
            'dim_car_load' => fn () => $this->syncDimCarLoad(),
            'fact_sales' => fn () => $this->syncFactSales(),
            'fact_payments' => fn () => $this->syncFactPayments(),
            'fact_invoices' => fn () => $this->syncFactInvoices(),
            'fact_daily_commissions' => fn () => $this->syncFactDailyCommissions(),
            'fact_expenses' => fn () => $this->syncFactExpenses(),
            'fact_purchases' => fn () => $this->syncFactPurchases(),
            'fact_car_load_stock' => fn () => $this->syncFactCarLoadStock(),
            'fact_beat_stops' => fn () => $this->syncFactBeatStops(),
            'fact_stock_entries' => fn () => $this->syncFactStockEntries(),
        ];

        foreach ($steps as $label => $step) {
            $this->runStep($label, $step);
        }

        $this->newLine();
        $this->info('Data warehouse sync complete!');

        return self::SUCCESS;
    }

    private function runStep(string $label, callable $step): void
    {
        $this->getOutput()->write("  <fg=yellow>{$label}</> ...");
        $step();
        $this->line(' <fg=green>done</>');
    }

    private function dw(): Connection
    {
        return DB::connection('datawarehouse');
    }

    private function src(string $table): string
    {
        return "`{$this->sourceDb}`.`{$table}`";
    }

    private function dropAllTables(): void
    {
        $dw = $this->dw();
        $factTables = [
            'fact_stock_entries', 'fact_beat_stops', 'fact_car_load_stock',
            'fact_purchases', 'fact_expenses', 'fact_daily_commissions',
            'fact_invoices', 'fact_payments', 'fact_sales',
        ];
        $dimTables = [
            'dim_car_load', 'dim_customer', 'dim_product',
            'dim_caisse', 'dim_expense_type', 'dim_warehouse',
            'dim_supplier', 'dim_commercial', 'dim_vehicle',
            'dim_team', 'dim_date',
        ];
        foreach (array_merge($factTables, $dimTables) as $table) {
            $dw->statement("DROP TABLE IF EXISTS `{$table}`");
        }
    }

    private function truncate(string $table): void
    {
        $this->dw()->statement("TRUNCATE TABLE `{$table}`");
    }

    // -------------------------------------------------------------------------
    // DDL — create all tables
    // -------------------------------------------------------------------------

    private function createTables(): void
    {
        $dw = $this->dw();

        $dw->statement("
            CREATE TABLE IF NOT EXISTS `dim_date` (
                `date_key`      INT NOT NULL COMMENT 'YYYYMMDD',
                `full_date`     DATE NOT NULL,
                `year`          SMALLINT NOT NULL,
                `quarter`       TINYINT NOT NULL,
                `month`         TINYINT NOT NULL,
                `month_name`    VARCHAR(20) NOT NULL,
                `week_of_year`  TINYINT NOT NULL,
                `day_of_month`  TINYINT NOT NULL,
                `day_of_week`   TINYINT NOT NULL COMMENT '0=Sunday, 6=Saturday',
                `day_name`      VARCHAR(20) NOT NULL,
                `is_weekend`    BOOLEAN NOT NULL,
                PRIMARY KEY (`date_key`),
                KEY `idx_year_month` (`year`, `month`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $dw->statement('
            CREATE TABLE IF NOT EXISTS `dim_team` (
                `team_id`   BIGINT UNSIGNED NOT NULL,
                `name`      VARCHAR(255),
                PRIMARY KEY (`team_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $dw->statement('
            CREATE TABLE IF NOT EXISTS `dim_vehicle` (
                `vehicle_id`                        BIGINT UNSIGNED NOT NULL,
                `name`                              VARCHAR(255),
                `plate_number`                      VARCHAR(255),
                `working_days_per_month`            SMALLINT UNSIGNED,
                `estimated_daily_fuel_consumption`  INT UNSIGNED,
                `driver_salary_monthly`             INT UNSIGNED,
                `insurance_monthly`                 INT UNSIGNED,
                `depreciation_monthly`              INT UNSIGNED,
                `maintenance_monthly`               INT UNSIGNED,
                `repair_reserve_monthly`            INT UNSIGNED,
                PRIMARY KEY (`vehicle_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $dw->statement('
            CREATE TABLE IF NOT EXISTS `dim_commercial` (
                `commercial_id`  BIGINT UNSIGNED NOT NULL,
                `name`           VARCHAR(255),
                `gender`         VARCHAR(50),
                `phone_number`   VARCHAR(255),
                `salary`         INT UNSIGNED,
                `team_id`        BIGINT UNSIGNED,
                `team_name`      VARCHAR(255),
                PRIMARY KEY (`commercial_id`),
                KEY `idx_team_id` (`team_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $dw->statement('
            CREATE TABLE IF NOT EXISTS `dim_supplier` (
                `supplier_id`   BIGINT UNSIGNED NOT NULL,
                `name`          VARCHAR(255),
                `address`       TEXT,
                `phone`         VARCHAR(255),
                `email`         VARCHAR(255),
                `tax_number`    VARCHAR(255),
                PRIMARY KEY (`supplier_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $dw->statement('
            CREATE TABLE IF NOT EXISTS `dim_warehouse` (
                `warehouse_id`  BIGINT UNSIGNED NOT NULL,
                `name`          VARCHAR(255),
                `address`       VARCHAR(255),
                PRIMARY KEY (`warehouse_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $dw->statement('
            CREATE TABLE IF NOT EXISTS `dim_expense_type` (
                `expense_type_id`   BIGINT UNSIGNED NOT NULL,
                `name`              VARCHAR(255),
                PRIMARY KEY (`expense_type_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $dw->statement('
            CREATE TABLE IF NOT EXISTS `dim_caisse` (
                `caisse_id`         BIGINT UNSIGNED NOT NULL,
                `name`              VARCHAR(255),
                `caisse_type`       VARCHAR(100),
                `commercial_id`     BIGINT UNSIGNED,
                `commercial_name`   VARCHAR(255),
                PRIMARY KEY (`caisse_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $dw->statement('
            CREATE TABLE IF NOT EXISTS `dim_product` (
                `product_id`                BIGINT UNSIGNED NOT NULL,
                `name`                      VARCHAR(255),
                `price`                     DECIMAL(10,2),
                `cost_price`                INT,
                `credit_price`              BIGINT UNSIGNED,
                `base_quantity`             INT,
                `is_variant`                BOOLEAN NOT NULL DEFAULT FALSE,
                `parent_product_id`         BIGINT UNSIGNED,
                `parent_product_name`       VARCHAR(255),
                `category_id`              BIGINT UNSIGNED,
                `category_name`             VARCHAR(255),
                `category_commission_rate`  DECIMAL(6,4),
                PRIMARY KEY (`product_id`),
                KEY `idx_category_id` (`category_id`),
                KEY `idx_parent_product_id` (`parent_product_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $dw->statement('
            CREATE TABLE IF NOT EXISTS `dim_customer` (
                `customer_id`       BIGINT UNSIGNED NOT NULL,
                `name`              VARCHAR(255),
                `address`           VARCHAR(255),
                `phone_number`      VARCHAR(255),
                `is_prospect`       BOOLEAN NOT NULL DEFAULT FALSE,
                `commercial_id`     BIGINT UNSIGNED,
                `commercial_name`   VARCHAR(255),
                `category_name`     VARCHAR(255),
                `zone_name`         VARCHAR(255),
                `ligne_name`        VARCHAR(255),
                `sector_name`       VARCHAR(255),
                PRIMARY KEY (`customer_id`),
                KEY `idx_commercial_id` (`commercial_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $dw->statement('
            CREATE TABLE IF NOT EXISTS `dim_car_load` (
                `car_load_id`       BIGINT UNSIGNED NOT NULL,
                `name`              VARCHAR(255),
                `status`            VARCHAR(50),
                `team_id`           BIGINT UNSIGNED,
                `team_name`         VARCHAR(255),
                `vehicle_id`        BIGINT UNSIGNED,
                `vehicle_name`      VARCHAR(255),
                `vehicle_plate`     VARCHAR(255),
                `load_date_key`     INT,
                `return_date_key`   INT,
                `fixed_daily_cost`  INT UNSIGNED,
                PRIMARY KEY (`car_load_id`),
                KEY `idx_team_id` (`team_id`),
                KEY `idx_load_date_key` (`load_date_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $dw->statement('
            CREATE TABLE IF NOT EXISTS `fact_sales` (
                `fact_sales_id`     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `sale_date_key`     INT NOT NULL,
                `commercial_id`     BIGINT UNSIGNED,
                `customer_id`       BIGINT UNSIGNED,
                `product_id`        BIGINT UNSIGNED NOT NULL,
                `car_load_id`       BIGINT UNSIGNED,
                `vente_id`          BIGINT UNSIGNED NOT NULL,
                `sales_invoice_id`  BIGINT UNSIGNED,
                `invoice_status`    VARCHAR(50),
                `payment_method`    VARCHAR(100),
                `is_paid`           BOOLEAN,
                `quantity`          INT NOT NULL,
                `unit_price`        DECIMAL(10,2) NOT NULL,
                `total_amount`      DECIMAL(12,2) NOT NULL,
                `cost_price`        INT,
                `total_cost`        DECIMAL(12,2),
                `profit`            INT,
                `gross_margin_pct`  DECIMAL(8,4),
                PRIMARY KEY (`fact_sales_id`),
                UNIQUE KEY `uq_vente_id` (`vente_id`),
                KEY `idx_sale_date_key` (`sale_date_key`),
                KEY `idx_commercial_id` (`commercial_id`),
                KEY `idx_customer_id` (`customer_id`),
                KEY `idx_product_id` (`product_id`),
                KEY `idx_car_load_id` (`car_load_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $dw->statement('
            CREATE TABLE IF NOT EXISTS `fact_payments` (
                `fact_payment_id`       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `payment_date_key`      INT NOT NULL,
                `commercial_id`         BIGINT UNSIGNED,
                `customer_id`           BIGINT UNSIGNED,
                `car_load_id`           BIGINT UNSIGNED,
                `payment_id`            BIGINT UNSIGNED NOT NULL,
                `sales_invoice_id`      BIGINT UNSIGNED,
                `payment_method`        VARCHAR(100),
                `amount`                INT NOT NULL,
                `profit`                INT,
                `commercial_commission` INT,
                PRIMARY KEY (`fact_payment_id`),
                UNIQUE KEY `uq_payment_id` (`payment_id`),
                KEY `idx_payment_date_key` (`payment_date_key`),
                KEY `idx_commercial_id` (`commercial_id`),
                KEY `idx_customer_id` (`customer_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $dw->statement('
            CREATE TABLE IF NOT EXISTS `fact_invoices` (
                `fact_invoice_id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `invoice_date_key`                  INT NOT NULL,
                `due_date_key`                      INT,
                `commercial_id`                     BIGINT UNSIGNED,
                `customer_id`                       BIGINT UNSIGNED,
                `car_load_id`                       BIGINT UNSIGNED,
                `sales_invoice_id`                  BIGINT UNSIGNED NOT NULL,
                `invoice_number`                    VARCHAR(255),
                `invoice_status`                    VARCHAR(50),
                `total_amount`                      BIGINT UNSIGNED,
                `total_payments`                    BIGINT UNSIGNED,
                `total_remaining`                   BIGINT,
                `total_estimated_profit`            BIGINT,
                `total_realized_profit`             BIGINT,
                `estimated_commercial_commission`   INT,
                `delivery_cost`                     INT UNSIGNED,
                `credit_price_difference`           BIGINT UNSIGNED,
                `days_overdue`                      INT,
                PRIMARY KEY (`fact_invoice_id`),
                UNIQUE KEY `uq_sales_invoice_id` (`sales_invoice_id`),
                KEY `idx_invoice_date_key` (`invoice_date_key`),
                KEY `idx_commercial_id` (`commercial_id`),
                KEY `idx_customer_id` (`customer_id`),
                KEY `idx_invoice_status` (`invoice_status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $dw->statement('
            CREATE TABLE IF NOT EXISTS `fact_daily_commissions` (
                `fact_commission_id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `work_day_key`                  INT NOT NULL,
                `commercial_id`                 BIGINT UNSIGNED NOT NULL,
                `daily_commission_id`           BIGINT UNSIGNED NOT NULL,
                `commercial_work_period_id`     BIGINT UNSIGNED,
                `base_commission`               INT UNSIGNED,
                `objective_bonus`               INT UNSIGNED,
                `basket_bonus`                  INT UNSIGNED,
                `net_commission`                INT UNSIGNED,
                `total_penalties`               INT UNSIGNED,
                `new_confirmed_customers_bonus` INT,
                `new_prospect_customers_bonus`  INT,
                `achieved_tier_level`           TINYINT UNSIGNED,
                `basket_achieved`               BOOLEAN,
                `mandatory_threshold_reached`   BOOLEAN,
                `average_margin_rate`           DECIMAL(6,4),
                `mandatory_daily_threshold`     INT,
                PRIMARY KEY (`fact_commission_id`),
                UNIQUE KEY `uq_daily_commission_id` (`daily_commission_id`),
                KEY `idx_work_day_key` (`work_day_key`),
                KEY `idx_commercial_id` (`commercial_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $dw->statement('
            CREATE TABLE IF NOT EXISTS `fact_expenses` (
                `fact_expense_id`   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `expense_date_key`  INT NOT NULL,
                `caisse_id`         BIGINT UNSIGNED,
                `expense_type_id`   BIGINT UNSIGNED,
                `depense_id`        BIGINT UNSIGNED NOT NULL,
                `expense_type_name` VARCHAR(255),
                `amount`            INT NOT NULL,
                PRIMARY KEY (`fact_expense_id`),
                UNIQUE KEY `uq_depense_id` (`depense_id`),
                KEY `idx_expense_date_key` (`expense_date_key`),
                KEY `idx_expense_type_id` (`expense_type_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $dw->statement('
            CREATE TABLE IF NOT EXISTS `fact_purchases` (
                `fact_purchase_id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `purchase_date_key`             INT NOT NULL,
                `product_id`                    BIGINT UNSIGNED NOT NULL,
                `supplier_id`                   BIGINT UNSIGNED,
                `purchase_invoice_id`           BIGINT UNSIGNED NOT NULL,
                `purchase_invoice_item_id`      BIGINT UNSIGNED NOT NULL,
                `invoice_number`                VARCHAR(255),
                `is_paid`                       BOOLEAN,
                `is_stocked`                    BOOLEAN,
                `quantity`                      INT NOT NULL,
                `unit_price`                    INT NOT NULL,
                `transportation_cost`           INT UNSIGNED,
                `line_total`                    BIGINT,
                `line_total_with_transport`     BIGINT,
                PRIMARY KEY (`fact_purchase_id`),
                UNIQUE KEY `uq_purchase_invoice_item_id` (`purchase_invoice_item_id`),
                KEY `idx_purchase_date_key` (`purchase_date_key`),
                KEY `idx_product_id` (`product_id`),
                KEY `idx_supplier_id` (`supplier_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $dw->statement('
            CREATE TABLE IF NOT EXISTS `fact_car_load_stock` (
                `fact_carload_stock_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `loaded_date_key`       INT NOT NULL,
                `car_load_id`           BIGINT UNSIGNED NOT NULL,
                `product_id`            BIGINT UNSIGNED NOT NULL,
                `team_id`               BIGINT UNSIGNED,
                `vehicle_id`            BIGINT UNSIGNED,
                `car_load_item_id`      BIGINT UNSIGNED NOT NULL,
                `load_source`           VARCHAR(100),
                `quantity_loaded`       INT NOT NULL,
                `quantity_left`         INT NOT NULL,
                `quantity_sold`         INT,
                `cost_price_per_unit`   INT UNSIGNED,
                `total_loaded_value`    BIGINT,
                `sell_out_rate`         DECIMAL(5,4),
                PRIMARY KEY (`fact_carload_stock_id`),
                UNIQUE KEY `uq_car_load_item_id` (`car_load_item_id`),
                KEY `idx_loaded_date_key` (`loaded_date_key`),
                KEY `idx_car_load_id` (`car_load_id`),
                KEY `idx_product_id` (`product_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $dw->statement('
            CREATE TABLE IF NOT EXISTS `fact_beat_stops` (
                `fact_beat_stop_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `visit_date_key`    INT NOT NULL,
                `commercial_id`     BIGINT UNSIGNED,
                `customer_id`       BIGINT UNSIGNED NOT NULL,
                `beat_stop_id`      BIGINT UNSIGNED NOT NULL,
                `beat_name`         VARCHAR(255),
                `visit_status`      VARCHAR(100),
                `resulted_in_sale`  BOOLEAN,
                PRIMARY KEY (`fact_beat_stop_id`),
                UNIQUE KEY `uq_beat_stop_id` (`beat_stop_id`),
                KEY `idx_visit_date_key` (`visit_date_key`),
                KEY `idx_commercial_id` (`commercial_id`),
                KEY `idx_customer_id` (`customer_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $dw->statement('
            CREATE TABLE IF NOT EXISTS `fact_stock_entries` (
                `fact_stock_entry_id`       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `entry_date_key`            INT NOT NULL,
                `product_id`                BIGINT UNSIGNED NOT NULL,
                `warehouse_id`              BIGINT UNSIGNED,
                `stock_entry_id`            BIGINT UNSIGNED NOT NULL,
                `purchase_invoice_item_id`  BIGINT UNSIGNED,
                `quantity`                  INT NOT NULL,
                `quantity_left`             INT NOT NULL,
                `unit_price`                INT NOT NULL,
                `transportation_cost`       INT UNSIGNED,
                `packaging_cost`            INT UNSIGNED,
                `total_cost`                BIGINT,
                PRIMARY KEY (`fact_stock_entry_id`),
                UNIQUE KEY `uq_stock_entry_id` (`stock_entry_id`),
                KEY `idx_entry_date_key` (`entry_date_key`),
                KEY `idx_product_id` (`product_id`),
                KEY `idx_warehouse_id` (`warehouse_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    // -------------------------------------------------------------------------
    // dim_date — calendar dimension (2020–2035), populated once
    // -------------------------------------------------------------------------

    private function populateDimDate(): void
    {
        $dw = $this->dw();

        if ($dw->table('dim_date')->count() > 0) {
            return;
        }

        $batchSize = 500;
        $batch = [];

        foreach (CarbonPeriod::create('2020-01-01', '2035-12-31') as $date) {
            $batch[] = [
                'date_key' => (int) $date->format('Ymd'),
                'full_date' => $date->toDateString(),
                'year' => $date->year,
                'quarter' => $date->quarter,
                'month' => $date->month,
                'month_name' => $date->format('F'),
                'week_of_year' => (int) $date->format('W'),
                'day_of_month' => $date->day,
                'day_of_week' => $date->dayOfWeek,
                'day_name' => $date->format('l'),
                'is_weekend' => in_array($date->dayOfWeek, [0, 6]) ? 1 : 0,
            ];

            if (count($batch) >= $batchSize) {
                $dw->table('dim_date')->insert($batch);
                $batch = [];
            }
        }

        if (! empty($batch)) {
            $dw->table('dim_date')->insert($batch);
        }
    }

    // -------------------------------------------------------------------------
    // Dimension syncs (full TRUNCATE + INSERT from OLTP via cross-DB SQL)
    // -------------------------------------------------------------------------

    private function syncDimTeam(): void
    {
        $this->truncate('dim_team');
        $this->dw()->statement("
            INSERT INTO `{$this->dwDb}`.`dim_team` (`team_id`, `name`)
            SELECT `id`, `name`
            FROM {$this->src('teams')}
        ");
    }

    private function syncDimVehicle(): void
    {
        $this->truncate('dim_vehicle');
        $this->dw()->statement("
            INSERT INTO `{$this->dwDb}`.`dim_vehicle`
                (`vehicle_id`, `name`, `plate_number`, `working_days_per_month`,
                 `estimated_daily_fuel_consumption`, `driver_salary_monthly`,
                 `insurance_monthly`, `depreciation_monthly`,
                 `maintenance_monthly`, `repair_reserve_monthly`)
            SELECT `id`, `name`, `plate_number`, `working_days_per_month`,
                   `estimated_daily_fuel_consumption`, `driver_salary_monthly`,
                   `insurance_monthly`, `depreciation_monthly`,
                   `maintenance_monthly`, `repair_reserve_monthly`
            FROM {$this->src('vehicles')}
        ");
    }

    private function syncDimCommercial(): void
    {
        $this->truncate('dim_commercial');
        $this->dw()->statement("
            INSERT INTO `{$this->dwDb}`.`dim_commercial`
                (`commercial_id`, `name`, `gender`, `phone_number`, `salary`,
                 `team_id`, `team_name`)
            SELECT c.`id`, c.`name`, c.`gender`, c.`phone_number`, c.`salary`,
                   c.`team_id`, t.`name`
            FROM {$this->src('commercials')} c
            LEFT JOIN {$this->src('teams')} t ON t.`id` = c.`team_id`
        ");
    }

    private function syncDimSupplier(): void
    {
        $this->truncate('dim_supplier');
        $this->dw()->statement("
            INSERT INTO `{$this->dwDb}`.`dim_supplier`
                (`supplier_id`, `name`, `address`, `phone`, `email`, `tax_number`)
            SELECT `id`, `name`, `address`, `phone`, `email`, `tax_number`
            FROM {$this->src('suppliers')}
        ");
    }

    private function syncDimWarehouse(): void
    {
        $this->truncate('dim_warehouse');
        $this->dw()->statement("
            INSERT INTO `{$this->dwDb}`.`dim_warehouse` (`warehouse_id`, `name`, `address`)
            SELECT `id`, `name`, `address`
            FROM {$this->src('warehouses')}
        ");
    }

    private function syncDimExpenseType(): void
    {
        $this->truncate('dim_expense_type');
        $this->dw()->statement("
            INSERT INTO `{$this->dwDb}`.`dim_expense_type` (`expense_type_id`, `name`)
            SELECT `id`, `name`
            FROM {$this->src('type_depenses')}
        ");
    }

    private function syncDimCaisse(): void
    {
        $this->truncate('dim_caisse');
        $this->dw()->statement("
            INSERT INTO `{$this->dwDb}`.`dim_caisse`
                (`caisse_id`, `name`, `caisse_type`, `commercial_id`, `commercial_name`)
            SELECT ca.`id`, ca.`name`, ca.`caisse_type`, ca.`commercial_id`, c.`name`
            FROM {$this->src('caisses')} ca
            LEFT JOIN {$this->src('commercials')} c ON c.`id` = ca.`commercial_id`
        ");
    }

    private function syncDimProduct(): void
    {
        $this->truncate('dim_product');
        $this->dw()->statement("
            INSERT INTO `{$this->dwDb}`.`dim_product`
                (`product_id`, `name`, `price`, `cost_price`, `credit_price`,
                 `base_quantity`, `is_variant`, `parent_product_id`, `parent_product_name`,
                 `category_id`, `category_name`, `category_commission_rate`)
            SELECT p.`id`, p.`name`, p.`price`, p.`cost_price`, p.`credit_price`,
                   p.`base_quantity`,
                   IF(p.`parent_id` IS NOT NULL, TRUE, FALSE),
                   p.`parent_id`, pp.`name`,
                   p.`product_category_id`, pc.`name`, pc.`commission_rate`
            FROM {$this->src('products')} p
            LEFT JOIN {$this->src('products')} pp ON pp.`id` = p.`parent_id`
            LEFT JOIN {$this->src('product_categories')} pc ON pc.`id` = p.`product_category_id`
        ");
    }

    private function syncDimCustomer(): void
    {
        $this->truncate('dim_customer');
        $this->dw()->statement("
            INSERT INTO `{$this->dwDb}`.`dim_customer`
                (`customer_id`, `name`, `address`, `phone_number`, `is_prospect`,
                 `commercial_id`, `commercial_name`, `category_name`,
                 `zone_name`, `ligne_name`, `sector_name`)
            SELECT cu.`id`, cu.`name`, cu.`address`, cu.`phone_number`, cu.`is_prospect`,
                   cu.`commercial_id`, c.`name`,
                   cc.`name`,
                   z.`name`, l.`name`, s.`name`
            FROM {$this->src('customers')} cu
            LEFT JOIN {$this->src('commercials')} c   ON c.`id`  = cu.`commercial_id`
            LEFT JOIN {$this->src('customer_categories')} cc ON cc.`id` = cu.`customer_category_id`
            LEFT JOIN {$this->src('sectors')} s        ON s.`id`  = cu.`sector_id`
            LEFT JOIN {$this->src('lignes')} l         ON l.`id`  = s.`ligne_id`
            LEFT JOIN {$this->src('zones')} z          ON z.`id`  = l.`zone_id`
        ");
    }

    private function syncDimCarLoad(): void
    {
        $this->truncate('dim_car_load');
        $this->dw()->statement("
            INSERT INTO `{$this->dwDb}`.`dim_car_load`
                (`car_load_id`, `name`, `status`, `team_id`, `team_name`,
                 `vehicle_id`, `vehicle_name`, `vehicle_plate`,
                 `load_date_key`, `return_date_key`, `fixed_daily_cost`)
            SELECT
                cl.`id`, cl.`name`, cl.`status`,
                cl.`team_id`, t.`name`,
                cl.`vehicle_id`, v.`name`, v.`plate_number`,
                CAST(DATE_FORMAT(cl.`load_date`, '%Y%m%d') AS UNSIGNED),
                CASE WHEN cl.`return_date` IS NOT NULL
                     THEN CAST(DATE_FORMAT(cl.`return_date`, '%Y%m%d') AS UNSIGNED)
                     ELSE NULL END,
                cl.`fixed_daily_cost`
            FROM {$this->src('car_loads')} cl
            LEFT JOIN {$this->src('teams')} t    ON t.`id` = cl.`team_id`
            LEFT JOIN {$this->src('vehicles')} v ON v.`id` = cl.`vehicle_id`
        ");
    }

    // -------------------------------------------------------------------------
    // Fact syncs
    // -------------------------------------------------------------------------

    private function syncFactSales(): void
    {
        $this->truncate('fact_sales');
        $this->dw()->statement("
            INSERT INTO `{$this->dwDb}`.`fact_sales`
                (`sale_date_key`, `commercial_id`, `customer_id`, `product_id`, `car_load_id`,
                 `vente_id`, `sales_invoice_id`, `invoice_status`, `payment_method`, `is_paid`,
                 `quantity`, `unit_price`, `total_amount`,
                 `cost_price`, `total_cost`, `profit`, `gross_margin_pct`)
            SELECT
                CAST(DATE_FORMAT(v.`created_at`, '%Y%m%d') AS UNSIGNED),
                si.`commercial_id`,
                v.`customer_id`,
                v.`product_id`,
                si.`car_load_id`,
                v.`id`,
                v.`sales_invoice_id`,
                si.`status`,
                v.`payment_method`,
                v.`paid`,
                v.`quantity`,
                v.`price`,
                (v.`quantity` * v.`price`),
                p.`cost_price`,
                (v.`quantity` * p.`cost_price`),
                v.`profit`,
                CASE WHEN (v.`quantity` * v.`price`) > 0
                     THEN ROUND(v.`profit` / (v.`quantity` * v.`price`), 4)
                     ELSE NULL END
            FROM {$this->src('ventes')} v
            LEFT JOIN {$this->src('sales_invoices')} si ON si.`id` = v.`sales_invoice_id`
            LEFT JOIN {$this->src('products')} p         ON p.`id`  = v.`product_id`
        ");
    }

    private function syncFactPayments(): void
    {
        $this->truncate('fact_payments');
        $this->dw()->statement("
            INSERT INTO `{$this->dwDb}`.`fact_payments`
                (`payment_date_key`, `commercial_id`, `customer_id`, `car_load_id`,
                 `payment_id`, `sales_invoice_id`, `payment_method`,
                 `amount`, `profit`, `commercial_commission`)
            SELECT
                CAST(DATE_FORMAT(pay.`created_at`, '%Y%m%d') AS UNSIGNED),
                si.`commercial_id`,
                si.`customer_id`,
                si.`car_load_id`,
                pay.`id`,
                pay.`sales_invoice_id`,
                pay.`payment_method`,
                pay.`amount`,
                pay.`profit`,
                pay.`commercial_commission`
            FROM {$this->src('payments')} pay
            LEFT JOIN {$this->src('sales_invoices')} si ON si.`id` = pay.`sales_invoice_id`
        ");
    }

    private function syncFactInvoices(): void
    {
        $this->truncate('fact_invoices');
        $this->dw()->statement("
            INSERT INTO `{$this->dwDb}`.`fact_invoices`
                (`invoice_date_key`, `due_date_key`, `commercial_id`, `customer_id`, `car_load_id`,
                 `sales_invoice_id`, `invoice_number`, `invoice_status`,
                 `total_amount`, `total_payments`, `total_remaining`,
                 `total_estimated_profit`, `total_realized_profit`,
                 `estimated_commercial_commission`, `delivery_cost`,
                 `credit_price_difference`, `days_overdue`)
            SELECT
                CAST(DATE_FORMAT(si.`created_at`, '%Y%m%d') AS UNSIGNED),
                CASE WHEN si.`should_be_paid_at` IS NOT NULL
                     THEN CAST(DATE_FORMAT(si.`should_be_paid_at`, '%Y%m%d') AS UNSIGNED)
                     ELSE NULL END,
                si.`commercial_id`,
                si.`customer_id`,
                si.`car_load_id`,
                si.`id`,
                si.`invoice_number`,
                si.`status`,
                si.`total_amount`,
                si.`total_payments`,
                (CAST(si.`total_amount` AS SIGNED) - CAST(si.`total_payments` AS SIGNED)),
                si.`total_estimated_profit`,
                si.`total_realized_profit`,
                si.`estimated_commercial_commission`,
                si.`delivery_cost`,
                si.`credit_price_difference`,
                CASE WHEN si.`status` != 'FULLY_PAID'
                          AND si.`should_be_paid_at` IS NOT NULL
                          AND si.`should_be_paid_at` < NOW()
                     THEN DATEDIFF(NOW(), si.`should_be_paid_at`)
                     ELSE 0 END
            FROM {$this->src('sales_invoices')} si
        ");
    }

    private function syncFactDailyCommissions(): void
    {
        $this->truncate('fact_daily_commissions');
        $this->dw()->statement("
            INSERT INTO `{$this->dwDb}`.`fact_daily_commissions`
                (`work_day_key`, `commercial_id`, `daily_commission_id`,
                 `commercial_work_period_id`, `base_commission`, `objective_bonus`,
                 `basket_bonus`, `net_commission`, `total_penalties`,
                 `new_confirmed_customers_bonus`, `new_prospect_customers_bonus`,
                 `achieved_tier_level`, `basket_achieved`,
                 `mandatory_threshold_reached`, `average_margin_rate`,
                 `mandatory_daily_threshold`)
            SELECT
                CAST(DATE_FORMAT(dc.`work_day`, '%Y%m%d') AS UNSIGNED),
                cwp.`commercial_id`,
                dc.`id`,
                dc.`commercial_work_period_id`,
                dc.`base_commission`,
                dc.`objective_bonus`,
                dc.`basket_bonus`,
                dc.`net_commission`,
                dc.`total_penalties`,
                dc.`new_confirmed_customers_bonus`,
                dc.`new_prospect_customers_bonus`,
                dc.`achieved_tier_level`,
                dc.`basket_achieved`,
                dc.`mandatory_threshold_reached`,
                dc.`cached_average_margin_rate`,
                dc.`mandatory_daily_threshold`
            FROM {$this->src('daily_commissions')} dc
            JOIN {$this->src('commercial_work_periods')} cwp
                ON cwp.`id` = dc.`commercial_work_period_id`
        ");
    }

    private function syncFactExpenses(): void
    {
        $this->truncate('fact_expenses');
        $this->dw()->statement("
            INSERT INTO `{$this->dwDb}`.`fact_expenses`
                (`expense_date_key`, `caisse_id`, `expense_type_id`,
                 `depense_id`, `expense_type_name`, `amount`)
            SELECT
                CAST(DATE_FORMAT(d.`created_at`, '%Y%m%d') AS UNSIGNED),
                d.`caisse_id`,
                d.`type_depense_id`,
                d.`id`,
                td.`name`,
                d.`amount`
            FROM {$this->src('depenses')} d
            LEFT JOIN {$this->src('type_depenses')} td ON td.`id` = d.`type_depense_id`
        ");
    }

    private function syncFactPurchases(): void
    {
        $this->truncate('fact_purchases');
        $this->dw()->statement("
            INSERT INTO `{$this->dwDb}`.`fact_purchases`
                (`purchase_date_key`, `product_id`, `supplier_id`,
                 `purchase_invoice_id`, `purchase_invoice_item_id`,
                 `invoice_number`, `is_paid`, `is_stocked`,
                 `quantity`, `unit_price`, `transportation_cost`,
                 `line_total`, `line_total_with_transport`)
            SELECT
                CAST(DATE_FORMAT(pi.`invoice_date`, '%Y%m%d') AS UNSIGNED),
                pii.`product_id`,
                pi.`supplier_id`,
                pii.`purchase_invoice_id`,
                pii.`id`,
                pi.`invoice_number`,
                pi.`is_paid`,
                pi.`is_stocked`,
                pii.`quantity`,
                pii.`unit_price`,
                pii.`transportation_cost`,
                (pii.`quantity` * pii.`unit_price`),
                (pii.`quantity` * pii.`unit_price` + IFNULL(pii.`transportation_cost`, 0))
            FROM {$this->src('purchase_invoice_items')} pii
            JOIN {$this->src('purchase_invoices')} pi
                ON pi.`id` = pii.`purchase_invoice_id`
            WHERE pi.`invoice_date` IS NOT NULL
        ");
    }

    private function syncFactCarLoadStock(): void
    {
        $this->truncate('fact_car_load_stock');
        $this->dw()->statement("
            INSERT INTO `{$this->dwDb}`.`fact_car_load_stock`
                (`loaded_date_key`, `car_load_id`, `product_id`, `team_id`, `vehicle_id`,
                 `car_load_item_id`, `load_source`, `quantity_loaded`, `quantity_left`,
                 `quantity_sold`, `cost_price_per_unit`, `total_loaded_value`, `sell_out_rate`)
            SELECT
                CAST(DATE_FORMAT(cli.`loaded_at`, '%Y%m%d') AS UNSIGNED),
                cli.`car_load_id`,
                cli.`product_id`,
                cl.`team_id`,
                cl.`vehicle_id`,
                cli.`id`,
                cli.`source`,
                cli.`quantity_loaded`,
                cli.`quantity_left`,
                (cli.`quantity_loaded` - cli.`quantity_left`),
                cli.`cost_price_per_unit`,
                (cli.`quantity_loaded` * cli.`cost_price_per_unit`),
                CASE WHEN cli.`quantity_loaded` > 0
                     THEN ROUND((cli.`quantity_loaded` - cli.`quantity_left`) / cli.`quantity_loaded`, 4)
                     ELSE NULL END
            FROM {$this->src('car_load_items')} cli
            JOIN {$this->src('car_loads')} cl ON cl.`id` = cli.`car_load_id`
            WHERE cli.`loaded_at` IS NOT NULL
        ");
    }

    private function syncFactBeatStops(): void
    {
        $this->truncate('fact_beat_stops');
        $this->dw()->statement("
            INSERT INTO `{$this->dwDb}`.`fact_beat_stops`
                (`visit_date_key`, `commercial_id`, `customer_id`,
                 `beat_stop_id`, `beat_name`, `visit_status`, `resulted_in_sale`)
            SELECT
                CAST(DATE_FORMAT(bs.`visit_date`, '%Y%m%d') AS UNSIGNED),
                b.`commercial_id`,
                bs.`customer_id`,
                bs.`id`,
                b.`name`,
                bs.`status`,
                bs.`resulted_in_sale`
            FROM {$this->src('beat_stops')} bs
            JOIN {$this->src('beats')} b ON b.`id` = bs.`beat_id`
            WHERE bs.`visit_date` IS NOT NULL
        ");
    }

    private function syncFactStockEntries(): void
    {
        $this->truncate('fact_stock_entries');
        $this->dw()->statement("
            INSERT INTO `{$this->dwDb}`.`fact_stock_entries`
                (`entry_date_key`, `product_id`, `warehouse_id`,
                 `stock_entry_id`, `purchase_invoice_item_id`,
                 `quantity`, `quantity_left`, `unit_price`,
                 `transportation_cost`, `packaging_cost`, `total_cost`)
            SELECT
                CAST(DATE_FORMAT(se.`created_at`, '%Y%m%d') AS UNSIGNED),
                se.`product_id`,
                se.`warehouse_id`,
                se.`id`,
                se.`purchase_invoice_item_id`,
                se.`quantity`,
                se.`quantity_left`,
                se.`unit_price`,
                se.`transportation_cost`,
                se.`packaging_cost`,
                (se.`quantity` * (se.`unit_price`
                    + IFNULL(se.`transportation_cost`, 0)
                    + IFNULL(se.`packaging_cost`, 0)))
            FROM {$this->src('stock_entries')} se
        ");
    }
}
