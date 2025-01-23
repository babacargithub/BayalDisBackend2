<?php

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

// SQLite connection
$sqlite = new PDO('sqlite:'.__DIR__.'/database.sqlite');
$sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// MySQL connection (using Laravel's connection)
$mysql = DB::connection()->getPdo();

// Get all tables
$tables = $sqlite->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);

foreach ($tables as $table) {
    echo "Transferring table: {$table}\n";
    
    // Get all records from SQLite
    $records = $sqlite->query("SELECT * FROM {$table}")->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($records)) {
        echo "No records found in table {$table}\n";
        continue;
    }
    
    // Clear existing data in MySQL table
    $mysql->exec("SET FOREIGN_KEY_CHECKS=0");
    $mysql->exec("TRUNCATE TABLE {$table}");
    
    // Prepare insert statement
    $columns = array_keys($records[0]);
    $values = str_repeat('?,', count($columns) - 1) . '?';
    $sql = "INSERT INTO {$table} (" . implode(',', $columns) . ") VALUES ({$values})";
    $stmt = $mysql->prepare($sql);
    
    // Insert records
    foreach ($records as $record) {
        try {
            $stmt->execute(array_values($record));
            echo ".";
        } catch (Exception $e) {
            echo "\nError inserting record in {$table}: " . $e->getMessage() . "\n";
        }
    }
    
    $mysql->exec("SET FOREIGN_KEY_CHECKS=1");
    echo "\nCompleted table: {$table}\n";
}

echo "\nData transfer completed!\n"; 