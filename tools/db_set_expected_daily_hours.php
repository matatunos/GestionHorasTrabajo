<?php
require_once __DIR__ . '/db.php';
$pdo = get_pdo();
$year = 2026;
$stmt = $pdo->prepare('UPDATE year_configs SET expected_daily_hours_winter = ?, expected_daily_hours_summer = ? WHERE year = ?');
$stmt->execute([7.65, 7.0, $year]);
echo "Valores configurados: invierno=7.65, verano=7.0 para 2026.\n";
