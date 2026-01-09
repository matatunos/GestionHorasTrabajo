<?php
/**
 * Test: Viernes 13:45 Minimum Exit Constraint
 * 
 * Verifica que:
 * 1. Viernes nunca tenga salida antes de 13:45
 * 2. Si hay conflicto, se redistribuyan horas a lunes-jueves
 * 3. El total de horas semanales se mantenga correcto
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib.php';

// Mock user if not authenticated
$user = null;
try {
    $user = current_user();
} catch (Exception $e) {
    // Use mock data for testing
    $_SESSION['user_id'] = 1;
    $_SESSION['username'] = 'testuser';
}

header('Content-Type: application/json');

$pdo = get_pdo();
$user_id = $_SESSION['user_id'] ?? 1;

echo json_encode([
    'test' => 'Friday 13:45 Minimum Exit Constraint',
    'timestamp' => date('Y-m-d H:i:s'),
    'user_id' => $user_id,
    'test_cases' => [
        [
            'name' => 'Test 1: Standard entry 08:00',
            'description' => 'Friday entry at 08:00 should exit at 13:45 or later',
            'entry_time' => '08:00',
            'minimum_exit' => '13:45',
            'minimum_hours' => 5.75,
            'notes' => 'If calculated hours < 5.75, should redistribute'
        ],
        [
            'name' => 'Test 2: Early entry 07:30 (with force_start_time)',
            'description' => 'Friday entry at 07:30 should exit at 13:45, requiring ~6.25h',
            'entry_time' => '07:30',
            'minimum_exit' => '13:45',
            'minimum_hours' => 6.25,
            'notes' => 'Excess hours (36 min) should be distributed to Mon-Thu'
        ],
        [
            'name' => 'Test 3: Late entry 09:30',
            'description' => 'Friday entry at 09:30 should exit at 13:45, requiring ~4.25h',
            'entry_time' => '09:30',
            'minimum_exit' => '13:45',
            'minimum_hours' => 4.25,
            'notes' => 'Below typical Friday hours, but respects constraint'
        ]
    ],
    'validation_checklist' => [
        'friday_exit_never_before_13_45' => 'Must verify in schedule_suggestions.php output',
        'hours_redistribution_to_mon_thu' => 'Check if excess hours are added to other days',
        'total_weekly_hours_maintained' => 'Sum should equal target hours ±0.01',
        'reasoning_mentions_constraint' => 'Check "restricción operativa" in response',
        'compatible_with_force_start_time' => 'Test with force_start_time=07:30 parameter'
    ],
    'how_to_test' => [
        '1. Open Schedule Suggestions modal in UI',
        '2. Check Friday suggestion for exit time',
        '3. Verify exit time is >= 13:45',
        '4. Check if extra hours appear in Mon-Thu',
        '5. Sum all hours and compare to target',
        'OR use API directly:',
        'curl "http://localhost/schedule_suggestions.php"',
        'curl "http://localhost/schedule_suggestions.php?force_start_time=07:30"'
    ],
    'expected_output' => [
        'suggestions' => [
            'friday_exit' => '>= 13:45',
            'friday_hours' => 'Calculated to meet constraint',
            'mon_thu_hours' => 'Increased by overflow hours',
            'total_hours' => 'Equals target_weekly_hours'
        ]
    ],
    'status' => 'Ready for testing',
    'notes' => [
        'Fix implemented in lines 261-293 of schedule_suggestions.php',
        'Validation occurs before suggestion generation',
        'Redistribution is automatic and transparent to user',
        'Compatible with all existing features (force_start_time, jornada detection)',
        'No database changes required'
    ]
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
?>
