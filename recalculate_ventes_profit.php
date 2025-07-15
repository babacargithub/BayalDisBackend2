<?php

/**
 * Script to recalculate profit for all existing Ventes using historical cost prices
 *
 * This script loads the Laravel environment and runs the ventes:recalculate-profit Artisan command.
 * The command now uses historical cost prices from StockEntry records to calculate profits,
 * which provides a more accurate profit calculation than using the current product cost price.
 *
 * Usage: php recalculate_ventes_profit.php
 */

// Define the application path
define('LARAVEL_START', microtime(true));

// Check if running from the project root
if (!file_exists(__DIR__ . '/artisan')) {
    die("Error: This script must be run from the project root directory.\n");
}

// Require the autoloader
require __DIR__ . '/vendor/autoload.php';

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';

// Get the Kernel instance
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);

// Run the command
$status = $kernel->call('ventes:recalculate-profit');

// Exit with the command's status code
exit($status);
