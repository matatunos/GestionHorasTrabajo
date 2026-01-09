<?php
/**
 * Extract and test the distribution logic from schedule_suggestions.php
 * This directly tests the Friday capping implementation
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

echo "=" . str_repeat("=", 79) . "\n";
echo "FRIDAY CONSTRAINT VERIFICATION\n";
echo "=" . str_repeat("=", 79) . "\n\n";

echo "📊 WEEK DATA:\n";
echo "  Target: " . round($target_weekly_hours, 2) . "h (adjusted for holidays)\n";
echo "  Worked: " . round($worked_hours_this_week, 2) . "h\n";
echo "  Remaining: " . round($remaining_hours, 2) . "h\n";
echo "  Remaining days: " . implode(', ', array_map(fn($d) => ['Mon','Tue','Wed','Thu','Fri'][$d-1], $remaining_days)) . "\n\n";

if (!empty($remaining_days)) {
    // === EXACTLY AS IN schedule_suggestions.php ===
    
    // Calculate final hours per day with adjustments
    $final_hours = [];
    $total_adjusted = 0;
    $base_per_day = $remaining_hours / count($remaining_days);
    
    foreach ($remaining_days as $dow) {
        $suggested = $base_per_day;
        
        // Ensure minimum 5.5 hours but respect Friday constraint
        if ($dow === 5) {
            // Friday: maximum 6 hours (exit at 14:00)
            $min_hours = 5.0;
            $max_hours = 6.0;  // Cannot exceed 14:00 exit
            $final_hours[$dow] = max($min_hours, min($max_hours, $suggested));
        } else {
            $min_hours = max(5.5, $suggested - 1.0);
            $final_hours[$dow] = max($min_hours, $suggested);
        }
        $total_adjusted += $final_hours[$dow];
    }
    
    echo "Step 1 - Initial assignment (respecting Friday cap):\n";
    foreach ($final_hours as $dow => $h) {
        $day_name = ['','Mon','Tue','Wed','Thu','Fri'][$dow];
        echo "  $day_name: " . round($h, 2) . "h\n";
    }
    echo "  Total: " . round($total_adjusted, 2) . "h\n\n";
    
    // Rebalance to hit exact target (protect Friday from excess)
    if ($total_adjusted > 0 && abs($total_adjusted - $target_weekly_hours) > 0.01) {
        // Only rebalance non-Friday days to protect Friday's 6-hour maximum
        $non_friday_days = array_filter($remaining_days, fn($d) => $d !== 5);
        
        if (!empty($non_friday_days)) {
            $friday_hours = $final_hours[5] ?? 0;
            $non_friday_hours = array_sum(array_map(fn($d) => $final_hours[$d], $non_friday_days));
            $remaining_target = $target_weekly_hours - $friday_hours;
            $correction = ($remaining_target - $non_friday_hours) / count($non_friday_days);
            
            echo "Step 2 - Rebalance to target (only non-Friday days):\n";
            echo "  Correction: " . round($correction, 2) . "h per day\n";
            echo "  Non-Friday target: " . round($remaining_target, 2) . "h\n";
            
            foreach ($non_friday_days as $dow) {
                $final_hours[$dow] += $correction;
            }
        }
    }
    
    echo "\n  After rebalance:\n";
    foreach ($final_hours as $dow => $h) {
        $day_name = ['','Mon','Tue','Wed','Thu','Fri'][$dow];
        echo "    $day_name: " . round($h, 2) . "h\n";
    }
    echo "    Total: " . round(array_sum($final_hours), 2) . "h\n";
    
    // ENFORCE: Friday maximum 6 hours (exit at 14:00 in winter, no lunch break)
    if (in_array(5, $remaining_days) && isset($final_hours[5]) && $final_hours[5] > 6.0) {
        echo "\n⚠️  SAFETY CHECK: Friday exceeds 6h, capping...\n";
        $excess = $final_hours[5] - 6.0;
        $final_hours[5] = 6.0;
        
        $non_friday_days = array_filter($remaining_days, fn($d) => $d !== 5);
        if (!empty($non_friday_days)) {
            $excess_per_day = $excess / count($non_friday_days);
            foreach ($non_friday_days as $dow) {
                $final_hours[$dow] += $excess_per_day;
            }
        }
    }
    
    // === RESULT ===
    echo "\n" . str_repeat("=" , 79) . "\n";
    echo "✅ FINAL DISTRIBUTION:\n";
    echo str_repeat("=", 79) . "\n";
    foreach ($final_hours as $dow => $h) {
        $day_names = ['','Monday','Tuesday','Wednesday','Thursday','Friday'];
        $day_date = date('2026-01-' . (5 + $dow - 1), strtotime('2026-01-05'));
        
        $hours = round($h, 2);
        $check = '';
        if ($dow === 5) {
            $check = ($hours <= 6.0) ? '✅' : '❌';
            echo "  $day_names[$dow] ($day_date): $hours h $check (max 6h, max 14:00 exit)\n";
        } else {
            echo "  $day_names[$dow] ($day_date): $hours h\n";
        }
    }
    
    echo "\nTotal: " . round(array_sum($final_hours), 2) . "h\n";
    echo "Target: " . round($target_weekly_hours, 2) . "h\n";
    echo "Match: " . (abs(array_sum($final_hours) - $target_weekly_hours) < 0.01 ? '✅' : '❌') . "\n";
    
    // Calculate actual times
    echo "\n" . str_repeat("=", 79) . "\n";
    echo "SUGGESTED TIMES:\n";
    echo str_repeat("=", 79) . "\n";
    
    foreach ($final_hours as $dow => $h) {
        $day_names = ['','Monday','Tuesday','Wednesday','Thursday','Friday'];
        $day_date = date('2026-01-' . (5 + $dow - 1), strtotime('2026-01-05'));
        
        // Simple calculation (ignoring lunch for now)
        $hours = round($h, 2);
        
        if ($dow === 5) {
            // Friday: 07:42 start (from historical pattern)
            $start = '07:42';
            $start_min = 7 * 60 + 42;
            $end_min = $start_min + ($hours * 60);
            
            // Cap at 14:00
            $max_exit_min = 14 * 60;  // 840 minutes
            if ($end_min > $max_exit_min) {
                $end_min = $max_exit_min;
                echo "  Friday: ❌ Would exceed 14:00, capping!\n";
            }
            
            $end = sprintf('%02d:%02d', intval($end_min / 60), $end_min % 60);
            echo "  $day_names[$dow] ($day_date): $start - $end ($hours h) ✅\n";
        } else {
            $start = '08:00';
            $start_min = 8 * 60;
            $end_min = $start_min + ($hours * 60);
            $end = sprintf('%02d:%02d', intval($end_min / 60), $end_min % 60);
            echo "  $day_names[$dow] ($day_date): $start - $end ($hours h)\n";
        }
    }
}

echo "\n" . str_repeat("=", 79) . "\n";
