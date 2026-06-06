<?php
/**
 * api/homer-status.php — Endpoint de estado para Homer dashboard
 *
 * Devuelve JSON con estadísticas básicas de la semana y mes actual.
 * Homer puede usarlo como health-check con type: "ping".
 * También accesible directamente para diagnóstico rápido.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');
header('Cache-Control: max-age=300'); // Caché 5 minutos

try {
    $pdo = get_pdo();

    $year  = (int)date('Y');
    $month = (int)date('m');
    $today = date('Y-m-d');

    // Lunes de esta semana
    $dow    = (int)date('N');
    $monday = date('Y-m-d', strtotime("-" . ($dow - 1) . " days"));

    // Horas trabajadas esta semana (usuario admin)
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(
            TIMESTAMPDIFF(MINUTE, e.start, COALESCE(e.end, CURTIME()))
            - COALESCE(TIMESTAMPDIFF(MINUTE, e.coffee_out, e.coffee_in), 0)
            - COALESCE(TIMESTAMPDIFF(MINUTE, e.lunch_out,  e.lunch_in),  0)
        ), 0)
        FROM entries e
        JOIN users u ON e.user_id = u.id AND u.is_admin = 1
        WHERE e.date BETWEEN ? AND ?
          AND e.start IS NOT NULL
          AND e.absence_type IS NULL
    ");
    $stmt->execute([$monday, $today]);
    $min_sem = (int)$stmt->fetchColumn();

    // Guardias de este mes
    $stmt2 = $pdo->prepare("
        SELECT COUNT(*) FROM holidays
        WHERE type = 'guardia' AND YEAR(date) = ? AND MONTH(date) = ?
    ");
    $stmt2->execute([$year, $month]);
    $guardias = (int)$stmt2->fetchColumn();

    // Fichaje activo hoy (entrada sin salida)
    $stmt3 = $pdo->prepare("
        SELECT COUNT(*) FROM entries e
        JOIN users u ON e.user_id = u.id AND u.is_admin = 1
        WHERE e.date = ? AND e.start IS NOT NULL AND e.end IS NULL
    ");
    $stmt3->execute([$today]);
    $fichado = (bool)$stmt3->fetchColumn();

    $h = floor($min_sem / 60);
    $m = $min_sem % 60;

    echo json_encode([
        'ok'          => true,
        'semana_horas' => sprintf('%dh%02dm', $h, $m),
        'guardias_mes' => $guardias,
        'fichado_hoy'  => $fichado,
        'updated'      => date('H:i'),
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db_error']);
}
