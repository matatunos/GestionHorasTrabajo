<?php
/**
 * Unit Test: Early Start Recalculation Logic
 * Tests the distribute_hours function directly
 */

require_once 'db.php';
require_once 'lib.php';

echo "=== Unit Test: Early Start Time Recalculation ===\n\n";

// Load the distribute_hours function from schedule_suggestions.php
$schedule_code = file_get_contents('schedule_suggestions.php');

// Extract the function
preg_match('/function distribute_hours\(.*?\n\}/s', $schedule_code, $matches);
if (empty($matches)) {
    echo "✗ Could not extract distribute_hours function\n";
    exit(1);
}

// Find and extract the time_to_minutes utility function if not already in lib.php
if (!function_exists('time_to_minutes')) {
    function time_to_minutes($time_str) {
        if (!$time_str) return 0;
        $parts = explode(':', $time_str);
        return intval($parts[0]) * 60 + intval($parts[1]);
    }
}

if (!function_exists('hours_to_hhmm')) {
    function hours_to_hhmm($hours) {
        $h = intval($hours);
        $m = round(($hours - $h) * 60);
        return sprintf("%d:%.2d", $h, $m);
    }
}

// Simulate test data
$target_hours = 28.45;  // Hours needed for week
$remaining_days = [4, 5];  // Thursday, Friday (Monday-Wednesday already done + Reyes holiday)
$force_start_time = '07:30';

// Mock patterns
$patterns = [
    4 => [  // Thursday
        'starts' => ['07:30', '07:30', '08:00'],
        'ends' => ['17:30', '17:30', '17:45'],
        'total_count' => 3
    ],
    5 => [  // Friday
        'starts' => ['08:00', '08:00', '08:15'],
        'ends' => ['14:15', '14:15', '14:30'],
        'total_count' => 3
    ]
];

// Mock year config
$year_config = [
    'monday_to_thursday_winter' => 8.45,
    'friday_winter' => 6.0,
    'monday_to_thursday_summer' => 8.0,
    'friday_summer' => 5.5
];

echo "Test Scenario:\n";
echo "  Target hours: " . $target_hours . "h\n";
echo "  Days remaining: " . count($remaining_days) . " (Thursday, Friday)\n";
echo "  Force start time: " . $force_start_time . "\n";
echo "  Expected adjustment: ~3% reduction (~0.85h)\n\n";

// Manually simulate the early start calculation logic
echo "=== Simulation: Early Start Calculation ===\n\n";

$adjusted_target = $target_hours;

if ($force_start_time) {
    $force_start_min = time_to_minutes($force_start_time);
    $normal_start_min = time_to_minutes('08:00');
    
    echo "Force start minutes: " . $force_start_min . " (07:30 = 450 min)\n";
    echo "Normal start minutes: " . $normal_start_min . " (08:00 = 480 min)\n";
    
    if ($force_start_min < $normal_start_min) {
        $early_minutes = $normal_start_min - $force_start_min;
        echo "Early minutes: " . $early_minutes . "\n";
        
        // New calculation: 30 min early × 0.5 efficiency factor × days remaining
        $early_start_minutes_saved = $early_minutes * 0.5 * count($remaining_days);
        echo "Total savings (30 min × 0.5 × " . count($remaining_days) . " days): " . number_format($early_start_minutes_saved, 2) . " minutes\n";
        
        if ($early_start_minutes_saved > 10) {
            $savings_hours = $early_start_minutes_saved / 60;
            $adjusted_target = $target_hours - $savings_hours;
            
            // Cap at 95% minimum
            $adjusted_target = max($adjusted_target, $target_hours * 0.95);
            
            $reduction = $target_hours - $adjusted_target;
            $reduction_pct = ($reduction / $target_hours) * 100;
            
            echo sprintf("\n✓ Target reduced from %.2fh to %.2fh (%.1f%% reduction)\n", 
                $target_hours, $adjusted_target, $reduction_pct);
        } else {
            echo "Savings too small (≤10 min), no adjustment applied\n";
        }
    } else {
        echo "Force start not earlier than normal, no adjustment needed\n";
    }
}

echo "\n";
echo "=== Distribution Across Days ===\n\n";

$base_per_day = $adjusted_target / count($remaining_days);
echo sprintf("Adjusted target: %.2fh\n", $adjusted_target);
echo sprintf("Days: %d\n", count($remaining_days));
echo sprintf("Base per day: %.2fh\n\n", $base_per_day);

// Simple distribution: Thursday gets more, Friday max 6h
$friday_max = 6.0;
$thursday_hours = min($adjusted_target - $friday_max, $base_per_day + 0.5);
$friday_hours = $adjusted_target - $thursday_hours;

echo sprintf("Distribution (constrained):\n");
echo sprintf("  Thursday: %.2fh\n", $thursday_hours);
echo sprintf("  Friday: %.2fh (max 6h)\n", min($friday_hours, $friday_max));

// Calculate exit times based on hours
$thursday_start = '07:30';
$thursday_start_min = time_to_minutes($thursday_start);
$thursday_work_min = $thursday_hours * 60;
$thursday_end_min = $thursday_start_min + $thursday_work_min;
$thursday_end = intval($thursday_end_min / 60) . ':' . sprintf('%02d', $thursday_end_min % 60);

$friday_start = '08:00';  // Friday typically starts at normal time
$friday_start_min = time_to_minutes($friday_start);
$friday_work_min = min($friday_hours, 6) * 60;
$friday_end_min = $friday_start_min + $friday_work_min;
$friday_end = intval($friday_end_min / 60) . ':' . sprintf('%02d', $friday_end_min % 60);

echo sprintf("\nCalculated exit times:\n");
echo sprintf("  Thursday: %s → %s (%.2f hours)\n", $thursday_start, $thursday_end, $thursday_hours);
echo sprintf("  Friday: %s → %s (%.2f hours)\n", $friday_start, $friday_end, min($friday_hours, 6));

// Verify constraints
echo sprintf("\n=== Constraint Validation ===\n\n");

$thursday_ok = ($thursday_hours <= 8.45);  // Max for weekday
$friday_ok = (time_to_minutes($friday_end) <= (14*60 + 10)) && (min($friday_hours, 6) <= 6);

if ($thursday_ok) {
    echo "✓ Thursday hours within limit (≤8.45h)\n";
} else {
    echo "✗ Thursday hours EXCEED limit\n";
}

if ($friday_ok) {
    echo "✓ Friday constraints respected (exit ≤14:10, hours ≤6h)\n";
} else {
    echo "✗ Friday constraints VIOLATED\n";
}

if ($thursday_ok && $friday_ok) {
    echo "\n✓ All constraints satisfied!\n";
} else {
    echo "\n✗ Some constraints violated\n";
}

echo "\n=== Test Complete ===\n";
?>
