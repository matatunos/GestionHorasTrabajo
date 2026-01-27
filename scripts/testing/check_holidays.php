<?php
require_once __DIR__ . '/db.php';

$pdo = get_pdo();

// Check for Jan 6 holidays (any year)
$stmt = $pdo->prepare("SELECT * FROM holidays WHERE date LIKE '%-01-06' OR date LIKE '2026-01-06' ORDER BY date");
$stmt->execute();
$holidays = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Festivos del 6 de enero en la base de datos:\n";
echo str_repeat("-", 100) . "\n";

if (empty($holidays)) {
    echo "No hay festivos registrados para el 6 de enero.\n";
} else {
    foreach ($holidays as $h) {
        echo sprintf(
            "Fecha: %s | Tipo: %s | Label: %s | Anual: %s | Usuario: %s\n",
            $h['date'],
            $h['type'],
            $h['label'] ?? '(sin descripción)',
            !empty($h['annual']) ? 'SÍ (se repite cada año)' : 'NO',
            $h['user_id'] ?? 'Global'
        );
    }
}

echo "\n";
echo "Todos los festivos registrados:\n";
echo str_repeat("-", 100) . "\n";

$stmt = $pdo->prepare("SELECT * FROM holidays ORDER BY date");
$stmt->execute();
$all_holidays = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($all_holidays)) {
    echo "No hay festivos registrados en la base de datos.\n";
} else {
    foreach ($all_holidays as $h) {
        echo sprintf(
            "%s | %s | %s | Annual: %s\n",
            $h['date'],
            $h['type'],
            $h['label'] ?? '-',
            !empty($h['annual']) ? 'YES' : 'NO'
        );
    }
}

?>
