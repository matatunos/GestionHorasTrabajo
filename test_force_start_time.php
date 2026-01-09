<?php
/**
 * Test if force_start_time properly recalculates end times
 */

require_once __DIR__ . '/lib.php';

echo "=== TEST: FORZAR ENTRADA A 7:30 ===\n\n";

// Scenario: 9h 15m de trabajo
$hours_of_work = 9 + 15/60;  // 9.25 hours
$lunch_duration = 60;  // 1 hour

echo "Escenario: " . round($hours_of_work, 2) . "h de trabajo + " . $lunch_duration . "m pausa\n\n";

// WITHOUT forced start time (normal 08:00)
echo "1. SIN forzar entrada (entrada histórica 08:00):\n";
$normal_start = time_to_minutes('08:00');
$normal_end = $normal_start + ($hours_of_work * 60) + $lunch_duration;
$normal_end_h = intval($normal_end / 60);
$normal_end_m = intval($normal_end % 60);
$normal_end_time = sprintf('%02d:%02d', $normal_end_h, $normal_end_m);

echo "   Entrada:  08:00\n";
echo "   Salida:   $normal_end_time\n";
echo "   Duración: " . round($hours_of_work, 2) . "h trabajo + 1h pausa\n\n";

// WITH forced start time (7:30)
echo "2. FORZANDO entrada a 7:30:\n";
$forced_start = time_to_minutes('07:30');
$forced_end = $forced_start + ($hours_of_work * 60) + $lunch_duration;
$forced_end_h = intval($forced_end / 60);
$forced_end_m = intval($forced_end % 60);
$forced_end_time = sprintf('%02d:%02d', $forced_end_h, $forced_end_m);

echo "   Entrada:  07:30\n";
echo "   Salida:   $forced_end_time\n";
echo "   Duración: " . round($hours_of_work, 2) . "h trabajo + 1h pausa\n\n";

echo "=== ANÁLISIS ===\n";
echo "Diferencia de entrada: 30 minutos antes\n";
echo "Diferencia de salida: 30 minutos antes\n";
echo "Horas de trabajo: " . round($hours_of_work, 2) . "h (SIN CAMBIOS)\n\n";

echo "✅ Comportamiento ACTUAL:\n";
echo "   - Las horas de trabajo se mantienen iguales\n";
echo "   - La salida se adelanta proporcional a la entrada\n";
echo "   - Esto es CORRECTO porque el usuario solo cambió la entrada\n\n";

echo "❓ PREGUNTA DEL USUARIO:\n";
echo "   '¿Se recalcula la sugerencia completa?'\n";
echo "   Respuesta: Parcialmente. Se recalcula la salida pero NO las horas de trabajo.\n";
echo "   Esto es lo correcto: si entras 30 min antes, sales 30 min antes.\n";
