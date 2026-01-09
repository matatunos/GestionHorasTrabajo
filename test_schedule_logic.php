<?php
/**
 * Test schedule_suggestions.php logic with actual data from week Jan 5-9, 2026
 * This simulates the API call to see what suggestions are generated
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib.php';

// Simulate being today (Jan 7, 2026 - Wednesday)
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = [];

$pdo = get_pdo();
$user_id = 1; // Test with user 1

// Set dates for analysis
$today = '2026-01-07'; // Wednesday
$current_year = 2026;
$current_week_start = '2026-01-05'; // Monday
$current_week_end = '2026-01-11'; // Sunday

echo "=== PRUEBA: schedule_suggestions.php CON DATOS DEL 5-9 ENERO 2026 ===\n";
echo "Usuario: $user_id\n";
echo "Hoy: $today (Miércoles)\n";
echo "Semana: $current_week_start a $current_week_end\n\n";

// Get year config
$year_config = get_year_config($current_year, $user_id);

// Load holidays for this week
$holidays_this_week = [];
try {
    // First query ALL holidays (not just those in date range) so we can reconstruct annual holidays
    $holQuery = 'SELECT date, type, label, annual FROM holidays 
                 WHERE (user_id = ? OR user_id IS NULL)';
    $holStmt = $pdo->prepare($holQuery);
    $holStmt->execute([$user_id]);
    $holidays_raw = $holStmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($holidays_raw as $hol) {
        $hDate = $hol['date'];
        if (!empty($hol['annual'])) {
            $hMonth = intval(substr($hDate, 5, 2));
            $hDay = intval(substr($hDate, 8, 2));
            $hDate = sprintf('%04d-%02d-%02d', $current_year, $hMonth, $hDay);
        }
        if ($hDate >= $current_week_start && $hDate <= $current_week_end) {
            $holidays_this_week[$hDate] = $hol;
        }
    }
} catch (Exception $e) {
    // No holidays table
}

echo "PASO 1: Cargar festivos de esta semana\n";
echo "  Festivos encontrados: " . count($holidays_this_week) . "\n";
if (count($holidays_this_week) > 0) {
    foreach ($holidays_this_week as $date => $hol) {
        echo "    - $date: " . $hol['type'] . "\n";
    }
}
echo "\n";

// Calculate target weekly hours
$friday_config_hours = $year_config['work_hours']['winter']['friday'] ?? 6.0;
$friday_worked_hours = max(5.0, $friday_config_hours - 1.0);

$base_target_hours = (
    ($year_config['work_hours']['winter']['mon_thu'] ?? 8.0) * 4 + 
    $friday_worked_hours
);

$base_target_weekly_hours = $base_target_hours;
foreach ($holidays_this_week as $hDate => $holiday) {
    $dow = (int)date('N', strtotime($hDate));
    if ($dow >= 1 && $dow <= 5) {
        if ($dow === 5) {
            $base_target_weekly_hours -= $friday_worked_hours;
        } else {
            $base_target_weekly_hours -= ($year_config['work_hours']['winter']['mon_thu'] ?? 8.0);
        }
    }
}

$target_weekly_hours = $base_target_weekly_hours;

echo "PASO 2: Calcular objetivo semanal (ajustado por festivos)\n";
echo "  Config base Mon-Thu: " . ($year_config['work_hours']['winter']['mon_thu'] ?? 8.0) . " h\n";
echo "  Config base Viernes: " . $friday_config_hours . " h\n";
echo "  Horas trabajadas viernes (config): " . $friday_worked_hours . " h\n";
echo "  Objetivo bruto (40 h / 5 días): " . $base_target_hours . " h\n";
echo "  Horas restadas por festivos: " . ($base_target_hours - $base_target_weekly_hours) . " h\n";
echo "  OBJETIVO AJUSTADO: " . $target_weekly_hours . " h\n\n";

// Get entries for this week (up to today)
$stmt = $pdo->prepare(
    "SELECT * FROM entries 
     WHERE user_id = ? AND date >= ? AND date <= ? 
     ORDER BY date ASC"
);
$stmt->execute([$user_id, $current_week_start, $today]);
$week_entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate hours worked so far
$worked_hours_this_week = 0;
$week_data = [];

for ($i = 1; $i <= 7; $i++) {
    $date = date('Y-m-d', strtotime($current_week_start . " +$i days"));
    $week_data[$i] = ['date' => $date, 'hours' => 0, 'start' => null, 'end' => null];
}

echo "PASO 3: Calcular horas ya trabajadas\n";
foreach ($week_entries as $entry) {
    if (empty($entry['start']) || empty($entry['end'])) {
        echo "  " . $entry['date'] . ": Sin salida registrada (fichaje impar)\n";
        continue;
    }
    
    $entry_calc = compute_day($entry, $year_config);
    
    if ($entry_calc['worked_minutes_for_display'] !== null) {
        $hours = $entry_calc['worked_minutes_for_display'] / 60;
        $worked_hours_this_week += $hours;
        
        $dow = (int)date('N', strtotime($entry['date']));
        if (isset($week_data[$dow])) {
            $week_data[$dow]['hours'] = round($hours, 2);
            $week_data[$dow]['start'] = $entry['start'];
            $week_data[$dow]['end'] = $entry['end'];
        }
        
        $dow_map = [1 => 'L', 2 => 'M', 3 => 'X', 4 => 'J', 5 => 'V'];
        echo "  " . $entry['date'] . " (" . $dow_map[$dow] . "): " . round($hours, 2) . " h\n";
    }
}

echo "  TOTAL TRABAJADO HASTA HOY: " . round($worked_hours_this_week, 2) . " h\n\n";

// Determine remaining days
$today_dow = (int)date('N', strtotime($today)); // 3 = Miércoles
$remaining_days = [];
$remaining_hours = max(0, $target_weekly_hours - $worked_hours_this_week);

echo "PASO 4: Determinar días restantes\n";
echo "  Hoy es: día " . $today_dow . " (Miércoles)\n";

for ($i = $today_dow; $i <= 5; $i++) {
    $check_date = date('Y-m-d', strtotime($current_week_start . " +" . ($i - 1) . " days"));
    
    if (isset($holidays_this_week[$check_date])) {
        echo "  Día $i ($check_date): EXCLUIDO (Festivo)\n";
        continue;
    }
    
    if ($i >= $today_dow || ($i === $today_dow && date('H:i') < '17:00')) {
        $remaining_days[] = $i;
        $dow_map = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes'];
        echo "  Día $i (" . $dow_map[$i] . "): INCLUIDO\n";
    }
}

// Remove current day if already registered
if (isset($week_data[$today_dow]) && $week_data[$today_dow]['hours'] > 0) {
    $remaining_days = array_filter($remaining_days, fn($d) => $d !== $today_dow);
    echo "  (Día actual excluido porque ya tiene fichajes registrados)\n";
}

echo "  Días disponibles: " . implode(', ', array_map(fn($d) => [1 => 'L', 2 => 'M', 3 => 'X', 4 => 'J', 5 => 'V'][$d] ?? '', $remaining_days)) . "\n";
echo "  Horas restantes a trabajar: " . round($remaining_hours, 2) . " h\n\n";

// Summary
echo "=== RESUMEN FINAL ===\n";
echo "Objetivo semanal (ajustado): " . round($target_weekly_hours, 2) . " h\n";
echo "Horas trabajadas (hasta hoy): " . round($worked_hours_this_week, 2) . " h\n";
echo "Horas pendientes: " . round($remaining_hours, 2) . " h\n";
echo "Días disponibles para trabajar: " . count($remaining_days) . "\n";

if (!empty($remaining_days) && $remaining_hours > 0.5) {
    $per_day = $remaining_hours / count($remaining_days);
    echo "Promedio por día: " . round($per_day, 2) . " h\n";
}

?>
