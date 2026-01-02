<?php
require_once __DIR__ . '/../db.php';
$pdo = get_pdo();
if (!$pdo) {
    fwrite(STDERR, "No se pudo conectar a la base de datos.\n");
    exit(1);
}

$sql = "SELECT date, start, coffee_out, coffee_in, lunch_out, lunch_in, end
        FROM entries
        WHERE start IS NOT NULL OR coffee_out IS NOT NULL OR coffee_in IS NOT NULL OR lunch_out IS NOT NULL OR lunch_in IS NOT NULL OR end IS NOT NULL
        ORDER BY date ASC";
$stmt = $pdo->query($sql);
$rows = $stmt->fetchAll();

if (!$rows) {
    fwrite(STDOUT, "No se encontraron fechas con datos.\n");
    exit(0);
}

foreach ($rows as $r) {
    $parts = [];
    foreach (['start','coffee_out','coffee_in','lunch_out','lunch_in','end'] as $k) {
        if (!empty($r[$k])) $parts[] = $k . ':' . $r[$k];
    }
    fwrite(STDOUT, $r['date'] . ' -> ' . (count($parts) ? implode(', ', $parts) : '(sin horas)') . "\n");
}
