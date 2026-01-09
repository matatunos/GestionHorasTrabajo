<?php
/**
 * JSON Response simulation of schedule_suggestions.php API
 * Using actual data from week Jan 5-9, 2026
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
                 WHERE (user_id = ? OR user_id IS NULL)
                 AND date >= ? AND date <= ?';
    $holStmt = $pdo->prepare($holQuery);
    $holStmt->execute([$user_id, $current_week_start, $current_week_end]);
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

// Get entries
$stmt = $pdo->prepare("SELECT * FROM entries WHERE user_id = ? AND date >= ? AND date <= ? ORDER BY date ASC");
$stmt->execute([$user_id, $current_week_start, $today]);
$week_entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

$worked_hours_this_week = 0;
$week_data = [];

for ($i = 1; $i <= 7; $i++) {
    $date = date('Y-m-d', strtotime($current_week_start . " +$i days"));
    $week_data[$i] = ['date' => $date, 'hours' => 0, 'start' => null, 'end' => null];
}

foreach ($week_entries as $entry) {
    if (empty($entry['start']) || empty($entry['end'])) continue;
    
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
    }
}

// Remaining
$today_dow = (int)date('N', strtotime($today));
$remaining_days = [];
$remaining_hours = max(0, $target_weekly_hours - $worked_hours_this_week);

for ($i = $today_dow; $i <= 5; $i++) {
    $check_date = date('Y-m-d', strtotime($current_week_start . " +" . ($i - 1) . " days"));
    
    if (isset($holidays_this_week[$check_date])) {
        continue;
    }
    
    if ($i >= $today_dow || ($i === $today_dow && date('H:i') < '17:00')) {
        $remaining_days[] = $i;
    }
}

if (isset($week_data[$today_dow]) && $week_data[$today_dow]['hours'] > 0) {
    $remaining_days = array_filter($remaining_days, fn($d) => $d !== $today_dow);
}

// Patterns
$patterns = [];
for ($day = 1; $day <= 5; $day++) {
    $patterns[$day] = [
        'entries' => [],
        'starts' => [],
        'ends' => [],
        'hours' => [],
        'lunch_durations' => [],
        'coffee_durations' => [],
        'total_count' => 0,
    ];
}

$stmt = $pdo->prepare("SELECT * FROM entries WHERE user_id = ? AND date >= DATE_SUB(?, INTERVAL 90 DAY) ORDER BY date ASC");
$stmt->execute([$user_id, $today]);
$hist_entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($hist_entries as $entry) {
    if (empty($entry['start']) || empty($entry['end'])) continue;
    
    $day_of_week = (int)date('N', strtotime($entry['date']));
    if ($day_of_week > 5) continue;
    
    $start_min = time_to_minutes($entry['start']);
    $end_min = time_to_minutes($entry['end']);
    $lunch_out = time_to_minutes($entry['lunch_out']);
    $lunch_in = time_to_minutes($entry['lunch_in']);
    
    if ($start_min !== null && $end_min !== null) {
        $worked_min = $end_min - $start_min;
        
        if ($lunch_out !== null && $lunch_in !== null) {
            $lunch_duration = $lunch_in - $lunch_out;
            $worked_min -= $lunch_duration;
            $patterns[$day_of_week]['lunch_durations'][] = $lunch_duration;
        }
        
        $patterns[$day_of_week]['entries'][] = [
            'date' => $entry['date'],
            'start' => $entry['start'],
            'end' => $entry['end'],
            'minutes' => $worked_min,
            'weight' => 3.0
        ];
        
        $patterns[$day_of_week]['starts'][] = $entry['start'];
        $patterns[$day_of_week]['ends'][] = $entry['end'];
        $patterns[$day_of_week]['hours'][] = $worked_min / 60;
        $patterns[$day_of_week]['total_count']++;
    }
}

// Shift pattern
$is_split_shift = true;
$monday_date = '2026-01-05';
$shift_stmt = $pdo->prepare("SELECT lunch_out, lunch_in FROM entries WHERE user_id = ? AND date = ? LIMIT 1");
$shift_stmt->execute([$user_id, $monday_date]);
$monday_entry = $shift_stmt->fetch(PDO::FETCH_ASSOC);
if ($monday_entry && !empty($monday_entry['lunch_out']) && !empty($monday_entry['lunch_in'])) {
    $is_split_shift = true;
} else {
    $is_split_shift = false;
}

// Build suggestions
$suggestions = [];
$day_name_map = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday'];
$day_name_es = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes'];

if (!empty($remaining_days) && $remaining_hours > 0.5) {
    $base_per_day = $remaining_hours / count($remaining_days);
    
    foreach ($remaining_days as $dow) {
        $day_date = date('Y-m-d', strtotime($current_week_start . " +$dow days"));
        $pattern = $patterns[$dow];
        
        $suggested_hours = round($base_per_day, 2);
        
        $suggested_start = !empty($pattern['starts']) 
            ? sprintf('%02d:%02d', 
                intval(array_sum(array_map('time_to_minutes', $pattern['starts'])) / count($pattern['starts']) / 60),
                intval(array_sum(array_map('time_to_minutes', $pattern['starts'])) % (count($pattern['starts']) * 60)) / count($pattern['starts']))
            : ($dow === 5 ? '09:00' : '08:00');
        
        $start_min = time_to_minutes($suggested_start);
        
        if ($is_split_shift && $dow !== 5) {
            $avg_lunch = !empty($pattern['lunch_durations']) 
                ? array_sum($pattern['lunch_durations']) / count($pattern['lunch_durations']) 
                : 60;
            $end_min = $start_min + ($suggested_hours * 60) + $avg_lunch;
        } else {
            $end_min = $start_min + ($suggested_hours * 60);
        }
        
        if ($end_min >= 1440) $end_min -= 1440;
        
        $end_hours = intdiv($end_min, 60);
        $end_mins = $end_min % 60;
        $suggested_end = sprintf('%02d:%02d', $end_hours, $end_mins);
        
        $confidence = $pattern['total_count'] >= 3 ? 'alta' : ($pattern['total_count'] > 0 ? 'media' : 'baja');
        
        $shift_type = ($dow === 5) ? 'continua' : ($is_split_shift ? 'partida' : 'continua');
        $has_lunch_break = ($shift_type === 'partida');
        
        $suggestions[] = [
            'date' => $day_date,
            'day_name' => $day_name_map[$dow],
            'day_name_es' => $day_name_es[$dow],
            'day_of_week' => $dow,
            'start' => $suggested_start,
            'end' => $suggested_end,
            'hours' => sprintf('%.2f', $suggested_hours),
            'hours_hhmm' => sprintf('%02d:%02d', intval($suggested_hours), intval(($suggested_hours * 60) % 60)),
            'confidence' => $confidence,
            'pattern_count' => $pattern['total_count'],
            'reasoning' => sprintf('Basado en %d registros históricos | Jornada %s', $pattern['total_count'], $shift_type),
            'shift_type' => $shift_type,
            'shift_label' => $shift_type === 'partida' ? 'Jornada Partida' : 'Jornada Continua',
            'has_lunch_break' => $has_lunch_break,
            'lunch_duration_minutes' => $has_lunch_break ? 60 : 0,
            'lunch_note' => $has_lunch_break ? 'Pausa comida: ~60 min' : 'Sin pausa comida'
        ];
    }
}

echo json_encode([
    'success' => true,
    'worked_this_week' => round($worked_hours_this_week, 2),
    'target_weekly_hours' => round($target_weekly_hours, 2),
    'remaining_hours' => round($remaining_hours, 2),
    'week_data' => $week_data,
    'suggestions' => $suggestions,
    'shift_pattern' => [
        'type' => $is_split_shift ? 'jornada_partida' : 'jornada_continua',
        'label' => $is_split_shift ? 'Jornada Partida (con pausa comida)' : 'Jornada Continua (sin pausa)',
        'applies_to' => 'Lunes a Jueves (Viernes siempre es continua)',
        'detected_from' => 'Entrada del lunes de la semana actual'
    ],
    'analysis' => [
        'lookback_days' => 90,
        'patterns_analyzed' => true,
        'days_remaining' => count($remaining_days),
        'forced_start_time' => null,
        'holidays_this_week' => count($holidays_this_week),
        'holidays_excluded_from_target' => count(array_filter($holidays_this_week, fn($h) => {
            $dow = (int)date('N', strtotime(array_key_first($h) ?? date('Y-m-d')));
            return $dow >= 1 && $dow <= 5;
        }))
    ],
    'message' => count($suggestions) > 0
        ? sprintf('Se sugieren horarios inteligentes para %d días basado en patrones históricos', count($suggestions))
        : ($remaining_hours < 0.5 ? 'Objetivo semanal completado ✓' : 'Sin días disponibles para completar la semana')
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

?>
