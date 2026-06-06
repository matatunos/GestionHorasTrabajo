<?php
require_once __DIR__ . '/db.php';
$pdo = get_pdo();
$year = 2026;
// Actualizar todos los campos relevantes para verano 2026 a 7.0h
$config = json_encode([
    'work_hours' => [
        'winter' => ['mon_thu' => 8.0, 'friday' => 6.0],
        'summer' => ['mon_thu' => 7.0, 'friday' => 7.0]
    ],
    'coffee_minutes' => 15,
    'lunch_minutes' => 30
]);
$stmt = $pdo->prepare('UPDATE year_configs SET config = ?, summer_mon_thu = ?, summer_friday = ? WHERE year = ?');
$stmt->execute([$config, 7.0, 7.0, $year]);
echo "Todos los campos de verano 2026 actualizados a 7.0h.\n";
