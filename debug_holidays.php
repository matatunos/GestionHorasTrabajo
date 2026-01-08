<?php
require_once __DIR__ . '/db.php';

$pdo = get_pdo();
$user_id = 1;
$current_year = 2026;
$week_start = '2026-01-05';
$week_end = '2026-01-11';

echo "=== DEBUG: Reconstrucción de festivos anuales ===\n\n";

// Load holidays
$holidays_this_week = [];
try {
    $holQuery = 'SELECT date, type, label, annual FROM holidays 
                 WHERE (user_id = ? OR user_id IS NULL)
                 AND date >= ? AND date <= ?';
    $holStmt = $pdo->prepare($holQuery);
    
    echo "Query ejecutada con parámetros:\n";
    echo "  user_id: $user_id\n";
    echo "  date >= $week_start\n";
    echo "  date <= $week_end\n";
    echo "  Result: Ninguno (because holidays are stored as 2025-01-06, not 2026-01-06)\n\n";
    
    $holStmt->execute([$user_id, $week_start, $week_end]);
    $holidays_raw = $holStmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Festivos encontrados en rango 2026-01-05 a 2026-01-11: " . count($holidays_raw) . "\n";
    if (count($holidays_raw) > 0) {
        foreach ($holidays_raw as $hol) {
            echo "  - " . $hol['date'] . "\n";
        }
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\n=== SOLUCIÓN: También consultar festivos anuales sin restricción de fecha ===\n\n";

// Correct query - no date restriction for annual holidays
$holidays_this_week_fixed = [];
try {
    $holQuery = 'SELECT date, type, label, annual FROM holidays 
                 WHERE (user_id = ? OR user_id IS NULL)';
    $holStmt = $pdo->prepare($holQuery);
    $holStmt->execute([$user_id]);
    $holidays_raw = $holStmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Total festivos en BD: " . count($holidays_raw) . "\n";
    
    foreach ($holidays_raw as $hol) {
        $hDate = $hol['date'];
        
        // For annual holidays, reconstruct for current year
        if (!empty($hol['annual'])) {
            $hMonth = intval(substr($hDate, 5, 2));
            $hDay = intval(substr($hDate, 8, 2));
            $hDate_reconstructed = sprintf('%04d-%02d-%02d', $current_year, $hMonth, $hDay);
            
            echo sprintf(
                "Festivo anual: %s → Reconstruido para 2026: %s\n",
                $hol['date'],
                $hDate_reconstructed
            );
            $hDate = $hDate_reconstructed;
        }
        
        // Only include if within current week
        if ($hDate >= $week_start && $hDate <= $week_end) {
            $holidays_this_week_fixed[$hDate] = $hol;
            echo "  ✓ INCLUIDO en semana 5-9 enero\n";
        }
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\n=== RESULTADO ===\n";
echo "Festivos en semana 5-9 enero con solución: " . count($holidays_this_week_fixed) . "\n";
foreach ($holidays_this_week_fixed as $date => $hol) {
    echo "  - $date: " . $hol['type'] . " (" . $hol['label'] . ")\n";
}

?>
