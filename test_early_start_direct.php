<?php
/**
 * Direct Test: Early Start Time Recalculation
 * Tests by directly calling schedule_suggestions API locally
 */

// Set up test environment
chdir('/opt/GestionHorasTrabajo');
$_GET['user_id'] = 1;
$_GET['date'] = '2026-01-08';  // Thursday

require_once 'db.php';
require_once 'lib.php';

echo "=== Early Start Recalculation Test (Normal Schedule) ===\n\n";

// Capture output from schedule_suggestions.php
ob_start();
include 'schedule_suggestions.php';
$normal_output = ob_get_clean();

$data_normal = json_decode($normal_output, true);
if ($data_normal && $data_normal['success']) {
    echo "✓ Normal schedule loaded\n";
    echo sprintf("  Worked this week: %.2f h\n", $data_normal['worked_this_week']);
    echo sprintf("  Target weekly: %.2f h\n", $data_normal['target_weekly_hours']);
    echo sprintf("  Adjusted target: %.2f h\n", $data_normal['adjusted_target_hours'] ?? 'N/A');
    echo sprintf("  Remaining: %.2f h\n", $data_normal['remaining_hours']);
    echo sprintf("  Days remaining: %d\n", $data_normal['analysis']['days_remaining']);
    
    if (!empty($data_normal['suggestions'])) {
        echo "\n  Suggestions:\n";
        foreach ($data_normal['suggestions'] as $s) {
            printf("    %s (%s): %s → %s (%s)\n", 
                $s['date'], $s['day_name'], $s['start'], $s['end'], $s['hours']);
        }
    }
} else {
    echo "✗ Failed to load normal schedule\n";
    if ($data_normal) {
        print_r($data_normal);
    } else {
        echo "Response: " . substr($normal_output, 0, 200) . "\n";
    }
}

echo "\n\n";
echo "=== Early Start Recalculation Test (07:30 Start) ===\n\n";

// Reset and test with force_start_time
$_GET['force_start_time'] = '07:30';
$_GET['date'] = '2026-01-08';
$_GET['user_id'] = 1;

ob_start();
include 'schedule_suggestions.php';
$early_output = ob_get_clean();

$data_early = json_decode($early_output, true);
if ($data_early && $data_early['success']) {
    echo "✓ Early start schedule loaded\n";
    echo sprintf("  Worked this week: %.2f h\n", $data_early['worked_this_week']);
    echo sprintf("  Target weekly (normal): %.2f h\n", $data_early['target_weekly_hours']);
    echo sprintf("  Target weekly (adjusted): %.2f h\n", $data_early['adjusted_target_hours'] ?? 'N/A');
    echo sprintf("  Remaining: %.2f h\n", $data_early['remaining_hours']);
    
    if (!empty($data_early['analysis']['early_start_adjustment'])) {
        echo sprintf("  Early start adjustment: %s\n", $data_early['analysis']['early_start_adjustment']);
    }
    
    if (!empty($data_early['suggestions'])) {
        echo "\n  Suggestions with early start:\n";
        foreach ($data_early['suggestions'] as $s) {
            printf("    %s (%s): %s → %s (%s)\n", 
                $s['date'], $s['day_name'], $s['start'], $s['end'], $s['hours']);
        }
    }
} else {
    echo "✗ Failed to load early start schedule\n";
    if ($data_early) {
        print_r($data_early);
    } else {
        echo "Response: " . substr($early_output, 0, 200) . "\n";
    }
}

echo "\n\n";

