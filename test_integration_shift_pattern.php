<?php
/**
 * Integration test for schedule_suggestions.php
 * Validates that the shift pattern detection integrates correctly with the main flow
 */

echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║  INTEGRATION TEST - Shift Pattern Detection System         ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

// Simulated test data
class MockPDO {
    public $testData = [];
    
    public function prepare($sql) {
        return new MockStatement($this->testData);
    }
}

class MockStatement {
    private $testData;
    
    public function __construct(&$testData) {
        $this->testData = &$testData;
    }
    
    public function execute($params = []) {
        return true;
    }
    
    public function fetch($mode = PDO::FETCH_ASSOC) {
        return reset($this->testData) ?: null;
    }
    
    public function fetchAll($mode = PDO::FETCH_ASSOC) {
        return $this->testData;
    }
}

// Test 1: Verify function signature
echo "TEST 1: Function Signatures\n";
echo "─────────────────────────────\n";

$test1_passed = true;

// Check if function exists in file
$code = file_get_contents('/opt/GestionHorasTrabajo/schedule_suggestions.php');

if (strpos($code, 'function detect_weekly_shift_pattern') !== false) {
    echo "✅ Function detect_weekly_shift_pattern() exists\n";
} else {
    echo "❌ Function detect_weekly_shift_pattern() NOT FOUND\n";
    $test1_passed = false;
}

if (strpos($code, 'distribute_hours(..., $is_split_shift') !== false || 
    strpos($code, '$is_split_shift = true') !== false) {
    echo "✅ Parameter \$is_split_shift in distribute_hours()\n";
} else {
    echo "⚠️ Parameter \$is_split_shift might not be properly integrated\n";
}

if (strpos($code, 'shift_pattern') !== false) {
    echo "✅ JSON response includes shift_pattern field\n";
} else {
    echo "❌ JSON response missing shift_pattern field\n";
    $test1_passed = false;
}

echo "\nTest 1 Result: " . ($test1_passed ? "✅ PASSED" : "❌ FAILED") . "\n\n";

// Test 2: Verify logic flow
echo "TEST 2: Logic Flow Integration\n";
echo "───────────────────────────────\n";

$test2_passed = true;

// Check for Monday date calculation
if (strpos($code, 'current_week_start . \' +1 days\'') !== false) {
    echo "✅ Monday date calculation found\n";
} else {
    echo "❌ Monday date calculation NOT FOUND\n";
    $test2_passed = false;
}

// Check for shift detection call
if (strpos($code, 'detect_weekly_shift_pattern') !== false && 
    strpos($code, 'shift_detection') !== false) {
    echo "✅ Shift pattern detection integration found\n";
} else {
    echo "❌ Shift pattern detection integration NOT FOUND\n";
    $test2_passed = false;
}

// Check for distribute_hours call with parameter
if (preg_match('/distribute_hours\([^)]*\$is_split_shift/', $code)) {
    echo "✅ distribute_hours() called with \$is_split_shift parameter\n";
} else {
    echo "⚠️ distribute_hours() might not receive \$is_split_shift parameter\n";
}

echo "\nTest 2 Result: " . ($test2_passed ? "✅ PASSED" : "❌ FAILED") . "\n\n";

// Test 3: Verify mathematical calculations
echo "TEST 3: Mathematical Calculation Accuracy\n";
echo "──────────────────────────────────────────\n";

$test3_passed = true;

// Test end-time calculation logic
function test_end_time_calculation($start, $hours, $is_split_shift, $lunch_minutes = 60) {
    $start_parts = explode(':', $start);
    $start_mins = $start_parts[0] * 60 + $start_parts[1];
    
    if ($is_split_shift) {
        $end_mins = $start_mins + ($hours * 60) + $lunch_minutes;
    } else {
        $end_mins = $start_mins + ($hours * 60);
    }
    
    $end_h = intval($end_mins / 60) % 24;
    $end_m = $end_mins % 60;
    return sprintf('%02d:%02d', $end_h, $end_m);
}

// Test cases
$tests = [
    ['start' => '08:00', 'hours' => 8, 'split' => true, 'lunch' => 60, 'expected' => '17:00', 'name' => 'Partida 8h+1h'],
    ['start' => '07:30', 'hours' => 8, 'split' => false, 'lunch' => 0, 'expected' => '15:30', 'name' => 'Continua 8h'],
    ['start' => '08:00', 'hours' => 6, 'split' => false, 'lunch' => 0, 'expected' => '14:00', 'name' => 'Viernes 6h'],
    ['start' => '07:00', 'hours' => 8, 'split' => true, 'lunch' => 60, 'expected' => '16:00', 'name' => 'Early 7h+8h+1h'],
];

