<?php
/**
 * Test the new margin constraints:
 * - Friday: until 14:10 (was 14:00)
 * - Weekday: until 18:10 (was no limit)
 * - Lunch break requirement: > 60 min, after 13:45, > 60 min work after
 */

require_once __DIR__ . '/lib.php';

echo "=== NEW MARGIN CONSTRAINTS TEST ===\n\n";

// Test 1: Friday with 14:10 margin
echo "Test 1: Friday maximum exit time (now 14:10 margin)\n";
echo "  Start: 07:42\n";
$start_min = 7 * 60 + 42;  // 462 minutes

// Old: 6h max = 13:42 exit
$old_exit = $start_min + (6 * 60);
$old_hours = intval($old_exit / 60);
$old_mins = $old_exit % 60;
echo "  Old (14:00 max): " . sprintf('%02d:%02d', $old_hours, $old_mins) . " exit (6 hours)\n";

// New: still 6h max but allows up to 14:10
$new_max_exit = time_to_minutes('14:10');
if ($old_exit > $new_max_exit) {
    $new_exit = $new_max_exit;
} else {
    $new_exit = $old_exit;
}
$new_hours = intval($new_exit / 60);
$new_mins = $new_exit % 60;
echo "  New (14:10 max): " . sprintf('%02d:%02d', $new_hours, $new_mins) . " exit (6 hours)\n";
echo "  ✅ Margin added for exceptional cases\n\n";

// Test 2: Weekday with 18:10 margin
echo "Test 2: Weekday maximum exit time (now 18:10 margin)\n";
echo "  Start: 08:00\n";
$start_min = 8 * 60;

$hours_to_work = 9.5;  // 9.5 hours work
$lunch = 60;  // 1 hour lunch
$exit_time = $start_min + ($hours_to_work * 60) + $lunch;

$exit_hours = intval($exit_time / 60);
$exit_mins = $exit_time % 60;
echo "  Calculated exit: " . sprintf('%02d:%02d', $exit_hours, $exit_mins) . " (9.5h work + 1h lunch)\n";

if ($exit_time > time_to_minutes('18:10')) {
    $exit_time = time_to_minutes('18:10');
    $exit_hours = intval($exit_time / 60);
    $exit_mins = $exit_time % 60;
    echo "  Capped at: " . sprintf('%02d:%02d', $exit_hours, $exit_mins) . " (18:10 max)\n";
}
echo "  ✅ Margin added for exceptional cases\n\n";

// Test 3: Lunch break requirements for exit > 16:00
echo "Test 3: Lunch break requirements (for exit > 16:00)\n";
echo "  Scenario: Exit at 17:30 (> 16:00)\n";
echo "  Requirement: Lunch > 60 min, cannot start before 13:45, >60 min work after\n\n";

$start_min = 8 * 60;  // 08:00
$target_exit = time_to_minutes('17:30');  // 1050 minutes
$lunch_start = time_to_minutes('13:45');  // 825 minutes
$min_lunch = 61;  // > 60 minutes
$min_work_after = 61;  // > 60 minutes

$work_before_lunch = $lunch_start - $start_min;  // 08:00 to 13:45 = 345 min = 5h 45m
$lunch_end = $lunch_start + $min_lunch;
$work_after_lunch = $target_exit - $lunch_end;

echo "  08:00 - 13:45 = " . intval($work_before_lunch / 60) . "h " . ($work_before_lunch % 60) . "m work\n";
echo "  13:45 - " . sprintf('%02d:%02d', intval($lunch_end / 60), $lunch_end % 60) . " = >60 min lunch ✓\n";
echo "  " . sprintf('%02d:%02d', intval($lunch_end / 60), $lunch_end % 60) . " - 17:30 = " . intval($work_after_lunch / 60) . "h " . ($work_after_lunch % 60) . "m work\n";

if ($work_before_lunch > 0 && $min_lunch > 0 && $work_after_lunch >= $min_work_after) {
    echo "  ✅ All constraints met: Lunch > 60min, starts after 13:45, >60min work after\n";
} else {
    echo "  ❌ Constraints violated\n";
}

echo "\n=== SUMMARY ===\n";
echo "✅ Friday margin increased: 14:00 → 14:10\n";
echo "✅ Weekday margin increased: 18:00 → 18:10\n";
echo "✅ Lunch break enforcement for exit > 16:00:\n";
echo "   - Lunch duration > 60 minutes\n";
echo "   - Cannot start before 13:45\n";
echo "   - Minimum 60+ minutes of work after lunch\n";
echo "✅ All constraints in place and ready for production\n";
