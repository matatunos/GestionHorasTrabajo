<?php
require_once __DIR__ . '/auth.php';
require_admin();
require_once __DIR__ . '/db.php';

$pdo = get_pdo();
if (!$pdo) { header('Content-Type: application/json'); echo json_encode(['error'=>'no db']); exit; }

$year = intval($_GET['year'] ?? date('Y'));
$stmt = $pdo->prepare('SELECT * FROM holidays WHERE year = ? OR annual = 1 ORDER BY date ASC');
$stmt->execute([$year]);
$rows = $stmt->fetchAll();

header('Content-Type: application/json');
echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