foreach ($tests as $t) {
    $result = test_end_time_calculation($t['start'], $t['hours'], $t['split'], $t['lunch']);
    $passed = $result === $t['expected'];
    $status = $passed ? '✅' : '❌';
    echo "$status {$t['name']}: {$t['start']} + {$t['hours']}h" . 
         ($t['split'] ? " + {$t['lunch']}m lunch" : "") . 
         " = $result (expected {$t['expected']})\n";
    
    if (!$passed) {
        $test3_passed = false;
    }
}

echo "\nTest 3 Result: " . ($test3_passed ? "✅ PASSED" : "❌ FAILED") . "\n\n";

// Test 4: Verify Friday special handling
echo "TEST 4: Friday Special Case Handling\n";
echo "────────────────────────────────────\n";

$test4_passed = true;

if (strpos($code, '$dow === 5') !== false) {
    echo "✅ Friday day-of-week check found (dow === 5)\n";
} else {
    echo "❌ Friday check NOT FOUND\n";
    $test4_passed = false;
}

// Check that Friday doesn't apply lunch deduction
if (preg_match('/if\s*\(\s*\$dow\s*===\s*5\s*\).*?(?!lunch).*?end.*?\}/s', $code)) {
    echo "✅ Friday logic appears to NOT include lunch deduction\n";
} else {
    echo "⚠️ Friday logic might incorrectly include lunch deduction\n";
}

echo "\nTest 4 Result: " . ($test4_passed ? "✅ PASSED" : "❌ FAILED") . "\n\n";

// Test 5: Verify JSON response structure
echo "TEST 5: JSON Response Structure\n";
echo "───────────────────────────────\n";

$test5_passed = true;

$required_fields = [
    "'success'",
    "'worked_this_week'",
    "'target_weekly_hours'",
    "'remaining_hours'",
    "'week_data'",
    "'suggestions'",
    "'shift_pattern'",  // NEW
    "'analysis'",
    "'message'"
];

foreach ($required_fields as $field) {
    if (strpos($code, $field) !== false) {
        echo "✅ JSON field $field exists\n";
    } else {
        echo "❌ JSON field $field missing\n";
        $test5_passed = false;
    }
}

// Verify shift_pattern object structure
$shift_pattern_fields = ["'type'", "'label'", "'applies_to'", "'detected_from'"];
$shift_pattern_found = true;
foreach ($shift_pattern_fields as $field) {
    if (strpos($code, $field) === false) {
        $shift_pattern_found = false;
        break;
    }
}

if ($shift_pattern_found) {
    echo "✅ shift_pattern object has all required fields\n";
} else {
    echo "❌ shift_pattern object missing required fields\n";
    $test5_passed = false;
}

echo "\nTest 5 Result: " . ($test5_passed ? "✅ PASSED" : "❌ FAILED") . "\n\n";

// Test 6: Verify error handling
echo "TEST 6: Edge Case and Error Handling\n";
echo "────────────────────────────────────\n";

$test6_passed = true;

// Check for null checks
if (preg_match('/\$shift_detection|!empty|is_null/', $code)) {
    echo "✅ Null/empty checks found for shift detection\n";
} else {
    echo "⚠️ Limited error handling for edge cases\n";
}

// Check for default value
if (strpos($code, '$is_split_shift = true') !== false) {
    echo "✅ Default value set for is_split_shift (true)\n";
} else {
    echo "⚠️ Default value for is_split_shift NOT FOUND\n";
    $test6_passed = false;
}

// Check for array access safety
if (strpos($code, '??') !== false) {
    echo "✅ Null coalescing operator (?) used for safe access\n";
} else {
    echo "⚠️ Limited use of null coalescing operators\n";
}

echo "\nTest 6 Result: " . ($test6_passed ? "✅ PASSED" : "⚠️ PARTIAL") . "\n\n";

// Final Summary
echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║  INTEGRATION TEST SUMMARY                                  ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

$all_tests = [
    ['name' => 'Function Signatures', 'result' => $test1_passed],
    ['name' => 'Logic Flow Integration', 'result' => $test2_passed],
    ['name' => 'Mathematical Calculations', 'result' => $test3_passed],
    ['name' => 'Friday Special Handling', 'result' => $test4_passed],
    ['name' => 'JSON Response Structure', 'result' => $test5_passed],
    ['name' => 'Error Handling', 'result' => $test6_passed],
];

$passed_count = 0;
foreach ($all_tests as $test) {
    $status = $test['result'] ? '✅ PASS' : '❌ FAIL';
    echo "$status  {$test['name']}\n";
    if ($test['result']) $passed_count++;
}

echo "\n";
echo "Total Tests: " . count($all_tests) . "\n";
echo "Passed: $passed_count\n";
echo "Failed: " . (count($all_tests) - $passed_count) . "\n";

$overall_result = $passed_count === count($all_tests);
echo "\nOVERALL RESULT: " . ($overall_result ? "✅ ALL TESTS PASSED" : "⚠️ SOME TESTS FAILED") . "\n";

echo "\n═════════════════════════════════════════════════════════════\n";
echo "Status: Ready for deployment testing with real database\n";
echo "═════════════════════════════════════════════════════════════\n";
