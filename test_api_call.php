<?php
/**
 * Simulate authenticated API call to schedule_suggestions.php
 * This tests the actual API response with real logic
 */

// Start session before any includes
session_start();

// Mock authentication BEFORE including auth.php
$_SESSION['user_id'] = 1;
$_SESSION['user_name'] = 'Test User';

// Set request parameters
$_GET['action'] = 'suggestions';
$_GET['user_id'] = '1';
$_GET['week_start'] = '2026-01-05';
$_GET['force_start_time'] = '';

// Change to the correct directory
chdir('/opt/GestionHorasTrabajo');

// Include the API file
ob_start();
include 'schedule_suggestions.php';
$output = ob_get_clean();

// Parse and display JSON response
if (!empty($output)) {
    $response = json_decode($output, true);
    
    if ($response && isset($response['success']) && $response['success']) {
        echo "✅ API RESPONSE VALID\n\n";
        
        // Check key fields
        echo "📊 SUMMARY:\n";
        echo "  Target weekly: " . $response['target_weekly_hours'] . "h\n";
        echo "  Remaining: " . $response['remaining_hours'] . "h\n";
        echo "  Days remaining: " . $response['analysis']['days_remaining'] . "\n\n";
        
        echo "📅 SUGGESTIONS:\n";
        if (!empty($response['suggestions'])) {
            foreach ($response['suggestions'] as $sugg) {
                $day_names = ['','Mon','Tue','Wed','Thu','Fri','Sat'];
                $dow = (int)date('N', strtotime($sugg['date']));
                
                echo "  " . $sugg['date'] . " (" . $day_names[$dow] . "):\n";
                echo "    Start: " . $sugg['start'] . "\n";
                echo "    End: " . $sugg['end'] . "\n";
                echo "    Hours: " . $sugg['hours'] . "h\n";
                echo "    Type: " . $sugg['jornada_type'] . "\n";
                
                // Verify Friday constraint
                if ($dow === 5) {
                    $hours = $sugg['hours'];
                    $end_parts = explode(':', $sugg['end']);
                    $end_minutes = intval($end_parts[0]) * 60 + intval($end_parts[1]);
                    $max_minutes = 14 * 60;  // 14:00
                    
                    if ($hours <= 6.0 && $end_minutes <= $max_minutes) {
                        echo "    ✅ CONSTRAINT OK (≤6h, exit ≤14:00)\n";
                    } else {
                        echo "    ❌ CONSTRAINT VIOLATED\n";
                        echo "       Hours: $hours (max 6) | Exit: " . $sugg['end'] . " (max 14:00)\n";
                    }
                }
                echo "\n";
            }
        } else {
            echo "  No suggestions (objective reached)\n";
        }
        
        echo "🎯 TARGETS ANALYSIS:\n";
        echo "  Shift pattern: " . $response['shift_pattern']['type'] . "\n";
        echo "  Message: " . $response['message'] . "\n";
        
    } else {
        echo "❌ API ERROR\n";
        if (isset($response['error'])) {
            echo "Error: " . $response['error'] . "\n";
        }
    }
} else {
    echo "❌ NO OUTPUT FROM API\n";
}
