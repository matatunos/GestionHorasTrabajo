<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';

// Dedupe entries by (user_id, date). Keeps the "best" row per day and deletes the rest.
// Best row heuristic: most filled time fields + note present; tie-breaker by higher id.

$isCli = (PHP_SAPI === 'cli');

$apply = false;
$targetUserId = null;

if ($isCli) {
  $args = $argv;
  array_shift($args);
  foreach ($args as $a) {
    if ($a === '--apply') $apply = true;
    if (preg_match('/^--user-id=(\d+)$/', $a, $m)) $targetUserId = intval($m[1]);
  }
} else {
  require_admin();
  $apply = !empty($_GET['apply']);
  if (!empty($_GET['user_id'])) $targetUserId = intval($_GET['user_id']);
}

$pdo = get_pdo();
if (!$pdo) {
  http_response_code(500);
  echo "DB connection failed\n";
  exit;
}

function row_score(array $r): int {
  $score = 0;
  foreach (['start','coffee_out','coffee_in','lunch_out','lunch_in','end'] as $k) {
    if (!empty($r[$k])) $score += 10;
  }
  if (!empty($r['note'])) $score += 1;
  return $score;
}

// Find duplicate groups
$sql = 'SELECT user_id, date, COUNT(*) AS c FROM entries';
$params = [];
if ($targetUserId !== null) {
  $sql .= ' WHERE user_id = ?';
  $params[] = $targetUserId;
}
$sql .= ' GROUP BY user_id, date HAVING c > 1 ORDER BY user_id ASC, date ASC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$dupes = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($isCli) {
  echo "Duplicate groups found: " . count($dupes) . "\n";
} else {
  header('Content-Type: text/html; charset=utf-8');
  echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
  echo '<title>Deduplicar entries</title><link rel="stylesheet" href="../styles.css"></head><body>';
  echo '<div class="container"><div class="card">';
  echo '<h1>Deduplicar fichajes</h1>';
  echo '<p>Grupos duplicados encontrados: <strong>' . intval(count($dupes)) . '</strong></p>';
}

if (empty($dupes)) {
  if (!$isCli) {
    echo '<div class="alert alert-success">No hay duplicados.</div>';
    echo '</div></div></body></html>';
  }
  exit;
}

$deletedTotal = 0;
$keptTotal = 0;
$toDelete = [];
$toKeep = [];

foreach ($dupes as $g) {
  $uid = intval($g['user_id']);
  $date = $g['date'];

  $stmt = $pdo->prepare('SELECT * FROM entries WHERE user_id = ? AND date = ? ORDER BY id DESC');
  $stmt->execute([$uid, $date]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  if (count($rows) <= 1) continue;

  usort($rows, function($a, $b) {
    $sa = row_score($a);
    $sb = row_score($b);
    if ($sa !== $sb) return $sb <=> $sa;
    return intval($b['id']) <=> intval($a['id']);
  });

  $keep = $rows[0];
  $toKeep[] = ['id' => intval($keep['id']), 'user_id' => $uid, 'date' => $date];

  for ($i = 1; $i < count($rows); $i++) {
    $rid = intval($rows[$i]['id']);
    $toDelete[] = $rid;
  }
}

if (!$apply) {
  if ($isCli) {
    echo "Would keep " . count($toKeep) . " rows and delete " . count($toDelete) . " rows.\n";
    echo "Re-run with --apply to execute deletions.\n";
    exit;
  }

  $qs = [];
  if ($targetUserId !== null) $qs[] = 'user_id=' . urlencode((string)$targetUserId);
  $qs[] = 'apply=1';
  $url = htmlspecialchars($_SERVER['PHP_SELF'] . '?' . implode('&', $qs));

  echo '<div class="alert alert-warning">Modo previsualización: aún no se ha borrado nada.</div>';
  echo '<p><a class="btn btn-danger" href="' . $url . '">Aplicar borrado de duplicados</a></p>';
  echo '<p class="muted">Se conservará la fila más completa por día (más campos de hora rellenos). En caso de empate, se conserva la de id más alto.</p>';
  echo '</div></div></body></html>';
  exit;
}

// Apply deletions in a transaction
$pdo->beginTransaction();
try {
  if (!empty($toDelete)) {
    // Chunk deletes to avoid huge IN() lists
    $chunks = array_chunk($toDelete, 500);
    foreach ($chunks as $chunk) {
      $placeholders = implode(',', array_fill(0, count($chunk), '?'));
      $del = $pdo->prepare('DELETE FROM entries WHERE id IN (' . $placeholders . ')');
      $del->execute(array_values($chunk));
      $deletedTotal += $del->rowCount();
    }
  }
  $pdo->commit();
} catch (Throwable $e) {
  $pdo->rollBack();
  if ($isCli) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
  } else {
    echo '<div class="alert alert-danger">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    echo '</div></div></body></html>';
  }
  exit(1);
}

if ($isCli) {
  echo "Deleted rows: $deletedTotal\n";
} else {
  echo '<div class="alert alert-success">Duplicados eliminados. Filas borradas: <strong>' . intval($deletedTotal) . '</strong></div>';
  echo '<p><a class="btn btn-secondary" href="../index.php">Volver al registro</a></p>';
  echo '</div></div></body></html>';
}
