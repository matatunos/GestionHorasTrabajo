<?php
header('Content-Type: application/json');
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $errstr . ' (' . $errfile . ':' . $errline . ')'
    ]);
    exit;
});

try {
    require_once __DIR__ . '/schedule_suggestions.php';
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