// Comparison
if ($data_normal && $data_normal['success'] && $data_early && $data_early['success']) {
    echo "=== Comparison: Normal vs Early Start ===\n\n";
    
    $target_normal = $data_normal['target_weekly_hours'];
    $target_adjusted = $data_early['adjusted_target_hours'] ?? $data_early['target_weekly_hours'];
    
    $target_reduction = $target_normal - $target_adjusted;
    $target_reduction_pct = ($target_reduction / $target_normal) * 100;
    
    echo sprintf("Target reduction: %.2f h (%.1f%%)\n", $target_reduction, $target_reduction_pct);
    
    if (!empty($data_normal['suggestions']) && !empty($data_early['suggestions'])) {
        echo "\nHourly changes per day:\n";
        for ($i = 0; $i < min(count($data_normal['suggestions']), count($data_early['suggestions'])); $i++) {
            $n = $data_normal['suggestions'][$i];
            $e = $data_early['suggestions'][$i];
            
            $n_hours = floatval(str_replace('h', '', $n['hours']));
            $e_hours = floatval(str_replace('h', '', $e['hours']));
            
            $diff = $n_hours - $e_hours;
            $diff_pct = ($diff / $n_hours) * 100;
            
            printf("  %s (%s): %.2f → %.2f h (saved %.2f h, %.1f%%)\n",
                $n['date'],
                $n['day_name'],
                $n_hours,
                $e_hours,
                $diff,
                $diff_pct);
        }
    }
    
    echo "\n=== Validation Checks ===\n\n";
    
    // Check 1: Target reduction is expected
    if ($target_reduction_pct >= 2 && $target_reduction_pct <= 4) {
        echo "✓ PASS: Target reduction is within expected range (2-4%)\n";
        echo sprintf("  Actual: %.1f%%\n", $target_reduction_pct);
    } else {
        echo "✗ FAIL: Target reduction out of range (expected 2-4%)\n";
        echo sprintf("  Actual: %.1f%%\n", $target_reduction_pct);
    }
    
    // Check 2: Friday still respects constraint
    if (!empty($data_early['suggestions'])) {
        $friday_check = null;
        foreach ($data_early['suggestions'] as $s) {
            if ($s['day_of_week'] === 5) {  // Friday
                $friday_hours = floatval(str_replace('h', '', $s['hours']));
                $exit_time = $s['end'];
                $exit_mins = (intval(substr($exit_time, 0, 2)) * 60) + intval(substr($exit_time, 3, 2));
                $max_friday_mins = (14 * 60) + 10;  // 14:10
                
                if ($exit_mins <= $max_friday_mins && $friday_hours <= 6.1) {
                    echo "✓ PASS: Friday constraints respected\n";
                    echo sprintf("  Exit: %s (≤14:10), Hours: %.2f h (≤6h)\n", $exit_time, $friday_hours);
                } else {
                    echo "✗ FAIL: Friday constraints violated\n";
                    if ($exit_mins > $max_friday_mins) {
                        echo sprintf("  Exit too late: %s (max 14:10)\n", $exit_time);
                    }
                    if ($friday_hours > 6.1) {
                        echo sprintf("  Hours too many: %.2f h (max 6h)\n", $friday_hours);
                    }
                }
                $friday_check = true;
                break;
            }
        }
        if (!$friday_check) {
            echo "ℹ INFO: No Friday work in remaining days\n";
        }
    }
    
    // Check 3: All days distributed evenly (within ~0.5h)
    if (!empty($data_early['suggestions'])) {
        $hours_list = [];
        foreach ($data_early['suggestions'] as $s) {
            $hours_list[] = floatval(str_replace('h', '', $s['hours']));
        }
        
        if (count($hours_list) > 1) {
            $min_hours = min($hours_list);
            $max_hours = max($hours_list);
            $diff = $max_hours - $min_hours;
            
            if ($diff <= 0.5) {
                echo "✓ PASS: Hours evenly distributed (max difference ≤0.5h)\n";
                echo sprintf("  Range: %.2f - %.2f h (diff: %.2f h)\n", $min_hours, $max_hours, $diff);
            } else {
                echo "✗ FAIL: Hours not evenly distributed\n";
                echo sprintf("  Range: %.2f - %.2f h (diff: %.2f h, max allowed: 0.5h)\n", $min_hours, $max_hours, $diff);
            }
        }
    }
}

echo "\n=== Test Complete ===\n";
?>
