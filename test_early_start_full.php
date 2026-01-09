<?php
/**
 * Test: Early Start Time Recalculation
 * 
 * Validates that forcing an early start (7:30) recalculates:
 * 1. Target hours reduced by ~3%
 * 2. Exit times calculated earlier
 * 3. Friday constraint still respected (max 14:10)
 * 4. Lunch breaks still enforced if needed
 */

require_once 'db.php';
require_once 'lib.php';

// Test week: Jan 5-9, 2026 (Monday-Friday)
// Current state: Lun 6.28h worked, Mar FESTIVO, Mié 6.2h worked
// Need: ~28.45h for week (accounting for Reyes)

$test_date = '2026-01-08';  // Thursday
$user_id = 1;  // Default test user

echo "=== Early Start Time Recalculation Test ===\n\n";

// Test 1: Normal schedule (08:00 start)
echo "Test 1: Normal Schedule (08:00 start)\n";
echo str_repeat("-", 60) . "\n";

$curl = curl_init('http://localhost:3000/api/schedule_suggestions.php?user_id=' . $user_id . '&date=' . $test_date);
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
$response_normal = curl_exec($curl);
curl_close($curl);

$data_normal = json_decode($response_normal, true);
if ($data_normal['success']) {
    echo "✓ Normal schedule loaded\n";
    echo sprintf("  Worked this week: %.2f h\n", $data_normal['worked_this_week']);
    echo sprintf("  Target weekly: %.2f h\n", $data_normal['target_weekly_hours']);
    echo sprintf("  Remaining: %.2f h\n", $data_normal['remaining_hours']);
    echo sprintf("  Days remaining: %d\n", $data_normal['analysis']['days_remaining']);
    
    if (!empty($data_normal['suggestions'])) {
        echo "\n  Suggestions:\n";
        foreach ($data_normal['suggestions'] as $s) {
            printf("    %s (%s): %s → %s (%s hours)\n", 
                $s['date'], $s['day_name'], $s['start'], $s['end'], $s['hours']);
        }
    }
} else {
    echo "✗ Failed to load normal schedule\n";
    print_r($data_normal);
}

echo "\n\n";

// Test 2: Early start schedule (07:30)
echo "Test 2: Early Start Schedule (07:30 start)\n";
echo str_repeat("-", 60) . "\n";

$curl = curl_init('http://localhost:3000/api/schedule_suggestions.php?user_id=' . $user_id . '&date=' . $test_date . '&force_start_time=07:30');
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
$response_early = curl_exec($curl);
curl_close($curl);

$data_early = json_decode($response_early, true);
if ($data_early['success']) {
    echo "✓ Early start schedule loaded\n";
    echo sprintf("  Worked this week: %.2f h\n", $data_early['worked_this_week']);
    echo sprintf("  Target weekly (normal): %.2f h\n", $data_early['target_weekly_hours']);
    echo sprintf("  Target weekly (adjusted): %.2f h\n", $data_early['adjusted_target_hours']);
    echo sprintf("  Remaining: %.2f h\n", $data_early['remaining_hours']);
    echo sprintf("  Days remaining: %d\n", $data_early['analysis']['days_remaining']);
    
    if (!empty($data_early['analysis']['early_start_adjustment'])) {
        echo sprintf("  Adjustment: %s\n", $data_early['analysis']['early_start_adjustment']);
    }
    
    if (!empty($data_early['suggestions'])) {
        echo "\n  Suggestions with early start:\n";
        foreach ($data_early['suggestions'] as $s) {
            printf("    %s (%s): %s → %s (%s hours)\n", 
                $s['date'], $s['day_name'], $s['start'], $s['end'], $s['hours']);
        }
    }
} else {
    echo "✗ Failed to load early start schedule\n";
    print_r($data_early);
}

echo "\n\n";

// Comparison
if ($data_normal['success'] && $data_early['success']) {
    echo "Comparison: Normal vs Early Start\n";
    echo str_repeat("-", 60) . "\n";
    
    $target_reduction = $data_normal['target_weekly_hours'] - $data_early['adjusted_target_hours'];
    $target_reduction_pct = ($target_reduction / $data_normal['target_weekly_hours']) * 100;
    
    echo sprintf("Target reduction: %.2f h (%.1f%%)\n", $target_reduction, $target_reduction_pct);
    
    if (!empty($data_normal['suggestions']) && !empty($data_early['suggestions'])) {
        echo "\nHourly changes:\n";
        for ($i = 0; $i < min(count($data_normal['suggestions']), count($data_early['suggestions'])); $i++) {
            $n = $data_normal['suggestions'][$i];
            $e = $data_early['suggestions'][$i];
            
            $normal_mins = time_to_minutes($n['end']) - time_to_minutes($n['start']);
            $early_mins = time_to_minutes($e['end']) - time_to_minutes($e['start']);
            
            // Account for lunch break
            if ($n['has_lunch_break']) {
                $normal_mins -= $n['lunch_duration_minutes'];
            }
            if ($e['has_lunch_break']) {
                $early_mins -= $e['lunch_duration_minutes'];
            }
            
            $time_saved = ($normal_mins - $early_mins) / 60;
            
            printf("  %s: %s (normal) → %s (early) = %.1f h saved\n",
                $n['date'],
                $n['hours'],
                $e['hours'],
                $time_saved);
        }
    }
    
    echo "\nValidation checks:\n";
    
    // Check 1: Target reduction is ~3%
    if ($target_reduction_pct >= 2 && $target_reduction_pct <= 4) {
        echo "✓ Target reduction is within expected range (2-4%)\n";
    } else {
        echo "✗ Target reduction out of range (expected 2-4%, got " . number_format($target_reduction_pct, 1) . "%)\n";
    }
    
    // Check 2: Friday still respects constraint
    if (!empty($data_early['suggestions'])) {
        $friday_found = false;
        foreach ($data_early['suggestions'] as $s) {
            if ($s['day_of_week'] === 5) {  // Friday
                $friday_found = true;
                $exit_time = $s['end'];
                $exit_mins = time_to_minutes($exit_time);
                $max_friday_mins = time_to_minutes('14:10');
                
                if ($exit_mins <= $max_friday_mins) {
                    echo "✓ Friday exit time respects constraint (≤14:10): " . $exit_time . "\n";
                } else {
                    echo "✗ Friday exit time exceeds max (14:10): " . $exit_time . "\n";
                }
                
                // Check work hours <= 6h
                $friday_hours = floatval(str_replace('h', '', $s['hours']));
                if ($friday_hours <= 6.1) {
                    echo "✓ Friday hours within limit (≤6h): " . $s['hours'] . "\n";
                } else {
                    echo "✗ Friday hours exceed limit (6h): " . $s['hours'] . "\n";
                }
            }
        }
        if (!$friday_found) {
            echo "ℹ No Friday suggestions (holiday or already completed)\n";
        }
    }
}

echo "\n=== Test Complete ===\n";

function time_to_minutes($time_str) {
    if (!$time_str) return 0;
    $parts = explode(':', $time_str);
    return intval($parts[0]) * 60 + intval($parts[1]);
}
?>
