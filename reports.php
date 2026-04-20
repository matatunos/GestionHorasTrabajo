<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib.php';
require_admin();
$pdo = get_pdo();

// Shared function to generate stats for all users for a given year/month
function generate_user_stats(PDO $pdo, int $year): array {
  $users = $pdo->query('SELECT id, username, is_admin FROM users ORDER BY username')->fetchAll(PDO::FETCH_ASSOC);
  $stats = [];
  foreach ($users as $user) {
    $userId = $user['id'];
    // Load entries for user for the year
    $stmt = $pdo->prepare('SELECT * FROM entries WHERE user_id = ? AND YEAR(date) = ? ORDER BY date ASC');
    $stmt->execute([$userId, $year]);
    $entries = [];
    foreach ($stmt->fetchAll() as $r) { $entries[$r['date']] = $r; }

    $daysWithEntries = count($entries);
    $totalWorked = 0; $totalExpected = 0; $lastEntry = null;
    $config = get_year_config($year, $userId);

    // Obtener festivos para el año (incluye anuales)
    // Cargar festivos del usuario (sistema: user_id IS NULL, y propios: user_id = $userId)
    // Excluir guardias: son días laborables con horas esperadas normales
    $holidays = [];
    $hstmt = $pdo->prepare('SELECT date, annual, type FROM holidays WHERE (YEAR(date)=? OR annual=1) AND (user_id IS NULL OR user_id=?)');
    $hstmt->execute([$year, $userId]);
    foreach ($hstmt->fetchAll() as $h) {
      if (($h['type'] ?? 'holiday') === 'guardia') continue; // guardia = día laborable normal
      $hd = $h['date'];
      if (!empty($h['annual'])) {
        $hd = sprintf('%04d-%s', $year, substr($h['date'],5));
      }
      $holidays[$hd] = true;
    }

    // Recorrer todos los días del año
    $start = new DateTimeImmutable("$year-01-01");
    $end = new DateTimeImmutable("$year-12-31");
    for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
      $dateStr = $d->format('Y-m-d');
      $dow = intval($d->format('N')); // 6=sábado, 7=domingo
      if ($dow >= 6) continue; // Excluir fines de semana
      if (isset($holidays[$dateStr])) continue; // Excluir festivos/vacaciones
      $entry = $entries[$dateStr] ?? ['date' => $dateStr];
      $calc = compute_day($entry, $config);
      $worked_display = $calc['worked_minutes_for_display'] ?? null;
      $expected = intval($calc['expected_empresa_minutes'] ?? 0);
      if ($expected > 0) {
        $totalExpected += $expected;
      }
      if ($worked_display !== null) {
        $totalWorked += intval($worked_display);
        $lastEntry = $dateStr;
      }
    }

    $stats[] = [
      'user' => $user,
      'days_with_entries' => $daysWithEntries,
      'worked_hours' => round($totalWorked / 60, 2),
      'expected_hours' => round($totalExpected / 60, 2),
      'balance_hours' => round(($totalWorked - $totalExpected) / 60, 2),
      'last_entry' => $lastEntry,
    ];
  }
  return $stats;
}

// Handle AJAX requests (return tbody HTML)
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
  $year = intval($_GET['year'] ?? date('Y'));
  $stats = generate_user_stats($pdo, $year);
  foreach ($stats as $s) {
    $balance = $s['balance_hours'];
    $balanceClass = $balance > 0 ? '--good' : ($balance < 0 ? '--bad' : '--ok');
    echo '<tr>';
    echo '<td>' . htmlspecialchars($s['user']['username']) . '</td>';
    echo '<td>' . ($s['user']['is_admin'] ? '✓' : '') . '</td>';
    echo '<td>' . $s['days_with_entries'] . '</td>';
    echo '<td>' . $s['worked_hours'] . 'h</td>';
    echo '<td>' . $s['expected_hours'] . 'h</td>';
    echo '<td><span class="pill ' . $balanceClass . '">' . ($balance > 0 ? '↑' : ($balance < 0 ? '↓' : '•')) . ' ' . abs($s['balance_hours']) . 'h</span></td>';
    echo '<td>' . ($s['last_entry'] ? date('d/m/Y', strtotime($s['last_entry'])) : '—') . '</td>';
    echo '</tr>';
  }
  exit;
}

