<?php
require_once __DIR__ . '/db.php';
$pdo = get_pdo();
$year = 2026;
// Actualizar el campo config para reflejar 7.0h en verano (lunes-viernes)
$config = json_encode([
    'work_hours' => [
        'winter' => ['mon_thu' => 8.0, 'friday' => 6.0],
        'summer' => ['mon_thu' => 7.0, 'friday' => 7.0]
    ],
    'coffee_minutes' => 15,
    'lunch_minutes' => 30
]);
$stmt = $pdo->prepare('UPDATE year_configs SET config = ? WHERE year = ?');
$stmt->execute([$config, $year]);
echo "Campo config actualizado para 2026.\n";
