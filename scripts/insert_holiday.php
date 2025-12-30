<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
$pdo = get_pdo();
if (!$pdo) { echo "no pdo\n"; exit(1); }
$year = 2025;
$date = '2025-12-25';
$label = 'Navidad';
$stmt = $pdo->prepare('REPLACE INTO holidays (year,date,label) VALUES (?,?,?)');
$stmt->execute([$year, $date, $label]);
echo "Inserted holiday $date ($label)\n";
