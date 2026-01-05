<?php
// Test sin auth - cargar solo las funciones necesarias

function mapTimesToSlots($times) {
    $timeCount = count($times);
    $horas_slots = [null, null, null, null, null, null];
    
    if ($timeCount === 0) return $horas_slots;
    
    $horas_slots[0] = $times[0];
    
    if ($timeCount === 1) return $horas_slots;
    
    if ($timeCount === 2) {
        $horas_slots[5] = $times[1];
        return $horas_slots;
    }
    
    if ($timeCount === 3) {
        $horas_slots[1] = $times[1];
        $horas_slots[5] = $times[2];
        return $horas_slots;
    }
    
    // Para 3+ horas: llena slots del 1 al 4, última va al 5
    $intermediateIndex = 1;
    for ($i = 1; $i < $timeCount - 1; $i++) {
        if ($intermediateIndex <= 4) {
            $horas_slots[$intermediateIndex] = $times[$i];
            $intermediateIndex++;
        }
    }
    $horas_slots[5] = $times[$timeCount - 1];
    
    return $horas_slots;
}

echo "=== Test mapTimesToSlots ===\n\n";

$testCases = [
    'Caso 1: 6 horas' => ['08:00', '10:30', '10:50', '14:00', '14:45', '17:30'],
    'Caso 2: 5 horas' => ['08:15', '10:45', '11:00', '14:15', '15:00', '17:45'],
    'Caso 3: 4 horas' => ['08:00', '10:30', '10:50', '17:00'],
    'Caso 4: 3 horas (sin lunch)' => ['08:00', '10:30', '17:00'],
    'Caso 5: 2 horas' => ['08:00', '17:00'],
    'Caso 6: 1 hora' => ['08:00'],
];

foreach ($testCases as $desc => $horas) {
    $slots = mapTimesToSlots($horas);
    echo "$desc\n";
    echo "  Input: [" . implode(", ", $horas) . "]\n";
    echo "  Output: [" . implode(", ", array_map(fn($v) => $v ?? 'null', $slots)) . "]\n";
    echo "  DB: start=" . ($slots[0] ?? 'NULL') . ", c_out=" . ($slots[1] ?? 'NULL') . ", c_in=" . ($slots[2] ?? 'NULL');
    echo ", l_out=" . ($slots[3] ?? 'NULL') . ", l_in=" . ($slots[4] ?? 'NULL') . ", end=" . ($slots[5] ?? 'NULL') . "\n\n";
}
?>
