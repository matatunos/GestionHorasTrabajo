<?php
/**
 * Test cuando hoy ya tiene registros (debe ser removido de remaining_days)
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
    $holQuery = 'SELECT date, type, label, annual FROM holidays WHERE (user_id = ? OR user_id IS NULL)';
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
$week_data = [];

for ($i = 1; $i <= 7; $i++) {
    $date = date('Y-m-d', strtotime($current_week_start . " +" . ($i - 1) . " days"));
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

echo "Week data:\n";
foreach ($week_data as $dow => $data) {
    $names = ['','Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
    echo "  " . $names[$dow] . " (" . $data['date'] . "): " . round($data['hours'], 2) . "h\n";
}

// Calculate remaining
$today_dow = (int)date('N', strtotime($today));
$remaining_days = [];
$remaining_hours = max(0, $target_weekly_hours - $worked_hours_this_week);

echo "\nCalculated:\n";
echo "  Today DOW: $today_dow\n";
echo "  Target: " . round($target_weekly_hours, 2) . "h\n";
echo "  Worked: " . round($worked_hours_this_week, 2) . "h\n";
echo "  Remaining: " . round($remaining_hours, 2) . "h\n\n";

// Build remaining days (as in schedule_suggestions.php)
for ($i = $today_dow; $i <= 5; $i++) {
    $check_date = date('Y-m-d', strtotime($current_week_start . " +" . ($i - 1) . " days"));
    
    if (isset($holidays_this_week[$check_date])) {
        continue;
    }
    
    if ($i >= $today_dow || ($i === $today_dow && '14:30' < '17:00')) {  // Mock time
        $remaining_days[] = $i;
    }
}

echo "Remaining days before filter: " . implode(', ', $remaining_days) . "\n";

// Remove current day if it's already registered
if (isset($week_data[$today_dow]) && $week_data[$today_dow]['hours'] > 0) {
    echo "  ❌ Today (" . $today_dow . ") has " . $week_data[$today_dow]['hours'] . "h - removing from suggestions\n";
    $remaining_days = array_filter($remaining_days, fn($d) => $d !== $today_dow);
}

echo "Remaining days after filter: " . implode(', ', $remaining_days) . "\n";

if (!empty($remaining_days)) {
    $base_per_day = $remaining_hours / count($remaining_days);
    echo "\nBase per day: " . round($base_per_day, 2) . "h\n";
    
    // Calculate final hours
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
    
    echo "\nInitial assignment:\n";
    foreach ($final_hours as $dow => $h) {
        $names = ['','Mon','Tue','Wed','Thu','Fri'];
        echo "  " . $names[$dow] . ": " . round($h, 2) . "h\n";
    }
    echo "  Total: " . round($total_adjusted, 2) . "h\n";
    
    // Rebalance
    if ($total_adjusted > 0 && abs($total_adjusted - $target_weekly_hours) > 0.01) {
        $non_friday_days = array_filter($remaining_days, fn($d) => $d !== 5);
        
        if (!empty($non_friday_days)) {
            $friday_hours = $final_hours[5] ?? 0;
            $non_friday_hours = array_sum(array_map(fn($d) => $final_hours[$d], $non_friday_days));
            $remaining_target = $target_weekly_hours - $friday_hours;
            $correction = ($remaining_target - $non_friday_hours) / count($non_friday_days);
            
            echo "\nRebalance:\n";
            echo "  Correction: " . round($correction, 2) . "h\n";
            
            foreach ($non_friday_days as $dow) {
                $final_hours[$dow] += $correction;
            }
        }
    }
    
    echo "\nFinal hours:\n";
    foreach ($final_hours as $dow => $h) {
        $names = ['','Mon','Tue','Wed','Thu','Fri'];
        echo "  " . $names[$dow] . ": " . round($h, 2) . "h\n";
    }
    echo "  Total: " . round(array_sum($final_hours), 2) . "h\n";
    
    // Check Friday constraint
    $friday_hours = $final_hours[5] ?? 0;
    echo "\n✅ Friday constraint check: $friday_hours h " . ($friday_hours <= 6.0 ? '✅' : '❌') . "\n";
}
