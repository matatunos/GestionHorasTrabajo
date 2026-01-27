<?php
require_once __DIR__ . '/db.php';
$pdo = get_pdo();
$year = 2026;
$stmt = $pdo->prepare('SELECT summer_mon_thu, summer_friday, mon_thu, friday FROM year_configs WHERE year = ?');
$stmt->execute([$year]);
$row = $stmt->fetch();
echo "Valores en year_configs para 2026:\n";
print_r($row);
