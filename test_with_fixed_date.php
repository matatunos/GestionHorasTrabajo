<?php
/**
 * Mockea la función date() para permitir testing con fechas específicas
 */

// Mock for date function
$MOCK_DATE = '2026-01-07 14:30:00';
$MOCK_TIMESTAMP = strtotime($MOCK_DATE);

// Simple namespace-based date override won't work in global scope
// Instead, use a workaround by calling the actual calculation manually

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib.php';

$pdo = get_pdo();
$user_id = 1;

// Fixed test date: 2026-01-07 (Wednesday, 2:30 PM)
$today = '2026-01-07';
$current_year = 2026;
$current_week_start = '2026-01-05';  // Monday
$current_week_end = '2026-01-11';    // Sunday

echo "Test Configuration:\n";
echo "  Today: $today (Wednesday)\n";
echo "  Week: $current_week_start to $current_week_end\n";
echo "  Year: $current_year\n\n";

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

echo "Holidays this week: " . (count($holidays_this_week) > 0 ? implode(', ', array_keys($holidays_this_week)) : 'None') . "\n\n";

// Get year config
$year_config = get_year_config($current_year, $user_id);

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

// Get worked hours
$stmt = $pdo->prepare("SELECT * FROM entries WHERE user_id = ? AND date >= ? AND date <= ? ORDER BY date ASC");
$stmt->execute([$user_id, $current_week_start, $today]);
$week_entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

$worked_hours_this_week = 0;
$week_data = [];
foreach ($week_entries as $entry) {
    if (empty($entry['start']) || empty($entry['end'])) continue;
    $entry_calc = compute_day($entry, $year_config);
    if ($entry_calc['worked_minutes_for_display'] !== null) {
        $worked_hours_this_week += $entry_calc['worked_minutes_for_display'] / 60;
    }
}

// Calculate remaining days - FIXED TO USE KNOWN TODAY DATE
$today_dow = (int)date('N', strtotime($today));  // 3 = Wednesday
$remaining_days = [];
$remaining_hours = max(0, $target_weekly_hours - $worked_hours_this_week);

echo "Calculated values:\n";
echo "  Today DOW: $today_dow (1=Mon, 5=Fri)\n";
echo "  Target: " . round($target_weekly_hours, 2) . "h\n";
echo "  Worked: " . round($worked_hours_this_week, 2) . "h\n";
echo "  Remaining: " . round($remaining_hours, 2) . "h\n\n";

// Build remaining days
for ($i = $today_dow; $i <= 5; $i++) {
    $check_date = date('Y-m-d', strtotime($current_week_start . " +" . ($i - 1) . " days"));
    
    echo "Checking day $i ($check_date): ";
    
    // Skip if it's a holiday
    if (isset($holidays_this_week[$check_date])) {
        echo "HOLIDAY - skipped\n";
        continue;
    }
    
    // Include if after today
    if ($check_date > $today) {
        echo "INCLUDED (after today)\n";
        $remaining_days[] = $i;
    } else if ($check_date === $today) {
        echo "TODAY - included (before 17:00)\n";
        $remaining_days[] = $i;
    } else {
        echo "PASSED - skipped\n";
    }
}

echo "\nRemaining days: " . (count($remaining_days) > 0 ? implode(', ', $remaining_days) : 'NONE') . "\n";

// Run the actual distribution logic
if (!empty($remaining_days)) {
    echo "\n" . str_repeat("=", 80) . "\n";
    echo "DISTRIBUTION LOGIC (with Friday capping)\n";
    echo str_repeat("=", 80) . "\n\n";
    
    // Calculate final hours per day with adjustments
    $final_hours = [];
    $total_adjusted = 0;
    $base_per_day = $remaining_hours / count($remaining_days);
    
    echo "Base per day: " . round($base_per_day, 2) . "h\n\n";
    
    foreach ($remaining_days as $dow) {
        $suggested = $base_per_day;
        
        // Ensure minimum 5.5 hours but respect Friday constraint
        if ($dow === 5) {
            // Friday: maximum 6 hours (exit at 14:00)
            $min_hours = 5.0;
            $max_hours = 6.0;
            $final_hours[$dow] = max($min_hours, min($max_hours, $suggested));
        } else {
            $min_hours = max(5.5, $suggested - 1.0);
            $final_hours[$dow] = max($min_hours, $suggested);
        }
        $total_adjusted += $final_hours[$dow];
    }
    
    echo "Step 1 - Initial assignment:\n";
    foreach ($final_hours as $dow => $h) {
        $names = ['','Mon','Tue','Wed','Thu','Fri'];
        echo "  " . $names[$dow] . ": " . round($h, 2) . "h\n";
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
            
            echo "Step 2 - Rebalance (only non-Friday days):\n";
            echo "  Friday fixed at: " . round($friday_hours, 2) . "h\n";
            echo "  Correction: " . round($correction, 2) . "h per day\n\n";
            
            foreach ($non_friday_days as $dow) {
                $final_hours[$dow] += $correction;
            }
        }
    }
    
    echo "Step 2 - After rebalance:\n";
    foreach ($final_hours as $dow => $h) {
        $names = ['','Mon','Tue','Wed','Thu','Fri'];
        echo "  " . $names[$dow] . ": " . round($h, 2) . "h\n";
    }
    echo "  Total: " . round(array_sum($final_hours), 2) . "h\n\n";
    
    // Final check
    if (in_array(5, $remaining_days) && isset($final_hours[5]) && $final_hours[5] > 6.0) {
        echo "⚠️ WARNING: Friday capping needed!\n";
    }
    
    echo str_repeat("=", 80) . "\n";
    echo "✅ FINAL RESULT\n";
    echo str_repeat("=", 80) . "\n\n";
    
    foreach ($final_hours as $dow => $h) {
        $names = ['','Monday','Tuesday','Wednesday','Thursday','Friday'];
        $day_date = date('Y-m-d', strtotime($current_week_start . " +" . ($dow - 1) . " days"));
        
        $check = '';
        if ($dow === 5 && $h <= 6.0) {
            $check = ' ✅';
        }
        echo "  " . $day_date . " (" . $names[$dow] . "): " . round($h, 2) . "h$check\n";
    }
    
    echo "\n  Total: " . round(array_sum($final_hours), 2) . "h\n";
    echo "  Target: " . round($target_weekly_hours, 2) . "h\n";
    echo "  Match: " . (abs(array_sum($final_hours) - $target_weekly_hours) < 0.01 ? '✅' : '❌') . "\n";
} else {
    echo "❌ No remaining days found!\n";
}
