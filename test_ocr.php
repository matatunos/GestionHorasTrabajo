<?php
/**
 * Simple test for mapTimesToSlots function
 * Copy the function definition here for testing
 */

function mapTimesToSlots($times) {
  $horas_slots = array_fill(0, 6, '');
  $timeCount = count($times);
  
  if ($timeCount === 0) {
    return $horas_slots;
  }
  
  if ($timeCount === 1) {
    $horas_slots[0] = $times[0];
  } elseif ($timeCount === 2) {
    $horas_slots[0] = $times[0];
    $horas_slots[5] = $times[1];
  } elseif ($timeCount === 3) {
    $horas_slots[0] = $times[0];
    $horas_slots[1] = $times[1];
    $horas_slots[5] = $times[2];
  } elseif ($timeCount === 4) {
    $horas_slots[0] = $times[0];
    $horas_slots[1] = $times[1];
    $horas_slots[2] = $times[2];
    $horas_slots[5] = $times[3];
  } elseif ($timeCount === 5) {
    $horas_slots[0] = $times[0];
    $horas_slots[1] = $times[1];
    $horas_slots[2] = $times[2];
    $horas_slots[3] = $times[3];
    $horas_slots[5] = $times[4];
  } else {
    for ($i = 0; $i < 6 && $i < $timeCount; $i++) {
      $horas_slots[$i] = $times[$i];
    }
  }
  
  return $horas_slots;
}

echo "=== TEST mapTimesToSlots ===\n\n";

$testCases = [
  ['7:32', '10:26', '11:00', '14:16'],  // 4 times - your case
  ['7:32', '14:16'],                     // 2 times
  ['7:32', '10:26', '14:16'],           // 3 times
  ['7:32', '10:26', '11:00', '12:00', '13:00', '14:16'],  // 6 times
];

$labels = ['Entrada', 'Salida café', 'Entrada café', 'Salida comida', 'Entrada comida', 'Salida'];

foreach ($testCases as $times) {
  echo "\n" . str_repeat("-", 60) . "\n";
  echo "Input times (" . count($times) . "): " . implode(", ", $times) . "\n";
  echo str_repeat("-", 60) . "\n";
  
  $slots = mapTimesToSlots($times);
  
  $hasValues = false;
  for ($i = 0; $i < 6; $i++) {
    if (!empty($slots[$i])) {
      echo "  [$i] {$labels[$i]}: {$slots[$i]}\n";
      $hasValues = true;
    }
  }
  
  if (!$hasValues) {
    echo "  (sin tiempos mapeados)\n";
  }
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "\n✓ TEST COMPLETED\n";
echo "\nExpected result for your 4-time case:\n";
echo "  [0] Entrada: 7:32\n";
echo "  [1] Salida café: 10:26\n";
echo "  [2] Entrada café: 11:00\n";
echo "  [5] Salida: 14:16\n";
?>