$year = intval($_GET['year'] ?? date('Y'));

// Export CSV if requested
if (!empty($_GET['export']) && $_GET['export'] === 'csv') {
  $stats = generate_user_stats($pdo, $year);
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="report_' . $year . '.csv"');
  $out = fopen('php://output', 'w');
  fputcsv($out, ['username','is_admin','days_with_entries','worked_hours','expected_hours','balance_hours','last_entry']);
  foreach ($stats as $s) {
    fputcsv($out, [
      $s['user']['username'], $s['user']['is_admin'] ? 1 : 0, $s['days_with_entries'], $s['worked_hours'], $s['expected_hours'], $s['balance_hours'], $s['last_entry'] ?? ''
    ]);
  }
  fclose($out);
  exit;
}

// Generate stats for initial page render
$stats = generate_user_stats($pdo, $year);
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Reportes</title>
  <link rel="icon" type="image/svg+xml" href="images/favicon.svg">
  <link rel="stylesheet" href="styles.css?v=<?php echo filemtime(__DIR__.'/styles.css'); ?>">
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>
<div class="container">
  <div class="card">
    <h2>Reportes de Usuarios</h2>
    
    <form class="row-form" style="margin-bottom: 1.5rem;" id="filters-form">
      <label class="form-label">Año <input class="form-control" id="year-input" type="number" name="year" value="<?php echo $year; ?>" min="2000" max="2099"></label>
    </form>
    <div style="margin-bottom:1rem;">
      <button id="export-csv-btn" class="btn btn-secondary">Exportar CSV</button>
    </div>

    <div class="table-responsive">
      <table class="sheet">
        <thead>
          <tr>
            <th>Usuario</th>
            <th>Admin</th>
            <th>Días con fichaje</th>
            <th>Horas trabajadas</th>
            <th>Horas esperadas</th>
            <th>Balance anual</th>
            <th>Última entrada</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($stats as $s): ?>
            <tr>
              <td><?php echo htmlspecialchars($s['user']['username']); ?></td>
              <td><?php echo $s['user']['is_admin'] ? '✓ Sí' : ''; ?></td>
              <td><?php echo $s['days_with_entries']; ?></td>
              <td><?php echo $s['worked_hours']; ?></td>
              <td><?php echo $s['expected_hours']; ?></td>
              <td class="<?php echo $s['balance_hours'] >= 0 ? 'balance--good' : 'balance--bad'; ?>">
                <span class="pill"><?php echo $s['balance_hours'] >= 0 ? '↑' : '↓'; ?> <?php echo abs($s['balance_hours']); ?>h</span>
              </td>
              <td><?php echo $s['last_entry'] ? $s['last_entry'] : '-'; ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php include __DIR__ . '/footer.php'; ?>
<script>
  const yearInput = document.getElementById('year-input');
  const tbody = document.querySelector('table.sheet tbody');
  
  function loadStats() {
    const year = yearInput.value;
    fetch(`?year=${year}`, {
      headers: {'X-Requested-With': 'XMLHttpRequest'}
    })
    .then(r => r.text())
    .then(html => {
      tbody.innerHTML = html;
    })
    .catch(err => console.error('Error loading stats:', err));
  }
  
  yearInput.addEventListener('change', loadStats);
  const exportBtn = document.getElementById('export-csv-btn');
  if (exportBtn) {
    exportBtn.addEventListener('click', function(){
      const year = yearInput.value;
      window.location.href = `?export=csv&year=${encodeURIComponent(year)}`;
    });
  }
</script>
</body>
</html>
