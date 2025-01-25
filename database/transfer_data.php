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
    echo "\nTransferring table: {$table}\n";
    
    try {
        // Get all records from SQLite
        $records = $sqlite->query("SELECT * FROM {$table}")->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($records)) {
            echo "No records found in table {$table}\n";
            continue;
        }

        // Get MySQL table columns
        $mysqlColumns = [];
        $mysqlColumnsResult = $mysql->query("SHOW COLUMNS FROM {$table}");
        while ($column = $mysqlColumnsResult->fetch(PDO::FETCH_ASSOC)) {
            $mysqlColumns[] = $column['Field'];
        }

        // Filter SQLite columns to only include those that exist in MySQL
        $sqliteColumns = array_keys($records[0]);
        $validColumns = array_intersect($sqliteColumns, $mysqlColumns);

        if (empty($validColumns)) {
            echo "No matching columns found for table {$table}\n";
            continue;
        }

        echo "Columns to transfer: " . implode(', ', $validColumns) . "\n";
        
        // Clear existing data in MySQL table
        $mysql->exec("SET FOREIGN_KEY_CHECKS=0");
        // do not truncate migrations table
        if ($table !== 'migrations') {
            $mysql->exec("TRUNCATE TABLE {$table}");
        }
        
        // Prepare insert statement with only valid columns
        $columnsList = implode(',', $validColumns);
        $placeholders = array_fill(0, count($validColumns), '?');
        $placeholdersList = implode(',', $placeholders);
        $sql = "INSERT INTO {$table} ({$columnsList}) VALUES ({$placeholdersList})";
        
        echo "SQL Query: {$sql}\n";
        
        $stmt = $mysql->prepare($sql);
        
        // Insert records using only valid columns
        foreach ($records as $index => $record) {
            try {
                // Create an array of values in the same order as the columns
                $values = [];
                foreach ($validColumns as $column) {
                    $values[] = $record[$column];
                }
                
                if (count($values) !== count($validColumns)) {
                    throw new Exception("Value count mismatch: expected " . count($validColumns) . ", got " . count($values));
                }
                
                $stmt->execute($values);
                echo ".";
            } catch (Exception $e) {
                echo "\nError inserting record {$index} in {$table}: " . $e->getMessage() . "\n";
                echo "Record data: " . json_encode($record) . "\n";
                echo "Values to insert: " . json_encode($values) . "\n";
            }
        }
        
        $mysql->exec("SET FOREIGN_KEY_CHECKS=1");
        echo "\nCompleted table: {$table}\n";
        
    } catch (Exception $e) {
        echo "\nError processing table {$table}: " . $e->getMessage() . "\n";
    }
}

echo "\nData transfer completed!\n";
