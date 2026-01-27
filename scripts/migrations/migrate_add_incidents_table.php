<?php
/**
 * Migration: Add incidents table
 * Run this to add the incidents table to existing databases
 */

require_once __DIR__ . '/db.php';

$pdo = get_pdo();

try {
    $sql = "CREATE TABLE IF NOT EXISTS incidents (
      id INT AUTO_INCREMENT PRIMARY KEY,
      user_id INT NOT NULL,
      date DATE NOT NULL,
      incident_type ENUM('full_day', 'hours') NOT NULL DEFAULT 'hours',
      hours_lost INT NULL COMMENT 'Minutes lost (only for hours type)',
      reason TEXT NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      KEY user_date (user_id, date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $pdo->exec($sql);
    echo "✓ Incidents table created successfully\n";
} catch (Throwable $e) {
    echo "✗ Error creating incidents table: " . $e->getMessage() . "\n";
    exit(1);
}
