<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib.php';

$pdo = get_pdo();
$user_id = 1;
$today = '2026-01-07';
$current_year = 2026;
$week_start = '2026-01-05';
$week_end = '2026-01-11';

echo "=== Verificación de día de semana ===\n\n";

for ($i = 5; $i <= 9; $i++) {
    $date = "2026-01-0$i";
    $dow = date('N', strtotime($date));
    $dow_name = ['', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes'][$dow];
    echo "$date = Día $dow ($dow_name)\n";
}

echo "\n=== Array $week_data ===\n";

$week_data = [];
for ($i = 1; $i <= 7; $i++) {
    $date = date('Y-m-d', strtotime($week_start . " +$i days"));
    $week_data[$i] = ['date' => $date, 'hours' => 0];
    echo "week_data[$i] = $date\n";
}

echo "\n=== Cálculo correcto ===\n";
echo "Lunes es día 1 de semana, fecha: 2026-01-05\n";
echo "Martes es día 2 de semana, fecha: 2026-01-06\n";
echo "Miércoles es día 3 de semana, fecha: 2026-01-07\n";
echo "Jueves es día 4 de semana, fecha: 2026-01-08\n";
echo "Viernes es día 5 de semana, fecha: 2026-01-09\n";
?>
