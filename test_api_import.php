<?php
/**
 * Test script para verificar que la API de importación funciona correctamente
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib.php';

require_login();
$user = current_user();

// Simular una solicitud POST como lo haría la extensión
echo "=== TEST API IMPORTACIÓN ===\n\n";

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

echo "📤 Datos a enviar:\n";
echo json_encode($testData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

// Simular el proceso de validación
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
  
  $validation = validate_time_entry($data);
  if (!$validation['valid']) {
    $errors[] = "Entrada $idx ($date): " . implode(', ', $validation['errors']);
    continue;
  }
  
  try {
    $stmt = $pdo->prepare('SELECT id FROM entries WHERE user_id = ? AND date = ? LIMIT 1');
    $stmt->execute([$user['id'], $date]);
    $row = $stmt->fetch();
    
    if ($row) {
      echo "  ⚠️ Entrada $date ya existe, actualizando...\n";
      $stmt = $pdo->prepare(
        'UPDATE entries SET start=?,coffee_out=?,coffee_in=?,lunch_out=?,lunch_in=?,end=?,note=?,absence_type=? WHERE id=?'
      );
      $stmt->execute([
        $data['start'], $data['coffee_out'], $data['coffee_in'], $data['lunch_out'],
        $data['lunch_in'], $data['end'], $data['note'], $data['absence_type'], $row['id']
      ]);
    } else {
      echo "  ✅ Creando entrada para $date...\n";
      $stmt = $pdo->prepare(
        'INSERT INTO entries (user_id,date,start,coffee_out,coffee_in,lunch_out,lunch_in,end,note,absence_type) VALUES (?,?,?,?,?,?,?,?,?,?)'
      );
      $stmt->execute([
        $user['id'], $date, $data['start'], $data['coffee_out'], $data['coffee_in'],
        $data['lunch_out'], $data['lunch_in'], $data['end'], $data['note'], $data['absence_type']
      ]);
    }
    
    $imported++;
  } catch (Exception $e) {
    $errors[] = "Entrada $idx ($date): " . $e->getMessage();
  }
}

echo "\n📊 RESULTADO:\n";
echo "✅ Importados: $imported de " . count($testData['entries']) . "\n";

if (!empty($errors)) {
  echo "❌ Errores (" . count($errors) . "):\n";
  foreach ($errors as $err) {
    echo "  - $err\n";
  }
}

echo "\n✓ Test completado\n";
?>
