<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db.php';

/**
 * Migration: add `absence_type` column to `entries` table if missing.
 * Usage: php migrate_add_absence_type_column.php
 */
echo "Starting migration: add absence_type column to entries table\n";

try {
    $pdo = get_pdo();
} catch (Throwable $e) {
    echo "Could not obtain DB connection: " . $e->getMessage() . "\n";
    exit(1);
}

try {
    $res = $pdo->query("SHOW COLUMNS FROM entries LIKE 'absence_type'");
    $found = $res && $res->fetch();
    if ($found) {
        echo "Column 'absence_type' already exists — nothing to do.\n";
        exit(0);
    }

    // Add the column (allow NULL). Adjust type/length if you have specific needs.
    $sql = "ALTER TABLE entries ADD COLUMN absence_type VARCHAR(50) DEFAULT NULL";
    $pdo->exec($sql);
    echo "Column 'absence_type' added successfully.\n";
    exit(0);
} catch (Throwable $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}

