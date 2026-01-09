<?php
// Script to add absence_type column to entries table
require_once __DIR__ . '/../db.php';

$pdo = get_pdo();

try {
  // Check if column exists
  $stmt = $pdo->query("SHOW COLUMNS FROM entries LIKE 'absence_type'");
  $exists = $stmt->rowCount() > 0;
  
  if (!$exists) {
    $pdo->exec("ALTER TABLE entries ADD COLUMN absence_type VARCHAR(50) DEFAULT NULL COMMENT 'vacation, illness, permit, other'");
    echo "✓ Column 'absence_type' added to entries table\n";
  } else {
    echo "✓ Column 'absence_type' already exists\n";
  }
  
  // Also add a display_label for better UI
  $stmt = $pdo->query("SHOW COLUMNS FROM entries LIKE 'display_label'");
  $exists2 = $stmt->rowCount() > 0;
  
  if (!$exists2) {
    $pdo->exec("ALTER TABLE entries ADD COLUMN display_label VARCHAR(100) DEFAULT NULL COMMENT 'Custom label for the absence'");
    echo "✓ Column 'display_label' added to entries table\n";
  } else {
    echo "✓ Column 'display_label' already exists\n";
  }
  
  echo "✓ Database schema updated successfully\n";
} catch (Exception $e) {
  echo "✗ Error: " . $e->getMessage() . "\n";
}
