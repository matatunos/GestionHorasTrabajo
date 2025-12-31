<?php
require_once __DIR__ . '/auth.php';
require_admin();
require_once __DIR__ . '/db.php';

$pdo = get_pdo();
if (!$pdo) { echo "DB connection required"; exit; }

// ensure table with summer-specific overrides
$pdo->exec("CREATE TABLE IF NOT EXISTS year_configs (
    year INT PRIMARY KEY,
    mon_thu DOUBLE DEFAULT NULL,
    friday DOUBLE DEFAULT NULL,
    summer_mon_thu DOUBLE DEFAULT NULL,
    summer_friday DOUBLE DEFAULT NULL,
    coffee_minutes INT DEFAULT NULL,
    lunch_minutes INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['year'])) {
    $y = intval($_POST['year']);
    $mt = $_POST['mon_thu'] !== '' ? floatval($_POST['mon_thu']) : null;
    $fr = $_POST['friday'] !== '' ? floatval($_POST['friday']) : null;
    $smt = $_POST['summer_mon_thu'] !== '' ? floatval($_POST['summer_mon_thu']) : null;
    $sfr = $_POST['summer_friday'] !== '' ? floatval($_POST['summer_friday']) : null;
    $cm = $_POST['coffee_minutes'] !== '' ? intval($_POST['coffee_minutes']) : null;
    $lm = $_POST['lunch_minutes'] !== '' ? intval($_POST['lunch_minutes']) : null;

    $stmt = $pdo->prepare('REPLACE INTO year_configs (year, mon_thu, friday, summer_mon_thu, summer_friday, coffee_minutes, lunch_minutes) VALUES (?,?,?,?,?,?,?)');
    $stmt->execute([$y, $mt, $fr, $smt, $sfr, $cm, $lm]);
    header('Location: years.php?msg=' . urlencode('Año guardado')); exit;
}

  // Recompute cached summaries for the given year (admin action)
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'recalc' && isset($_POST['recalc_year'])) {
    $ry = intval($_POST['recalc_year']);
    $pdo->exec("CREATE TABLE IF NOT EXISTS app_settings (
            name VARCHAR(191) PRIMARY KEY,
            value TEXT NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // fetch all users
    $users = $pdo->query('SELECT id FROM users')->fetchAll();
    foreach ($users as $u) {
      $uid = $u['id'];
      // compute monthly totals for this user
      $months = array_fill(1,12, ['worked'=>0,'expected'=>0]);
      $startTs = strtotime("$ry-01-01"); $endTs = strtotime("$ry-12-31");
      $cfg = get_year_config($ry);
      for ($ts = $startTs; $ts <= $endTs; $ts += 86400) {
        $d = date('Y-m-d', $ts); $m = intval(date('n', $ts));
        // load entry for user
        $est = $pdo->prepare('SELECT * FROM entries WHERE user_id = ? AND date = ? LIMIT 1');
        $est->execute([$uid,$d]);
        $e = $est->fetch() ?: ['date'=>$d];
        // load holidays visible to this user
        $hstmt = $pdo->prepare('SELECT date,label,type,annual,user_id FROM holidays WHERE (YEAR(date)=? OR annual=1) AND (user_id IS NULL OR user_id = ?)');
        $hstmt->execute([$ry,$uid]);
        $hols = [];
        foreach ($hstmt->fetchAll() as $hh) {
          $kd = $hh['date']; if (!empty($hh['annual'])) $kd = sprintf('%04d-%s', $ry, substr($hh['date'],5)); $hols[$kd] = $hh;
        }
        if (isset($hols[$d])) { $e['is_holiday']=true; $e['special_type']=$hols[$d]['type']; }
        $calc = compute_day($e, $cfg);
        $months[$m]['worked'] += $calc['worked_minutes'] ?? 0;
        $months[$m]['expected'] += $calc['expected_minutes'] ?? 0;
      }
      // store in app_settings as JSON
      $key = 'summary_' . $uid . '_' . $ry;
      $stmt = $pdo->prepare('REPLACE INTO app_settings (name,value) VALUES (?,?)');
      $stmt->execute([$key, json_encode($months)]);
    }
    header('Location: years.php?msg=' . urlencode('Recalculación completada')); exit;
  }

$rows = $pdo->query('SELECT * FROM year_configs ORDER BY year DESC')->fetchAll();
$msg = $_GET['msg'] ?? '';
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Años — Configuración</title>
  <link rel="stylesheet" href="styles.css">
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>
<div class="container">
  <div class="card">
    <h3>Configurar año</h3>
    <?php if ($msg): ?><div class="alert"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>
    <form method="post" class="form-wrapper">
      <div class="form-grid">
        <div class="form-group"><label class="form-label">Año</label><input class="form-control" name="year" required></div>
        <div class="form-group"><label class="form-label">Horas Invierno (mon-thu)</label><input class="form-control" name="mon_thu" placeholder="ej. 8.0"></div>
        <div class="form-group"><label class="form-label">Horas Invierno Viernes</label><input class="form-control" name="friday" placeholder="ej. 6.0"></div>
        <div class="form-group"><label class="form-label">Horas Verano (mon-thu)</label><input class="form-control" name="summer_mon_thu" placeholder="ej. 7.5"></div>
        <div class="form-group"><label class="form-label">Horas Verano Viernes</label><input class="form-control" name="summer_friday" placeholder="ej. 6.0"></div>
        <div class="form-group"><label class="form-label">Minutos café</label><input class="form-control" name="coffee_minutes" placeholder="15"></div>
        <div class="form-group"><label class="form-label">Minutos comida</label><input class="form-control" name="lunch_minutes" placeholder="30"></div>
      </div>
      <div class="form-actions" style="margin-top:12px;"><button class="btn-primary" type="submit">Guardar año</button></div>
    </form>

    <div style="margin-top:12px;">
      <form method="post" class="form-wrapper" style="display:flex;gap:8px;align-items:center;">
        <input type="hidden" name="action" value="recalc">
        <label class="form-label">Recalcular estadísticas para año
          <select name="recalc_year">
            <?php for ($yy = date('Y')-2; $yy <= date('Y')+2; $yy++): ?>
              <option value="<?php echo $yy; ?>"><?php echo $yy; ?></option>
            <?php endfor; ?>
          </select>
        </label>
        <button class="btn-primary" type="submit">Recalcular estadísticas</button>
      </form>
    </div>

    <h3>Listado</h3>
    <div class="table-responsive">
      <form method="post" class="form-wrapper">
        <div class="form-grid">
          <div class="form-group"><label class="form-label">Año</label><input class="form-control" name="year" required></div>
          <div class="form-group"><label class="form-label">Horas Invierno (mon-thu)</label><input class="form-control" name="mon_thu" placeholder="ej. 8.0"></div>
          <div class="form-group"><label class="form-label">Horas Invierno Viernes</label><input class="form-control" name="friday" placeholder="ej. 6.0"></div>
          <div class="form-group"><label class="form-label">Horas Verano (mon-thu)</label><input class="form-control" name="summer_mon_thu" placeholder="ej. 7.5"></div>
          <div class="form-group"><label class="form-label">Horas Verano Viernes</label><input class="form-control" name="summer_friday" placeholder="ej. 6.0"></div>
          <div class="form-group"><label class="form-label">Minutos café</label><input class="form-control" name="coffee_minutes" placeholder="15"></div>
          <div class="form-group"><label class="form-label">Minutos comida</label><input class="form-control" name="lunch_minutes" placeholder="30"></div>
        </div>
        <div class="form-actions" style="margin-top:12px;"><button class="btn-primary" type="submit">Guardar año</button></div>
      </form>
      <table class="sheet">
        <thead>
          <tr><th>Año</th><th>Inv Mon-Thu</th><th>Inv Fri</th><th>Ver Mon-Thu</th><th>Ver Fri</th><th>Café</th><th>Comida</th><th>Creado</th></tr>
        </thead>
        <tbody>
        <?php foreach($rows as $r): ?>
          <tr>
            <td><?php echo $r['year']?></td>
            <td><?php echo $r['mon_thu']?></td>
            <td><?php echo $r['friday']?></td>
            <td><?php echo $r['summer_mon_thu']?></td>
            <td><?php echo $r['summer_friday']?></td>
            <td><?php echo $r['coffee_minutes']?></td>
            <td><?php echo $r['lunch_minutes']?></td>
            <td><?php echo $r['created_at']?></td>
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
