<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib.php';

$pdo = get_pdo();
$user_id = 1;
$today = '2026-01-07';
$current_year = 2026;
$week_start = '2026-01-05';

echo "=== Verificación: Restricción de viernes a 14:00 ===\n\n";

// Simular cálculo de viernes
$friday_start = '07:42';
$friday_max_hours = 6.0;

function time_to_minutes($time) {
    if (!$time) return null;
    $parts = explode(':', $time);
    if (count($parts) < 2) return null;
    return intval($parts[0]) * 60 + intval($parts[1]);
}

$start_min = time_to_minutes($friday_start);
$max_end_min = time_to_minutes('14:00');

$max_hours_allowed = ($max_end_min - $start_min) / 60;

echo "Entrada viernes: $friday_start\n";
echo "Salida máxima: 14:00\n";
echo "Horas máximas que se pueden trabajar: $max_hours_allowed h\n";
echo "Config permite máximo: $friday_max_hours h\n";
echo "Restricción real: " . min($friday_max_hours, $max_hours_allowed) . " h\n\n";

// Prueba con diferentes horas
$test_hours = 7.98;
echo "Si se sugieren 7.98 h:\n";
$end_min = $start_min + ($test_hours * 60);
$end_h = intdiv($end_min, 60);
$end_m = $end_min % 60;
echo "  Salida: " . sprintf('%02d:%02d', $end_h, $end_m) . " ❌ INCORRECTO (después de 14:00)\n\n";

// Correcta
$correct_hours = min($friday_max_hours, $max_hours_allowed);
echo "Correcto: máximo " . round($correct_hours, 2) . " h\n";
$end_min = $start_min + ($correct_hours * 60);
if ($end_min > $max_end_min) $end_min = $max_end_min;
$end_h = intdiv($end_min, 60);
$end_m = $end_min % 60;
echo "  Salida: " . sprintf('%02d:%02d', $end_h, $end_m) . " ✓ CORRECTO (14:00 o antes)\n";

?>
