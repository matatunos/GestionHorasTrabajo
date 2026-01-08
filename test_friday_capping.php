<?php
// Test the Friday capping logic

$target_hours = 28.45;
$remaining_days = [3, 4, 5];  // Thu, Fri
$base_per_day = 28.45 / 2;  // Distributed over 2 days

$final_hours = [];
foreach ($remaining_days as $dow) {
    if ($dow === 5) {
        $final_hours[$dow] = min(6.0, $base_per_day);
    } else {
        $final_hours[$dow] = $base_per_day;
    }
}

echo "After initial assignment:\n";
foreach ($final_hours as $dow => $h) {
    echo "  Day $dow: " . round($h, 2) . "\n";
}

$total_adjusted = array_sum($final_hours);
$correction = ($target_hours - $total_adjusted) / count($remaining_days);
echo "\nTotal: " . round($total_adjusted, 2) . ", Target: $target_hours, Correction: " . round($correction, 2) . "\n\n";

foreach ($remaining_days as $dow) {
    $final_hours[$dow] += $correction;
}

echo "After rebalance (BEFORE capping):\n";
foreach ($final_hours as $dow => $h) {
    echo "  Day $dow: " . round($h, 2) . "\n";
}

// Apply Friday cap
if (in_array(5, $remaining_days) && $final_hours[5] > 6.0) {
    echo "\n>>> Friday exceeds 6 hours, capping...\n";
    $excess = $final_hours[5] - 6.0;
    $final_hours[5] = 6.0;
    $non_friday_days = array_filter($remaining_days, fn($d) => $d !== 5);
    if (!empty($non_friday_days)) {
        $excess_per_day = $excess / count($non_friday_days);
        echo ">>> Redistributing " . round($excess, 2) . "h excess (" . round($excess_per_day, 2) . " per day)\n";
        foreach ($non_friday_days as $dow) {
            $final_hours[$dow] += $excess_per_day;
        }
    }
}

echo "\nAfter Friday capping:\n";
foreach ($final_hours as $dow => $h) {
    echo "  Day $dow: " . round($h, 2) . "\n";
}
echo "\nTotal: " . round(array_sum($final_hours), 2) . "\n";
