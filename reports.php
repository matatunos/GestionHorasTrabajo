<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib.php';
require_admin();
$pdo = get_pdo();

$year = intval($_GET['year'] ?? date('Y'));
$month = intval($_GET['month'] ?? date('n'));

// Get all users
$users = $pdo->query('SELECT id, username, is_admin FROM users ORDER BY username')->fetchAll(PDO::FETCH_ASSOC);

// Calculate stats for each user
$stats = [];
foreach ($users as $user) {
  $userId = $user['id'];
  
  // Get entries for this user and year
  $stmt = $pdo->prepare('SELECT * FROM entries WHERE user_id = ? AND YEAR(date) = ? ORDER BY date ASC');
  $stmt->execute([$userId, $year]);
  $entries = [];
  foreach ($stmt->fetchAll() as $r) {
    $entries[$r['date']] = $r;
  }
  
  // Count days entered
  $daysWithEntries = count($entries);
  
  // Calculate totals for this month
  $monthStart = sprintf('%04d-%02d-01', $year, $month);
  $monthEnd = date('Y-m-t', strtotime($monthStart));
  
  $totalWorked = 0;
  $totalExpected = 0;
  $daysInMonth = 0;
  $lastEntry = null;
  
  $config = get_year_config($year, $userId);
  
  for ($d = new DateTimeImmutable($monthStart); $d <= new DateTimeImmutable($monthEnd); $d = $d->modify('+1 day')) {
    $dateStr = $d->format('Y-m-d');
    $entry = $entries[$dateStr] ?? ['date' => $dateStr];
    
    $calc = compute_day($entry, $config);
    if ($calc['worked'] !== null) {
      $totalWorked += $calc['worked'];
      $totalExpected += $calc['expected'];
      $daysInMonth++;
      $lastEntry = $dateStr;
    }
  }
  
  $stats[] = [
    'user' => $user,
    'days_with_entries' => $daysWithEntries,
    'days_this_month' => $daysInMonth,
    'worked_hours' => round($totalWorked / 60, 2),
    'expected_hours' => round($totalExpected / 60, 2),
    'balance_hours' => round(($totalWorked - $totalExpected) / 60, 2),
    'last_entry' => $lastEntry,
  ];
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Reportes</title>
  <link rel="stylesheet" href="styles.css">
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>
<div class="container">
  <div class="card">
    <h2>Reportes de Usuarios</h2>
    
    <form method="get" class="row-form" style="margin-bottom: 1.5rem;">
      <label class="form-label">Mes <select class="form-control" name="month">
        <?php for ($m = 1; $m <= 12; $m++): ?>
          <option value="<?php echo $m; ?>" <?php echo $m === $month ? 'selected' : ''; ?>><?php echo strftime('%B', mktime(0,0,0,$m,1)); ?></option>
        <?php endfor; ?>
      </select></label>
      <label class="form-label">Año <input class="form-control" type="number" name="year" value="<?php echo $year; ?>" min="2000" max="2099"></label>
      <button class="btn btn-primary" type="submit">Actualizar</button>
    </form>

    <div class="table-responsive">
      <table class="sheet">
        <thead>
          <tr>
            <th>Usuario</th>
            <th>Admin</th>
            <th>Días con fichaje (año)</th>
            <th>Días este mes</th>
            <th>Horas trabajadas</th>
            <th>Horas esperadas</th>
            <th>Balance</th>
            <th>Última entrada</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($stats as $s): ?>
            <tr>
              <td><?php echo htmlspecialchars($s['user']['username']); ?></td>
              <td><?php echo $s['user']['is_admin'] ? '✓ Sí' : ''; ?></td>
              <td><?php echo $s['days_with_entries']; ?></td>
              <td><?php echo $s['days_this_month']; ?></td>
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
</body>
</html>
