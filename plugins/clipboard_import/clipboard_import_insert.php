<?php
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../db.php';
require_login();
$user = current_user();
$pdo = get_pdo();

header('Content-Type: application/json');
$data = json_decode(file_get_contents('php://input'), true);
if (!isset($data['registros']) || !is_array($data['registros'])) {
  echo json_encode(['ok'=>false,'error'=>'No se recibieron registros válidos']);
  exit;
}
$errores = [];
$insertados = 0;
foreach ($data['registros'] as $r) {
  $fecha = $r['fechaISO'] ?? null;
  $horas = $r['horas'] ?? [];
  if (!$fecha || !is_array($horas)) {
    $errores[] = 'Registro inválido: ' . json_encode($r);
    continue;
  }
  // Mapear a slots: start, coffee_out, coffee_in, lunch_out, lunch_in, end
  $slots = array_fill(0, 6, '');
  $count = count($horas);
  if ($count > 0) $slots[0] = $horas[0];
  if ($count > 1) $slots[5] = $horas[$count-1];
  for ($i=1; $i<$count-1 && $i<=4; $i++) $slots[$i] = $horas[$i];
  try {
    $stmt = $pdo->prepare('REPLACE INTO entries (user_id, date, start, coffee_out, coffee_in, lunch_out, lunch_in, end, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
      $user['id'], $fecha,
      $slots[0], $slots[1], $slots[2], $slots[3], $slots[4], $slots[5],
      'Importado portapapeles'
    ]);
    $insertados++;
  } catch (Exception $e) {
    $errores[] = 'Error en ' . $fecha . ': ' . $e->getMessage();
  }
}
echo json_encode(['ok'=>count($errores)===0, 'insertados'=>$insertados, 'errores'=>$errores]);
