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

require_once __DIR__ . '/JWTHelper.php';
require_once __DIR__ . '/LogConfig.php';

// Inicializar logging
LogConfig::init();

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib.php';

// ⚠️ CORS Headers para permitir solicitudes desde la extensión Chrome
// Cuando usamos credentials: 'include' en fetch, no podemos usar Access-Control-Allow-Origin: *
// Necesitamos permitir el origin específico del cliente (en este caso la extensión de Chrome)
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

// Permitir extensión Chrome y localhost
$allowed_origins = [
  'chrome-extension://',  // Cualquier extensión (es básicamente inseguro, pero necesario para extensiones)
  'http://localhost',
  'http://127.0.0.1',
  'https://calendar.favala.es',
  'https://localhost'
];

$should_allow = false;
foreach ($allowed_origins as $allowed) {
  if (strpos($origin, $allowed) === 0) {
    $should_allow = true;
    break;
  }
}

if ($should_allow) {
  header('Access-Control-Allow-Origin: ' . $origin);
  header('Access-Control-Allow-Credentials: true');
}

header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, X-CSRF-Token');
header('Access-Control-Max-Age: 86400');

// Security Headers - Protección contra ataques comunes
header('X-Content-Type-Options: nosniff'); // Prevent MIME type sniffing
header('X-Frame-Options: DENY'); // Prevent clickjacking
header('X-XSS-Protection: 1; mode=block'); // Enable XSS filter
header('Content-Security-Policy: default-src \'self\'; script-src \'self\' \'unsafe-inline\'; style-src \'self\' \'unsafe-inline\''); // CSP
header('Referrer-Policy: strict-origin-when-cross-origin'); // Control referrer info
header('Permissions-Policy: geolocation=(), microphone=(), camera=()'); // Restrict APIs
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
  header('Strict-Transport-Security: max-age=31536000; includeSubDomains'); // HSTS - solo si HTTPS
}

// Manejar preflight requests (OPTIONS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(200);
  exit;
}

// Solo permitir AJAX y apps móviles (no navegador directo)
// Permitir: X-Requested-With (AJAX), Authorization (Bearer token móvil)
$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest';
$is_mobile = !empty($_SERVER['HTTP_AUTHORIZATION']);
$is_login = strpos($_SERVER['REQUEST_URI'], '/login') !== false;

