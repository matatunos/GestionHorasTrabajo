<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
$pdo = get_pdo();
if (!$pdo) { echo "no pdo\n"; exit(1); }
$date = '2025-12-25';
$label = 'Navidad';
$stmt = $pdo->prepare('REPLACE INTO holidays (date,label) VALUES (?,?)');
$stmt->execute([$date, $label]);
echo "Inserted holiday $date ($label)\n";
