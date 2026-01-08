<?php
echo "=== Verificación: Restricción de viernes a 14:00 ===\n\n";

// Simular cálculo de viernes
$friday_start = '07:42';
$friday_max_hours = 6.0;

function time_to_mins($time) {
    if (!$time) return null;
    $parts = explode(':', $time);
    if (count($parts) < 2) return null;
    return intval($parts[0]) * 60 + intval($parts[1]);
}

$start_min = time_to_mins($friday_start);
$max_end_min = time_to_mins('14:00');

$max_hours_allowed = ($max_end_min - $start_min) / 60;

echo "Entrada viernes: $friday_start\n";
echo "Salida máxima permitida: 14:00\n";
echo "Horas máximas permitidas por horario: " . round($max_hours_allowed, 2) . " h\n";
echo "Horas máximas por config: $friday_max_hours h\n";
echo "Restricción real a aplicar: " . round(min($friday_max_hours, $max_hours_allowed), 2) . " h\n\n";

// Prueba con 7.98 horas (lo que sugiere actualmente)
$test_hours = 7.98;
echo "Si se sugieren 7.98 h:\n";
$end_min = $start_min + ($test_hours * 60);
$end_h = intdiv($end_min, 60);
$end_m = $end_min % 60;
echo "  Salida: " . sprintf('%02d:%02d', $end_h, $end_m) . " ❌ INCORRECTO (después de 14:00)\n\n";

// Correcta
$correct_hours = min($friday_max_hours, $max_hours_allowed);
echo "Con restricción correcta:\n";
echo "  Máximo: " . round($correct_hours, 2) . " h\n";
$end_min = $start_min + ($correct_hours * 60);
if ($end_min > $max_end_min) $end_min = $max_end_min;
$end_h = intdiv($end_min, 60);
$end_m = $end_min % 60;
echo "  Salida: " . sprintf('%02d:%02d', $end_h, $end_m) . " ✓ CORRECTO (14:00 o antes)\n";

?>
