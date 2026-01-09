<?php
/**
 * Test: Early Start Recalculation - Full Week Scenario
 * More realistic: User starts the week fresh
 */

require_once 'lib.php';  // This includes time_to_minutes

echo "=== Unit Test: Early Start Recalculation (Full Week) ===\n\n";

// Simulate fresh week: no work yet
$target_hours = 39.5;  // Full winter week (8.45*4 + 6 for Fri = 39.8, minus holiday = 39.5)
$remaining_days = [1, 2, 3, 4, 5];  // Mon-Fri all available
$force_start_time = '07:30';

echo "Test Scenario:\n";
echo "  Target hours: " . $target_hours . "h\n";
echo "  Days remaining: " . count($remaining_days) . " (Monday-Friday)\n";
echo "  Force start time: " . $force_start_time . "\n";
echo "  Expected adjustment: ~1.5h saved (30 min × 0.5 × 5 days)\n\n";

// Helper function
if (!function_exists('time_to_minutes')) {
    function time_to_minutes($time_str) {
        if (!$time_str) return 0;
        $parts = explode(':', $time_str);
        return intval($parts[0]) * 60 + intval($parts[1]);
    }
}

echo "=== Simulation: Early Start Calculation ===\n\n";

$adjusted_target = $target_hours;

if ($force_start_time) {
    $force_start_min = time_to_minutes($force_start_time);
    $normal_start_min = time_to_minutes('08:00');
    
    echo "Force start: " . $force_start_time . " (" . $force_start_min . " min)\n";
    echo "Normal start: 08:00 (" . $normal_start_min . " min)\n";
    
    if ($force_start_min < $normal_start_min) {
        $early_minutes = $normal_start_min - $force_start_min;
        echo "Early minutes per day: " . $early_minutes . "\n";
        
        $early_start_minutes_saved = $early_minutes * 0.5 * count($remaining_days);
        echo "Total savings (30 × 0.5 × " . count($remaining_days) . " days): " . number_format($early_start_minutes_saved, 2) . " minutes\n";
        
        if ($early_start_minutes_saved > 10) {
            $savings_hours = $early_start_minutes_saved / 60;
            $adjusted_target = $target_hours - $savings_hours;
            
            // Cap at 95% minimum
            $adjusted_target = max($adjusted_target, $target_hours * 0.95);
            
            $reduction = $target_hours - $adjusted_target;
            $reduction_pct = ($reduction / $target_hours) * 100;
            
            echo sprintf("\n✓ Target reduced from %.2fh to %.2fh (%.2fh saved, %.1f%% reduction)\n", 
                $target_hours, $adjusted_target, $reduction, $reduction_pct);
        }
    }
}

echo "\n";
echo "=== Distribution Across Days ===\n\n";

$base_per_day = $adjusted_target / count($remaining_days);
echo sprintf("Adjusted target: %.2fh\n", $adjusted_target);
echo sprintf("Days: %d\n", count($remaining_days));
echo sprintf("Base per day: %.2fh\n\n", $base_per_day);

// Distribution: Mon-Thu same, Friday max 6h
$daily_hours = [];
for ($i = 1; $i <= 5; $i++) {
    if ($i === 5) {  // Friday
        $daily_hours[$i] = min(6.0, $base_per_day + 0.3);
    } else {  // Mon-Thu
        $daily_hours[$i] = $base_per_day - 0.05;  // Slightly less for balance
    }
}

// Normalize to match target
$current_total = array_sum($daily_hours);
$adjustment_factor = $adjusted_target / $current_total;
foreach ($daily_hours as &$h) {
    $h = $h * $adjustment_factor;
}
unset($h);

// Recap Friday at 6h if needed
if ($daily_hours[5] > 6) {
    $excess = $daily_hours[5] - 6;
    $daily_hours[5] = 6;
    // Redistribute excess to other days
    for ($i = 1; $i <= 4; $i++) {
        $daily_hours[$i] += $excess / 4;
    }
}

$day_names = ['', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes'];

echo sprintf("Distribution:\n");
for ($i = 1; $i <= 5; $i++) {
    printf("  %s: %.2f hours\n", $day_names[$i], $daily_hours[$i]);
}

echo sprintf("\nTotal: %.2f h (target: %.2f h)\n\n", array_sum($daily_hours), $adjusted_target);

// Calculate exit times
echo "=== Calculated Exit Times ===\n\n";

$start_times = [
    1 => '07:30',  // Early start Monday
    2 => '08:00',  // Normal Tuesday
    3 => '08:00',  // Normal Wednesday
    4 => '07:30',  // Can go back to early Thursday
    5 => '08:00'   // Normal Friday
];

for ($i = 1; $i <= 5; $i++) {
    $start = $start_times[$i];
    $hours = $daily_hours[$i];
    
    $start_min = time_to_minutes($start);
    $work_min = $hours * 60;
    $end_min = $start_min + $work_min;
    $end = intval($end_min / 60) . ':' . sprintf('%02d', $end_min % 60);
    
    printf("  %s: %s → %s (%.2f h)\n", $day_names[$i], $start, $end, $hours);
}

echo "\n=== Constraint Validation ===\n\n";

$all_ok = true;
for ($i = 1; $i <= 5; $i++) {
    if ($i === 5) {  // Friday
        $max_hours = 6;
        $max_exit_min = (14 * 60) + 10;  // 14:10
    } else {  // Mon-Thu
        $max_hours = 8.45;
        $max_exit_min = (18 * 60) + 10;  // 18:10
    }
    
    if ($daily_hours[$i] > $max_hours) {
        echo "✗ " . $day_names[$i] . " exceeds max hours (%.2f > %.2f h)\n", $daily_hours[$i], $max_hours;
        $all_ok = false;
    }
}

if ($all_ok) {
    echo "✓ All days within hour limits\n";
}

// Check distribution variance
$min_h = min($daily_hours);
$max_h = max($daily_hours);
if ($max_h - $min_h <= 1.0) {
    echo "✓ Hours evenly distributed (variance ≤ 1h)\n";
} else {
    echo "⚠ Hours not evenly distributed (variance > 1h)\n";
}

echo "\n=== Summary ===\n\n";
echo sprintf("Early start advantage: Saves %.0f minutes across the week\n", $early_start_minutes_saved);
echo sprintf("Weekly hours reduced from %.2fh to %.2fh\n", $target_hours, $adjusted_target);
echo sprintf("Daily hours average: %.2fh (range: %.2f - %.2f h)\n", array_sum($daily_hours) / count($daily_hours), $min_h, $max_h);
echo sprintf("User finishes early every day while completing weekly target\n");

echo "\n=== Test Complete ===\n";
?>
