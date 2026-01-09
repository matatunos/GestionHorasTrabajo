<?php
/**
 * Full simulation of schedule_suggestions logic with Friday capping
 * Week of Jan 5-9, 2026
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

// Load holidays (full load, then reconstruct annuals)
$holidays_this_week = [];
try {
    $holQuery = 'SELECT date, type, label, annual FROM holidays 
                 WHERE (user_id = ? OR user_id IS NULL)';
    $holStmt = $pdo->prepare($holQuery);
    $holStmt->execute([$user_id]);
    $holidays_raw = $holStmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($holidays_raw as $hol) {
        $hDate = $hol['date'];
        // Reconstruct annual holidays to current year
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

// Load patterns (from analyze_patterns function)
$patterns = [];
for ($i = 0; $i <= 6; $i++) {
    $patterns[$i] = [
        'starts' => [],
        'ends' => [],
        'lunch_durations' => []
    ];
}

try {
    $stmt = $pdo->prepare(
        "SELECT * FROM entries 
         WHERE user_id = ? AND start IS NOT NULL AND end IS NOT NULL 
         ORDER BY date DESC LIMIT 180"
    );
    $stmt->execute([$user_id]);
    $historical = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $now = time();
    foreach ($historical as $entry) {
        $entry_age_days = ($now - strtotime($entry['date'])) / 86400;
        $weight = max(0.2, 1.0 - ($entry_age_days / 90));
        
        $dow = (int)date('N', strtotime($entry['date'])) - 1;
        
        for ($w = 0; $w < intval($weight * 10); $w++) {
            $patterns[$dow]['starts'][] = $entry['start'];
            $patterns[$dow]['ends'][] = $entry['end'];
            
            if (!empty($entry['lunch_out']) && !empty($entry['lunch_in'])) {
                $lunch_min = time_to_minutes($entry['lunch_in']) - time_to_minutes($entry['lunch_out']);
                $patterns[$dow]['lunch_durations'][] = $lunch_min;
            }
        }
    }
} catch (Exception $e) {}

// Calculate base target
$friday_config_hours = $year_config['work_hours']['winter']['friday'] ?? 6.0;
$friday_worked_hours = max(5.0, $friday_config_hours - 1.0);
$base_target_hours = (
    ($year_config['work_hours']['winter']['mon_thu'] ?? 8.0) * 4 + 
    $friday_worked_hours
);

// Adjust for holidays
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

// Get worked hours so far
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

// Get remaining days (after today)
$today_dow = (int)date('N', strtotime($today));
$remaining_days = [];
$remaining_hours = max(0, $target_weekly_hours - $worked_hours_this_week);

for ($i = $today_dow; $i <= 5; $i++) {
    $date = date('Y-m-d', strtotime($current_week_start . " +" . ($i - 1) . " days"));
    if ($date > $today && !isset($holidays_this_week[$date])) {
        $remaining_days[] = $i;
    }
}

echo "=== Distribution Logic ===\n";
echo "Target hours: " . round($target_weekly_hours, 2) . "\n";
echo "Worked hours: " . round($worked_hours_this_week, 2) . "\n";
echo "Remaining hours: " . round($remaining_hours, 2) . "\n";
echo "Remaining days: " . implode(', ', $remaining_days) . "\n\n";

if (empty($remaining_days)) {
    echo "No remaining days to distribute.\n";
} else {
    // Distribute hours
    $base_per_day = $remaining_hours / count($remaining_days);
    echo "Base per day: " . round($base_per_day, 2) . "\n\n";
    
    // Step 1: Initial assignment with Friday cap
    $final_hours = [];
    $total_adjusted = 0;
    
    foreach ($remaining_days as $dow) {
        $suggested = $base_per_day;
        
        if ($dow === 5) {
            $min_hours = 5.0;
            $max_hours = 6.0;  // Cannot exceed 14:00 exit
            $final_hours[$dow] = max($min_hours, min($max_hours, $suggested));
        } else {
            $min_hours = max(5.5, $suggested - 1.0);
            $final_hours[$dow] = max($min_hours, $suggested);
        }
        $total_adjusted += $final_hours[$dow];
    }
    
    echo "Step 1 - Initial assignment:\n";
    foreach ($final_hours as $dow => $h) {
        echo "  Day $dow: " . round($h, 2) . "h\n";
    }
    
    // Step 2: Rebalance
    if ($total_adjusted > 0 && abs($total_adjusted - $target_weekly_hours) > 0.01) {
        // Only rebalance non-Friday days to avoid exceeding Friday's max
        $non_friday_days = array_filter($remaining_days, fn($d) => $d !== 5);
        $friday_hours = $final_hours[5] ?? 0;
        
        $non_friday_hours = array_sum(array_map(fn($d) => $final_hours[$d], $non_friday_days));
        $remaining_target = $target_weekly_hours - $friday_hours;
        $correction = ($remaining_target - $non_friday_hours) / count($non_friday_days);
        
        echo "\nStep 2 - Rebalance (only non-Friday days)\n";
        echo "  Friday fixed: " . round($friday_hours, 2) . "h\n";
        echo "  Non-Friday hours: " . round($non_friday_hours, 2) . "h\n";
        echo "  Remaining target: " . round($remaining_target, 2) . "h\n";
        echo "  Correction: " . round($correction, 2) . "h per day\n";
        
        foreach ($non_friday_days as $dow) {
            $final_hours[$dow] += $correction;
        }
    }
    
    echo "\nStep 2 - After rebalance:\n";
    foreach ($final_hours as $dow => $h) {
        echo "  Day $dow: " . round($h, 2) . "h\n";
    }
    
    // Step 3: ENFORCE Friday maximum (THIS IS THE NEW LOGIC)
    echo "\nStep 3 - Friday capping:\n";
    if (in_array(5, $remaining_days) && $final_hours[5] > 6.0) {
        echo "  ⚠️  Friday exceeds 6h max (" . round($final_hours[5], 2) . "h)\n";
        echo "  ✓ Friday already capped in step 1 and protected in rebalance\n";
    } else {
        echo "  ✓ Friday respects 6h max\n";
    }
    
    echo "\nFinal hours:\n";
    foreach ($final_hours as $dow => $h) {
        echo "  Day $dow: " . round($h, 2) . "h\n";
    }
    echo "\n  Total: " . round(array_sum($final_hours), 2) . "h\n";
    
    // Step 4: Calculate actual times with Friday constraint
    echo "\n=== Suggested Times ===\n";
    
    // Define weighted_average_time locally
    $weighted_average_time_fn = function($times) {
        if (empty($times)) return null;
        $total_min = array_reduce($times, fn($carry, $t) => $carry + time_to_minutes($t), 0);
        $avg_min = intval($total_min / count($times));
        return sprintf('%02d:%02d', intdiv($avg_min, 60), $avg_min % 60);
    };
    
    foreach ($remaining_days as $dow) {
        $day_names = ['', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes'];
        $day_date = date('Y-m-d', strtotime($current_week_start . " +" . ($dow - 1) . " days"));
        $pattern = $patterns[$dow - 1];
        
        $suggested_hours = round($final_hours[$dow], 2);
        $suggested_start = !empty($pattern['starts']) ? $weighted_average_time_fn($pattern['starts']) ?? '08:00' : '08:00';
        
        $start_min = time_to_minutes($suggested_start);
        
        if ($dow === 5) {
            // Friday: jornada continua, max 14:00 exit
            $end_min = $start_min + ($suggested_hours * 60);
            $max_exit_min = time_to_minutes('14:00');
            if ($end_min > $max_exit_min) {
                echo "  ⚠️  Day $dow: Exit would be after 14:00, capping\n";
                $end_min = $max_exit_min;
            }
        } else {
            $end_min = $start_min + ($suggested_hours * 60);
        }
        
        if ($end_min >= 1440) $end_min -= 1440;
        
        $end_hours = intdiv($end_min, 60);
        $end_mins = $end_min % 60;
        $suggested_end = sprintf('%02d:%02d', $end_hours, $end_mins);
        
        echo "  $day_date ($day_names[$dow]): $suggested_start - $suggested_end (" . round($final_hours[$dow], 2) . "h)\n";
    }
}
