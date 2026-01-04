<?php
/**
 * Test script para verificar que la API de importación funciona correctamente
 * Versión sin autenticación para testing
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib.php';

// Asumir usuario 1 para testing
$user = ['id' => 1, 'email' => 'test@example.com'];

// Datos de prueba
$testData = [
  'entries' => [
    [
      'date' => '2025-12-15',
      'start' => '07:30',
      'end' => '17:00',
      'coffee_out' => '10:00',
      'coffee_in' => '10:15',
      'lunch_out' => '13:00',
      'lunch_in' => '14:00',
      'note' => 'Test importación'
    ],
    [
      'date' => '2025-12-16',
      'start' => '08:00',
      'end' => '17:30',
      'note' => 'Test 2'
    ]
  ]
];

echo "=== TEST VALIDACIÓN API ===\n\n";
echo "📤 Datos a procesar:\n";
echo json_encode($testData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

try {
  $pdo = get_pdo();
  $imported = 0;
  $errors = [];

  foreach ($testData['entries'] as $idx => $entry) {
    if (!isset($entry['date']) || empty($entry['date'])) {
      $errors[] = "Entrada $idx: falta fecha";
      continue;
    }
    
    $date = $entry['date'];
    
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
      $errors[] = "Entrada $idx: fecha inválida ($date)";
      continue;
    }
    
    $data = [
      'start' => $entry['start'] ?? null,
      'coffee_out' => $entry['coffee_out'] ?? null,
      'coffee_in' => $entry['coffee_in'] ?? null,
      'lunch_out' => $entry['lunch_out'] ?? null,
      'lunch_in' => $entry['lunch_in'] ?? null,
      'end' => $entry['end'] ?? null,
      'note' => $entry['note'] ? (substr($entry['note'], 0, 500)) : '',
      'absence_type' => $entry['absence_type'] ?? null,
    ];
    
    echo "Validando entrada $idx ($date)...\n";
    $validation = validate_time_entry($data);
    
    if (!$validation['valid']) {
      echo "  ❌ Validación fallida: " . implode(', ', $validation['errors']) . "\n";
      $errors[] = "Entrada $idx ($date): " . implode(', ', $validation['errors']);
      continue;
    }
    
    echo "  ✅ Validación OK\n";
    
    // Aquí iría el INSERT/UPDATE
    $imported++;
  }

  echo "\n📊 RESULTADO:\n";
  echo "✅ Importados: $imported de " . count($testData['entries']) . "\n";

  if (!empty($errors)) {
    echo "❌ Errores (" . count($errors) . "):\n";
    foreach ($errors as $err) {
      echo "  - $err\n";
    }
  } else {
    echo "✅ No hay errores\n";
  }
} catch (Exception $e) {
  echo "❌ Error: " . $e->getMessage() . "\n";
}

?>