if (!$is_ajax && !$is_mobile && !$is_login) {
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

// Responder JSON
header('Content-Type: application/json');

// Rutas de la API
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = str_replace('/api.php', '', $path);

// ============================================
// ENDPOINT LOGIN (sin autenticación requerida)
// ============================================
if ($method === 'POST' && $path === '/login') {
  $username = trim($global_input['username'] ?? '');
  $password = $global_input['password'] ?? '';
  
  // Validación de entrada
  if (empty($username) || empty($password)) {
    http_response_code(400);
    echo json_encode([
      'ok' => false,
      'error' => 'missing_fields',
      'message' => 'Usuario y contraseña requeridos'
    ]);
    exit;
  }
  
  // Validar longitud máxima
  if (strlen($username) > 255 || strlen($password) > 255) {
    http_response_code(400);
    echo json_encode([
      'ok' => false,
      'error' => 'invalid_input',
      'message' => 'Los datos ingresados son inválidos'
    ]);
    exit;
  }
  
  // Validar credenciales
  $pdo = get_pdo();
  $stmt = $pdo->prepare('SELECT id, username, email, name, password FROM users WHERE username = ?');
  $stmt->execute([$username]);
  $user_data = $stmt->fetch();
  
  if (!$user_data) {
    LogConfig::jsonLog('auth', [
      'action' => 'LOGIN_FAILED',
      'reason' => 'user_not_found',
      'username' => $username
    ]);
    http_response_code(401);
    echo json_encode([
      'ok' => false,
      'error' => 'invalid_credentials',
      'message' => 'Usuario o contraseña inválidos'
    ]);
    exit;
  }
  
  // Verificar contraseña (intentar ambos: password_verify y comparación directa)
  $password_valid = false;
  if (function_exists('password_verify')) {
    $password_valid = password_verify($password, $user_data['password']);
  } else {
    // Fallback: comparación directa si no está hasheada
    $password_valid = ($password === $user_data['password']);
  }
  
  if (!$password_valid) {
    LogConfig::jsonLog('auth', [
      'action' => 'LOGIN_FAILED',
      'reason' => 'invalid_password',
      'username' => $username,
      'user_id' => $user_data['id']
    ]);
    http_response_code(401);
    echo json_encode([
      'ok' => false,
      'error' => 'invalid_credentials',
      'message' => 'Usuario o contraseña inválidos'
    ]);
    exit;
  }
  
  // Generar JWT token usando JWTHelper
  $token = JWTHelper::create($user_data['id'], $user_data['username'], [
    'email' => $user_data['email'],
    'name' => $user_data['name']
  ]);
  
  LogConfig::jsonLog('auth', [
    'action' => 'LOGIN_SUCCESS',
    'user_id' => $user_data['id'],
    'username' => $user_data['username']
  ]);
  
  echo json_encode([
    'ok' => true,
    'token' => $token,
    'user' => [
      'id' => $user_data['id'],
      'username' => $user_data['username'],
      'email' => $user_data['email'],
      'name' => $user_data['name']
    ]
  ]);
  exit;
}

// ============================================
// Autenticación HÍBRIDA: Sesión O Token
// ============================================

// 1. Intentar autenticación por sesión
session_start();
if (!empty($_SESSION['user_id'])) {
  $user = current_user();
  $auth_method = 'session';
}

// 2. Si no hay sesión, intentar token
if (!$user) {
  // Intentar Bearer token primero (móvil)
  $auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
  if (preg_match('/Bearer\s+(\S+)/', $auth_header, $matches)) {
    $token = $matches[1];
    // Validar JWT token
    $payload = JWTHelper::verify($token);
    if ($payload && isset($payload['user_id'])) {
      $user_id = $payload['user_id'];
      $pdo = get_pdo();
      $stmt = $pdo->prepare('SELECT id, username, email, name FROM users WHERE id = ?');
      $stmt->execute([$user_id]);
      $user = $stmt->fetch();
      $auth_method = 'bearer_token';
    }
  }
  
  // Si no hay Bearer token, intentar token en JSON (extensión Chrome)
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
// GET /api.php/me - Datos del usuario actual (NUEVO)
else if ($method === 'GET' && $path === '/me') {
  echo json_encode([
    'ok' => true,
    'data' => [
      'id' => $user['id'],
      'username' => $user['username'],
      'email' => $user['email'],
      'name' => $user['name'] ?? $user['username'],
    ]
  ]);
  exit;
}
// GET /api.php/entries/today - Fichajes de hoy (NUEVO - para móvil)
else if ($method === 'GET' && $path === '/entries/today') {
  $pdo = get_pdo();
  $today = date('Y-m-d');
  
  try {
    $stmt = $pdo->prepare('
      SELECT id, user_id, date, start, end, lunch_out, lunch_in, coffee_out, coffee_in, note, absence_type
      FROM entries
      WHERE user_id = ? AND date = ?
      LIMIT 1
    ');
    $stmt->execute([$user['id'], $today]);
    $entry = $stmt->fetch();
    
    echo json_encode([
      'ok' => true,
      'data' => $entry ? [$entry] : [],
      'timestamp' => date('Y-m-d H:i:s')
    ]);
  } catch (Exception $e) {
    http_response_code(500);
    error_log('Entries/today error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'database_error', 'message' => 'Error procesando solicitud']);
  }
  exit;
}
// GET /api.php/entries - Historial de fichajes con paginación (NUEVO - para móvil)
else if ($method === 'GET' && $path === '/entries') {
  $limit = (int)($_GET['limit'] ?? 30);
  $offset = (int)($_GET['offset'] ?? 0);
  $limit = min($limit, 100); // Máximo 100
  
  $pdo = get_pdo();
  
  try {
    $stmt = $pdo->prepare('
      SELECT id, user_id, date, start, end, lunch_out, lunch_in, coffee_out, coffee_in, note, absence_type
      FROM entries
      WHERE user_id = ?
      ORDER BY date DESC, start DESC
      LIMIT ? OFFSET ?
    ');
    $stmt->execute([$user['id'], $limit, $offset]);
    $entries = $stmt->fetchAll();
    
    echo json_encode([
      'ok' => true,
      'data' => $entries,
      'pagination' => [
        'limit' => $limit,
        'offset' => $offset,
        'count' => count($entries)
      ]
    ]);
  } catch (Exception $e) {
    http_response_code(500);
    error_log('Entries error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'database_error', 'message' => 'Error procesando solicitud']);
  }
  exit;
}
// POST /api.php/entries/checkin - Registrar entrada (NUEVO - para móvil)
else if ($method === 'POST' && $path === '/entries/checkin') {
  $pdo = get_pdo();
  $today = date('Y-m-d');
  $now = date('H:i:s');
  
  try {
    // Verificar si ya existe entrada hoy
    $stmt = $pdo->prepare('
      SELECT id, end FROM entries
      WHERE user_id = ? AND date = ?
      LIMIT 1
    ');
    $stmt->execute([$user['id'], $today]);
    $existing = $stmt->fetch();
    
    if ($existing && $existing['end']) {
      // Ya tiene entrada y salida hoy, error
      http_response_code(400);
      echo json_encode([
        'ok' => false,
        'error' => 'already_checked_out',
        'message' => 'Ya completaste tu jornada hoy'
      ]);
      exit;
    } elseif ($existing && !$existing['end']) {
      // Ya tiene entrada sin salida, error
      http_response_code(400);
      echo json_encode([
        'ok' => false,
        'error' => 'already_checked_in',
        'message' => 'Ya tienes una entrada registrada sin salida'
      ]);
      exit;
    }
    
    // Crear entrada nueva
    $stmt = $pdo->prepare('
      INSERT INTO entries (user_id, date, start)
      VALUES (?, ?, ?)
    ');
    $stmt->execute([$user['id'], $today, $now]);
    $id = $pdo->lastInsertId();
    
    // Retornar entrada creada
    $stmt = $pdo->prepare('
      SELECT id, user_id, date, start, end FROM entries WHERE id = ?
    ');
    $stmt->execute([$id]);
    $entry = $stmt->fetch();
    
    echo json_encode([
      'ok' => true,
      'data' => $entry,
      'message' => 'Entrada registrada'
    ]);
  } catch (Exception $e) {
    http_response_code(500);
    error_log('Checkin error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'database_error', 'message' => 'Error procesando solicitud']);
  }
  exit;
}
// POST /api.php/entries/checkout - Registrar salida (NUEVO - para móvil)
else if ($method === 'POST' && $path === '/entries/checkout') {
  $pdo = get_pdo();
  $today = date('Y-m-d');
  $now = date('H:i:s');
  
  try {
    // Obtener entrada sin salida de hoy
    $stmt = $pdo->prepare('
      SELECT id FROM entries
      WHERE user_id = ? AND date = ? AND end IS NULL
      LIMIT 1
    ');
    $stmt->execute([$user['id'], $today]);
    $entry = $stmt->fetch();
    
    if (!$entry) {
      http_response_code(400);
      echo json_encode([
        'ok' => false,
        'error' => 'no_active_entry',
        'message' => 'No hay entrada activa para cerrar'
      ]);
      exit;
    }
    
    // Actualizar con salida
    $stmt = $pdo->prepare('
      UPDATE entries
      SET end = ?
      WHERE id = ?
    ');
    $stmt->execute([$now, $entry['id']]);
    
    // Retornar entrada actualizada
    $stmt = $pdo->prepare('
      SELECT id, user_id, date, start, end FROM entries WHERE id = ?
    ');
    $stmt->execute([$entry['id']]);
    $updatedEntry = $stmt->fetch();
    
    echo json_encode([
      'ok' => true,
      'data' => $updatedEntry,
      'message' => 'Salida registrada'
    ]);
  } catch (Exception $e) {
    http_response_code(500);
    error_log('Checkout error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'database_error', 'message' => 'Error procesando solicitud']);
  }
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
    error_log('Create entry error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'database_error', 'message' => 'Error procesando solicitud']);
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
    error_log('Delete entry error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'database_error', 'message' => 'Error procesando solicitud']);
  }
}
?>