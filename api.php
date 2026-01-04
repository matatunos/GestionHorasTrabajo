<?php
/**
 * API para la extensión de Chrome
 * Endpoint seguro para importar datos de fichajes
 * 
 * Requiere:
 * - Sesión autenticada (cookie)
 * - Header X-Requested-With: XMLHttpRequest
 * - CSRF token en X-CSRF-Token (opcional pero recomendado)
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib.php';

// Solo AJAX
if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || $_SERVER['HTTP_X_REQUESTED_WITH'] !== 'XMLHttpRequest') {
  http_response_code(403);
  header('Content-Type: application/json');
  echo json_encode(['ok' => false, 'error' => 'forbidden', 'message' => 'Solo peticiones AJAX permitidas']);
  exit;
}

// Validar HTTPS en producción
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
if ($protocol === 'http' && $_SERVER['HTTP_HOST'] !== 'localhost' && $_SERVER['HTTP_HOST'] !== '127.0.0.1') {
  http_response_code(403);
  header('Content-Type: application/json');
  echo json_encode(['ok' => false, 'error' => 'insecure', 'message' => 'API solo disponible por HTTPS']);
  exit;
}

// ⚠️ IMPORTANTE: Leer php://input UNA SOLA VEZ al inicio (no se puede leer dos veces)
$raw_input = file_get_contents('php://input');
$global_input = json_decode($raw_input, true);

// Autenticación HÍBRIDA: Sesión O Token
$user = null;
$auth_method = null;

// 1. Intentar autenticación por sesión
session_start();
if (!empty($_SESSION['user_id'])) {
  $user = current_user();
  $auth_method = 'session';
}

// 2. Si no hay sesión, intentar token
if (!$user) {
  $token = $global_input['token'] ?? null;
  
  if ($token) {
    $user_id = validate_extension_token($token);
    if ($user_id) {
      // Validar usuario existe
      $pdo = get_pdo();
      $stmt = $pdo->prepare('SELECT id, username, email, name FROM users WHERE id = ?');
      $stmt->execute([$user_id]);
      $user = $stmt->fetch();
      $auth_method = 'token';
    }
  }
}

// Si no se autenticó por ningún método, rechazar
if (!$user) {
  http_response_code(401);
  header('Content-Type: application/json');
  echo json_encode([
    'ok' => false, 
    'error' => 'unauthorized', 
    'message' => 'Sesión expirada o token inválido. Por favor, descarga la extensión nuevamente.'
  ]);
  exit;
}

// Responder JSON
header('Content-Type: application/json');

// Rutas de la API
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = str_replace('/api.php', '', $path);

// GET /api.php/debug - Ver datos que llegan (solo para debugging)
if ($method === 'GET' && $path === '/debug') {
  echo json_encode([
    'debug' => 'API funcionando',
    'method' => $_SERVER['REQUEST_METHOD'],
    'path' => $path,
    'user' => ['id' => $user['id'], 'email' => $user['email']],
    'raw_input_length' => strlen($raw_input),
    'parsed_input_keys' => $global_input ? array_keys($global_input) : [],
    'timestamp' => date('Y-m-d H:i:s')
  ]);
  exit;
}

// POST /api.php/import - Importar múltiples fichajes
if ($method === 'POST' && ($path === '' || $path === '/')) {
  handleImportFichajes($global_input);
}
// GET /api.php/status - Estado de la extensión
else if ($method === 'GET' && ($path === '' || $path === '/')) {
  echo json_encode([
    'ok' => true,
    'status' => 'active',
    'user' => [
      'id' => $user['id'],
      'name' => $user['name'],
      'email' => $user['email']
    ],
    'message' => 'GestionHorasTrabajo API v1.1 - Extensión Chrome con autenticación híbrida'
  ]);
  exit;
}
// POST /api.php/entry - Crear/actualizar entrada
else if ($method === 'POST' && $path === '/entry') {
  handleCreateEntry($global_input);
}
// DELETE /api.php/entry/{date} - Eliminar entrada
else if ($method === 'DELETE' && preg_match('#^/entry/(.+)$#', $path, $matches)) {
  handleDeleteEntry($matches[1]);
}
// Ruta no encontrada
else {
  http_response_code(404);
  echo json_encode(['ok' => false, 'error' => 'not_found', 'message' => 'Endpoint no encontrado']);
  exit;
}

/**
 * Importar múltiples fichajes (desde extensión)
 * POST /api.php/import
 * Body: { entries: [{ date, start, end, coffee_out, coffee_in, lunch_out, lunch_in, note }] }
 */
