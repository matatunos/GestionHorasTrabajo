<?php
/**
 * Nuevos endpoints para app móvil
 * Agregar estas rutas a tu api.php existente
 */

// GET /api.php/me - Datos del usuario actual
if ($method === 'GET' && $path === '/me') {
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

// GET /api.php/entries/today - Fichajes de hoy
if ($method === 'GET' && $path === '/entries/today') {
  $pdo = get_pdo();
  $today = date('Y-m-d');
  
  $stmt = $pdo->prepare('
    SELECT id, user_id, date, entrada, salida, created_at
    FROM fichajes
    WHERE user_id = ? AND DATE(date) = ?
    ORDER BY entrada ASC
  ');
  $stmt->execute([$user['id'], $today]);
  $entries = $stmt->fetchAll();
  
  echo json_encode([
    'ok' => true,
    'data' => $entries,
    'timestamp' => date('Y-m-d H:i:s')
  ]);
  exit;
}

// GET /api.php/entries - Historial de fichajes con paginación
if ($method === 'GET' && $path === '/entries') {
  $limit = (int)($_GET['limit'] ?? 30);
  $offset = (int)($_GET['offset'] ?? 0);
  $limit = min($limit, 100); // Máximo 100
  
  $pdo = get_pdo();
  
  $stmt = $pdo->prepare('
    SELECT id, user_id, date, entrada, salida, created_at
    FROM fichajes
    WHERE user_id = ?
    ORDER BY date DESC, entrada DESC
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
  exit;
}

// POST /api.php/entries/checkin - Registrar entrada
if ($method === 'POST' && $path === '/entries/checkin') {
  $pdo = get_pdo();
  $today = date('Y-m-d');
  $now = date('H:i:s');
  
  // Verificar si ya existe entrada hoy
  $stmt = $pdo->prepare('
    SELECT id FROM fichajes
    WHERE user_id = ? AND DATE(date) = ?
    ORDER BY entrada DESC
    LIMIT 1
  ');
  $stmt->execute([$user['id'], $today]);
  $lastEntry = $stmt->fetch();
  
  if ($lastEntry) {
    $stmt = $pdo->prepare('
      SELECT id, salida FROM fichajes
      WHERE id = ?
    ');
    $stmt->execute([$lastEntry['id']]);
    $check = $stmt->fetch();
    
    // Si ya tiene salida hoy, crear nueva entrada
    if ($check && $check['salida']) {
      // Crear nuevo
      $stmt = $pdo->prepare('
        INSERT INTO fichajes (user_id, date, entrada, created_at)
        VALUES (?, ?, ?, NOW())
      ');
      $stmt->execute([$user['id'], $today, $now]);
      $id = $pdo->lastInsertId();
    } else {
      // Error: ya tiene entrada sin salida
      http_response_code(400);
      echo json_encode([
        'ok' => false,
        'error' => 'already_checked_in',
        'message' => 'Ya tienes una entrada registrada sin salida'
      ]);
      exit;
    }
  } else {
    // Crear entrada nueva
    $stmt = $pdo->prepare('
      INSERT INTO fichajes (user_id, date, entrada, created_at)
      VALUES (?, ?, ?, NOW())
    ');
    $stmt->execute([$user['id'], $today, $now]);
    $id = $pdo->lastInsertId();
  }
  
  // Retornar entrada creada
  $stmt = $pdo->prepare('
    SELECT id, user_id, date, entrada, salida FROM fichajes WHERE id = ?
  ');
  $stmt->execute([$id]);
  $entry = $stmt->fetch();
  
  echo json_encode([
    'ok' => true,
    'data' => $entry,
    'message' => 'Entrada registrada'
  ]);
  exit;
}

// POST /api.php/entries/checkout - Registrar salida
if ($method === 'POST' && $path === '/entries/checkout') {
  $pdo = get_pdo();
  $today = date('Y-m-d');
  $now = date('H:i:s');
  
  // Obtener última entrada sin salida
  $stmt = $pdo->prepare('
    SELECT id FROM fichajes
    WHERE user_id = ? AND DATE(date) = ? AND salida IS NULL
    ORDER BY entrada DESC
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
    UPDATE fichajes
    SET salida = ?, updated_at = NOW()
    WHERE id = ?
  ');
  $stmt->execute([$now, $entry['id']]);
  
  // Retornar entrada actualizada
  $stmt = $pdo->prepare('
    SELECT id, user_id, date, entrada, salida FROM fichajes WHERE id = ?
  ');
  $stmt->execute([$entry['id']]);
  $updatedEntry = $stmt->fetch();
  
  echo json_encode([
    'ok' => true,
    'data' => $updatedEntry,
    'message' => 'Salida registrada'
  ]);
  exit;
}

// DELETE /api.php/entries/{id} - Eliminar fichaje
if ($method === 'DELETE' && preg_match('/^\/entries\/(\d+)$/', $path, $matches)) {
  $entryId = (int)$matches[1];
  $pdo = get_pdo();
  
  // Verificar que el fichaje pertenece al usuario actual
  $stmt = $pdo->prepare('
    SELECT id FROM fichajes
    WHERE id = ? AND user_id = ?
  ');
  $stmt->execute([$entryId, $user['id']]);
  
  if (!$stmt->fetch()) {
    http_response_code(404);
    echo json_encode([
      'ok' => false,
      'error' => 'not_found',
      'message' => 'Fichaje no encontrado'
    ]);
    exit;
  }
  
  // Eliminar
  $stmt = $pdo->prepare('DELETE FROM fichajes WHERE id = ?');
  $stmt->execute([$entryId]);
  
  echo json_encode([
    'ok' => true,
    'message' => 'Fichaje eliminado'
  ]);
  exit;
}
