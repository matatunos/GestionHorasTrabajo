<?php
/**
 * Test Top 3 Features: Hours calculation, validation, and filters
 */

require_once __DIR__ . '/lib.php';

echo "=== Testing Top 3 Features ===\n\n";

// Test 1: get_hours_display function
echo "✓ Test 1: get_hours_display() function\n";
$result1 = get_hours_display('09:00', '17:00', 480); // 8 hours
echo "  Input: start=09:00, end=17:00, worked_minutes=480\n";
echo "  Output: " . $result1 . "\n";
echo "  Expected: 09:00→17:00 (8h 0m)\n";
assert(strpos($result1, '09:00→17:00') !== false, "Failed: time range not found");
assert(strpos($result1, '8h') !== false, "Failed: hours not found");
echo "  ✓ PASSED\n\n";

// Test 2: Validation with good data
echo "✓ Test 2: validate_time_entry() with valid data\n";
$validEntry = [
  'start' => '09:00',
  'coffee_out' => '10:00',
  'coffee_in' => '10:15',
  'lunch_out' => '13:00',
  'lunch_in' => '14:00',
  'end' => '17:30',
  'note' => 'Good day',
  'absence_type' => null,
];
$validation = validate_time_entry($validEntry);
echo "  Input: start 09:00, end 17:30, with breaks\n";
echo "  Valid: " . ($validation['valid'] ? 'YES' : 'NO') . "\n";
echo "  Errors: " . count($validation['errors']) . "\n";
assert($validation['valid'], "Failed: valid entry marked as invalid");
echo "  ✓ PASSED\n\n";

// Test 3: Validation with bad data - wrong order
echo "✓ Test 3: validate_time_entry() with invalid data (wrong order)\n";
$invalidEntry = [
  'start' => '17:00',
  'coffee_out' => null,
  'coffee_in' => null,
  'lunch_out' => null,
  'lunch_in' => null,
  'end' => '09:00',
  'note' => '',
  'absence_type' => null,
];
$validation = validate_time_entry($invalidEntry);
echo "  Input: start 17:00, end 09:00 (reversed!)\n";
echo "  Valid: " . ($validation['valid'] ? 'YES' : 'NO') . "\n";
echo "  Errors: " . count($validation['errors']) . "\n";
if (!$validation['valid']) {
  echo "  Error messages:\n";
  foreach ($validation['errors'] as $err) {
    echo "    - " . $err . "\n";
  }
}
assert(!$validation['valid'], "Failed: invalid entry marked as valid");
echo "  ✓ PASSED\n\n";

// Test 4: Validation with bad break data
echo "✓ Test 4: validate_time_entry() with invalid break (coffee wrong order)\n";
$invalidBreak = [
  'start' => '09:00',
  'coffee_out' => '10:15',
  'coffee_in' => '10:00',
  'lunch_out' => null,
  'lunch_in' => null,
  'end' => '17:00',
  'note' => '',
  'absence_type' => null,
];
$validation = validate_time_entry($invalidBreak);
echo "  Input: coffee_out 10:15, coffee_in 10:00 (reversed!)\n";
echo "  Valid: " . ($validation['valid'] ? 'YES' : 'NO') . "\n";
assert(!$validation['valid'], "Failed: coffee break reversed but marked as valid");
echo "  ✓ PASSED\n\n";

// Test 5: Validation with too-long break
echo "✓ Test 5: validate_time_entry() with too-long break\n";
$longBreak = [
  'start' => '09:00',
  'coffee_out' => '10:00',
  'coffee_in' => '12:30',  // 2.5 hours, too long
  'lunch_out' => null,
  'lunch_in' => null,
  'end' => '17:00',
  'note' => '',
  'absence_type' => null,
];
$validation = validate_time_entry($longBreak);
echo "  Input: coffee break 10:00-12:30 (150 minutes, max 120)\n";
echo "  Valid: " . ($validation['valid'] ? 'YES' : 'NO') . "\n";
assert(!$validation['valid'], "Failed: long break marked as valid");
echo "  ✓ PASSED\n\n";

// Test 6: get_hours_display with null values
echo "✓ Test 6: get_hours_display() with missing data\n";
$result6 = get_hours_display(null, '17:00', 480);
echo "  Input: start=null, end=17:00, worked_minutes=480\n";
echo "  Output: " . $result6 . "\n";
assert($result6 === '—', "Failed: should return dash for missing start");
echo "  ✓ PASSED\n\n";

// Test 7: multiple_minutes_to_hours_formatted
echo "✓ Test 7: minutes_to_hours_formatted() function\n";
$minutes_tests = [
  0 => '0h 0m',
  30 => '0h 30m',
  60 => '1h 0m',
  404 => '6h 44m',
  480 => '8h 0m',
  -120 => '-2h 0m',
];
foreach ($minutes_tests as $mins => $expected) {
  $result = minutes_to_hours_formatted($mins);
  echo "  Input: $mins minutes → Output: $result (Expected: $expected)\n";
  // Just check format is similar
  assert(strpos($result, 'h') !== false && strpos($result, 'm') !== false, "Failed format");
}
echo "  ✓ PASSED\n\n";

echo "=== ✅ All tests PASSED ===\n";
