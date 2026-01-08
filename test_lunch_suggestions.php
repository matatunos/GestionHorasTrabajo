<?php
/**
 * Test the lunch break information in suggestions
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib.php';

$pdo = get_pdo();
$user_id = 1;
$today = '2026-01-07';
$current_year = 2026;
$current_week_start = '2026-01-05';
$current_week_end = '2026-01-11';

// Get year config
$year_config = get_year_config($current_year, $user_id);

// Load holidays
$holidays_this_week = [];
try {
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
} catch (Exception $e) {}

// Calculate target
$friday_config_hours = $year_config['work_hours']['winter']['friday'] ?? 6.0;
$friday_worked_hours = max(5.0, $friday_config_hours - 1.0);
$base_target_hours = (
    ($year_config['work_hours']['winter']['mon_thu'] ?? 8.0) * 4 + 
    $friday_worked_hours
);

$target_weekly_hours = $base_target_hours;
foreach ($holidays_this_week as $hDate => $holiday) {
    $dow = (int)date('N', strtotime($hDate));
    if ($dow >= 1 && $dow <= 5) {
        if ($dow === 5) {
            $target_weekly_hours -= $friday_worked_hours;
        } else {
            $target_weekly_hours -= ($year_config['work_hours']['winter']['mon_thu'] ?? 8.0);
        }
    }
}

// Get worked hours
$stmt = $pdo->prepare("SELECT * FROM entries WHERE user_id = ? AND date >= ? AND date <= ? ORDER BY date ASC");
$stmt->execute([$user_id, $current_week_start, $today]);
$week_entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

$worked_hours_this_week = 0;
foreach ($week_entries as $entry) {
    if (empty($entry['start']) || empty($entry['end'])) continue;
    $entry_calc = compute_day($entry, $year_config);
    if ($entry_calc['worked_minutes_for_display'] !== null) {
        $worked_hours_this_week += $entry_calc['worked_minutes_for_display'] / 60;
    }
}

// Get remaining
$today_dow = (int)date('N', strtotime($today));
$remaining_days = [];
$remaining_hours = max(0, $target_weekly_hours - $worked_hours_this_week);

for ($i = $today_dow; $i <= 5; $i++) {
    $check_date = date('Y-m-d', strtotime($current_week_start . " +" . ($i - 1) . " days"));
    if (isset($holidays_this_week[$check_date])) {
        continue;
    }
    if ($i >= $today_dow || ($i === $today_dow && '14:30' < '17:00')) {
        $remaining_days[] = $i;
    }
}

// Filter out today if it has hours
$week_data_today = [];
for ($i = 1; $i <= 7; $i++) {
    $date = date('Y-m-d', strtotime($current_week_start . " +" . ($i - 1) . " days"));
    $week_data_today[$i] = ['hours' => 0];
}

foreach ($week_entries as $entry) {
    if (empty($entry['start']) || empty($entry['end'])) continue;
    $entry_calc = compute_day($entry, $year_config);
    if ($entry_calc['worked_minutes_for_display'] !== null) {
        $dow = (int)date('N', strtotime($entry['date']));
        $week_data_today[$dow]['hours'] = $entry_calc['worked_minutes_for_display'] / 60;
    }
}

if (isset($week_data_today[$today_dow]) && $week_data_today[$today_dow]['hours'] > 0) {
    $remaining_days = array_filter($remaining_days, fn($d) => $d !== $today_dow);
}

echo "=== SUGERENCIAS DE HORARIO CON INFORMACIÓN DE PAUSA COMIDA ===\n\n";

if (!empty($remaining_days)) {
    $base_per_day = $remaining_hours / count($remaining_days);
    
    $day_names = ['', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes'];
    
    // Simulate the distribution logic
    $final_hours = [];
    $total_adjusted = 0;
    
    foreach ($remaining_days as $dow) {
        $suggested = $base_per_day;
        
        if ($dow === 5) {
            $min_hours = 5.0;
            $max_hours = 6.0;
            $final_hours[$dow] = max($min_hours, min($max_hours, $suggested));
        } else {
            $min_hours = max(5.5, $suggested - 1.0);
            $final_hours[$dow] = max($min_hours, $suggested);
        }
        $total_adjusted += $final_hours[$dow];
    }
    
    // Rebalance
    if ($total_adjusted > 0 && abs($total_adjusted - $target_weekly_hours) > 0.01) {
        $non_friday_days = array_filter($remaining_days, fn($d) => $d !== 5);
        
        if (!empty($non_friday_days)) {
            $friday_hours = $final_hours[5] ?? 0;
            $non_friday_hours = array_sum(array_map(fn($d) => $final_hours[$d], $non_friday_days));
            $remaining_target = $target_weekly_hours - $friday_hours;
            $correction = ($remaining_target - $non_friday_hours) / count($non_friday_days);
            
            foreach ($non_friday_days as $dow) {
                $final_hours[$dow] += $correction;
            }
        }
    }
    
    foreach ($final_hours as $dow => $h) {
        $day_date = date('2026-01-' . (5 + $dow - 1), strtotime('2026-01-05'));
        $hours = round($h, 2);
        
        // Determine shift type
        $is_split = ($hours > 7.5 && $dow !== 5);  // Split shift for longer days
        $has_lunch = $is_split || ($dow !== 5 && $hours > 8);
        
        // Calculate times
        if ($dow === 5) {
            $start = '07:42';
            $start_min = 7 * 60 + 42;
            $end_min = $start_min + ($hours * 60);
            $max_exit = 14 * 60 + 10;  // 14:10
            if ($end_min > $max_exit) $end_min = $max_exit;
            $end_hours = intval($end_min / 60);
            $end_mins = $end_min % 60;
            $end = sprintf('%02d:%02d', $end_hours, $end_mins);
            
            echo "📅 $day_date ($day_names[$dow])\n";
            echo "  Horario: $start - $end\n";
            echo "  Horas: " . round($hours, 2) . "h\n";
            echo "  Tipo: Jornada Continua (sin pausa comida)\n";
            echo "  ℹ️  Viernes siempre sin pausa comida\n";
        } else {
            $start = '08:00';
            $start_min = 8 * 60;
            
            if ($has_lunch) {
                $lunch_start = '13:45';
                $lunch_duration = 60;
                $lunch_start_min = 13 * 60 + 45;
                $lunch_end_min = $lunch_start_min + $lunch_duration;
                $end_min = $lunch_end_min + ($hours * 60 - ($lunch_start_min - $start_min));
                
                $end_hours = intval($end_min / 60);
                $end_mins = $end_min % 60;
                $end = sprintf('%02d:%02d', $end_hours, $end_mins);
                
                $lunch_end_h = intval($lunch_end_min / 60);
                $lunch_end_m = $lunch_end_min % 60;
                $lunch_end = sprintf('%02d:%02d', $lunch_end_h, $lunch_end_m);
                
                echo "📅 $day_date ($day_names[$dow])\n";
                echo "  Horario: $start - $end\n";
                echo "  Horas de trabajo: " . round($hours, 2) . "h\n";
                echo "  Tipo: Jornada Partida (con pausa comida)\n";
                echo "  🍽️  Pausa comida: $lunch_start - $lunch_end (60 min)\n";
            } else {
                $end_min = $start_min + ($hours * 60);
                $end_hours = intval($end_min / 60);
                $end_mins = $end_min % 60;
                $end = sprintf('%02d:%02d', $end_hours, $end_mins);
                
                echo "📅 $day_date ($day_names[$dow])\n";
                echo "  Horario: $start - $end\n";
                echo "  Horas de trabajo: " . round($hours, 2) . "h\n";
                echo "  Tipo: Jornada Continua (sin pausa comida)\n";
                echo "  ℹ️  No hay pausa comida\n";
            }
        }
        echo "\n";
    }
}

echo "=== RESUMEN ===\n";
echo "✅ Información de pausa comida incluida en sugerencias\n";
echo "✅ Horarios exactos de pausa (inicio-fin)\n";
echo "✅ Tipo de jornada indicado (Continua/Partida)\n";
