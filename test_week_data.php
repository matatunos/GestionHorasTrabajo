<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib.php';

$pdo = get_pdo();

// Week of Jan 5-9, 2026 (Monday-Friday)
$week_start = '2026-01-05';
$week_end = '2026-01-09';

echo "=== SEMANA DEL 5 AL 9 DE ENERO DE 2026 ===\n\n";

// Get all entries for this week
$query = 'SELECT * FROM entries WHERE date >= ? AND date <= ? ORDER BY date ASC';
$stmt = $pdo->prepare($query);
$stmt->execute([$week_start, $week_end]);
$entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "FICHAJES REGISTRADOS:\n";
echo str_repeat("-", 100) . "\n";

if (empty($entries)) {
    echo "No hay fichajes registrados para esta semana.\n";
} else {
    foreach ($entries as $entry) {
        $dow_map = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes'];
        $dow = (int)date('N', strtotime($entry['date']));
        $dow_name = $dow_map[$dow] ?? 'Unknown';
        
        echo sprintf(
            "%s (%s): %s - %s | Café: %s-%s | Comida: %s-%s | Usuario: %d\n",
            $entry['date'],
            $dow_name,
            $entry['start'] ?? '-',
            $entry['end'] ?? '-',
            $entry['coffee_out'] ?? '-',
            $entry['coffee_in'] ?? '-',
            $entry['lunch_out'] ?? '-',
            $entry['lunch_in'] ?? '-',
            $entry['user_id']
        );
    }
}

// Get holidays for this week
echo "\n" . str_repeat("-", 100) . "\n";
echo "FESTIVOS/AUSENCIAS REGISTRADOS:\n";
echo str_repeat("-", 100) . "\n";

$hol_query = 'SELECT * FROM holidays WHERE date >= ? AND date <= ? ORDER BY date ASC';
$hol_stmt = $pdo->prepare($hol_query);
$hol_stmt->execute([$week_start, $week_end]);
$holidays = $hol_stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($holidays)) {
    echo "No hay festivos/ausencias registrados para esta semana.\n";
} else {
    foreach ($holidays as $hol) {
        echo sprintf(
            "%s: %s (%s) - %s [Usuario: %s]\n",
            $hol['date'],
            $hol['type'],
            $hol['label'] ?? '(sin descripción)',
            !empty($hol['annual']) ? '(Anual)' : '(Específico)',
            $hol['user_id'] ?? 'Global'
        );
    }
}

// Summary
echo "\n" . str_repeat("=", 100) . "\n";
echo "RESUMEN:\n";
echo "- Fichajes: " . count($entries) . "\n";
echo "- Festivos: " . count($holidays) . "\n";
echo "\nDías de la semana:\n";
echo "  Lunes 5: ";
echo count(array_filter($entries, fn($e) => $e['date'] === '2026-01-05')) > 0 ? "✓ Con fichajes" : "✗ Sin fichajes";
echo count(array_filter($holidays, fn($h) => $h['date'] === '2026-01-05')) > 0 ? " (Festivo)" : "";
echo "\n";
echo "  Martes 6: ";
echo count(array_filter($entries, fn($e) => $e['date'] === '2026-01-06')) > 0 ? "✓ Con fichajes" : "✗ Sin fichajes";
echo count(array_filter($holidays, fn($h) => $h['date'] === '2026-01-06')) > 0 ? " (Festivo)" : "";
echo "\n";
echo "  Miércoles 7: ";
echo count(array_filter($entries, fn($e) => $e['date'] === '2026-01-07')) > 0 ? "✓ Con fichajes" : "✗ Sin fichajes";
echo count(array_filter($holidays, fn($h) => $h['date'] === '2026-01-07')) > 0 ? " (Festivo)" : "";
echo "\n";
echo "  Jueves 8: ";
echo count(array_filter($entries, fn($e) => $e['date'] === '2026-01-08')) > 0 ? "✓ Con fichajes" : "✗ Sin fichajes";
echo count(array_filter($holidays, fn($h) => $h['date'] === '2026-01-08')) > 0 ? " (Festivo)" : "";
echo "\n";
echo "  Viernes 9: ";
echo count(array_filter($entries, fn($e) => $e['date'] === '2026-01-09')) > 0 ? "✓ Con fichajes" : "✗ Sin fichajes";
echo count(array_filter($holidays, fn($h) => $h['date'] === '2026-01-09')) > 0 ? " (Festivo)" : "";
echo "\n";

?>