function handleImportFichajes($input) {
  global $pdo, $user;
  
  // DEBUG: Loguear qué se recibe
  $debug_log = fopen('/tmp/gestion_import_debug.log', 'a');
  fwrite($debug_log, "\n=== " . date('Y-m-d H:i:s') . " ===\n");
  fwrite($debug_log, "User: " . ($user['email'] ?? 'UNKNOWN') . "\n");
  fwrite($debug_log, "Input received:\n" . json_encode($input, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
  fclose($debug_log);
  
  if (!isset($input['entries']) || !is_array($input['entries'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_input', 'message' => 'Se requiere "entries" array']);
    exit;
  }
  
  $imported = 0;
  $errors = [];
  
  foreach ($input['entries'] as $idx => $entry) {
    // Validar que tenga fecha
    if (!isset($entry['date']) || empty($entry['date'])) {
      $errors[] = "Entrada $idx: falta fecha";
      continue;
    }
    
    $date = $entry['date'];
    
    // Validar formato de fecha (YYYY-MM-DD)
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
    
    // Validar entrada
    $validation = validate_time_entry($data);
    if (!$validation['valid']) {
      $errors[] = "Entrada $idx ($date): " . implode(', ', $validation['errors']);
      continue;
    }
    
    try {
      // UPSERT
      $stmt = $pdo->prepare('SELECT id FROM entries WHERE user_id = ? AND date = ? LIMIT 1');
      $stmt->execute([$user['id'], $date]);
      $row = $stmt->fetch();
      
      if ($row) {
        // UPDATE
        $stmt = $pdo->prepare(
          'UPDATE entries SET start=?,coffee_out=?,coffee_in=?,lunch_out=?,lunch_in=?,end=?,note=?,absence_type=? WHERE id=?'
        );
        $stmt->execute([
          $data['start'], $data['coffee_out'], $data['coffee_in'], $data['lunch_out'],
          $data['lunch_in'], $data['end'], $data['note'], $data['absence_type'], $row['id']
        ]);
      } else {
        // INSERT
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
  
  echo json_encode([
    'ok' => true,
    'imported' => $imported,
    'total' => count($input['entries']),
    'errors' => $errors,
    'message' => "$imported de " . count($input['entries']) . " fichajes importados"
  ]);
}

/**
 * Crear/actualizar entrada individual
 * POST /api.php/entry
 * Body: { date, start, end, ... }
 */
function handleCreateEntry($input) {
  global $pdo, $user;
  
  if (!isset($input['date'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_date']);
    exit;
  }
  
  $date = $input['date'];
  $data = [
    'start' => $input['start'] ?? null,
    'coffee_out' => $input['coffee_out'] ?? null,
    'coffee_in' => $input['coffee_in'] ?? null,
    'lunch_out' => $input['lunch_out'] ?? null,
    'lunch_in' => $input['lunch_in'] ?? null,
    'end' => $input['end'] ?? null,
    'note' => $input['note'] ? (substr($input['note'], 0, 500)) : '',
    'absence_type' => $input['absence_type'] ?? null,
  ];
  
  // Validar
  $validation = validate_time_entry($data);
  if (!$validation['valid']) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'validation_failed', 'errors' => $validation['errors']]);
    exit;
  }
  
  try {
    $stmt = $pdo->prepare('SELECT id FROM entries WHERE user_id = ? AND date = ? LIMIT 1');
    $stmt->execute([$user['id'], $date]);
    $row = $stmt->fetch();
    
    if ($row) {
      $stmt = $pdo->prepare('UPDATE entries SET start=?,coffee_out=?,coffee_in=?,lunch_out=?,lunch_in=?,end=?,note=?,absence_type=? WHERE id=?');
      $stmt->execute([$data['start'],$data['coffee_out'],$data['coffee_in'],$data['lunch_out'],$data['lunch_in'],$data['end'],$data['note'],$data['absence_type'],$row['id']]);
    } else {
      $stmt = $pdo->prepare('INSERT INTO entries (user_id,date,start,coffee_out,coffee_in,lunch_out,lunch_in,end,note,absence_type) VALUES (?,?,?,?,?,?,?,?,?,?)');
      $stmt->execute([$user['id'],$date,$data['start'],$data['coffee_out'],$data['coffee_in'],$data['lunch_out'],$data['lunch_in'],$data['end'],$data['note'],$data['absence_type']]);
    }
    
    echo json_encode(['ok' => true, 'message' => 'Entrada guardada']);
  } catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'database_error', 'message' => $e->getMessage()]);
  }
}

/**
 * Eliminar entrada
 * DELETE /api.php/entry/{date}
 */
function handleDeleteEntry($date) {
  global $pdo, $user;
  
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_date']);
    exit;
  }
  
  try {
    $stmt = $pdo->prepare('DELETE FROM entries WHERE user_id = ? AND date = ?');
    $stmt->execute([$user['id'], $date]);
    
    echo json_encode(['ok' => true, 'message' => 'Entrada eliminada']);
  } catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'database_error', 'message' => $e->getMessage()]);
  }
}
?>