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
    header('Location: years.php'); exit;
}

$rows = $pdo->query('SELECT * FROM year_configs ORDER BY year DESC')->fetchAll();
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
    <form method="post" class="config-grid">
      <div class="field"><label>Año</label><input name="year" required></div>
      <div class="field"><label>Horas Invierno (mon-thu)</label><input name="mon_thu" placeholder="ej. 8.0"></div>
      <div class="field"><label>Horas Invierno Viernes</label><input name="friday" placeholder="ej. 6.0"></div>
      <div class="field"><label>Horas Verano (mon-thu)</label><input name="summer_mon_thu" placeholder="ej. 7.5"></div>
      <div class="field"><label>Horas Verano Viernes</label><input name="summer_friday" placeholder="ej. 6.0"></div>
      <div class="field"><label>Minutos café</label><input name="coffee_minutes" placeholder="15"></div>
      <div class="field"><label>Minutos comida</label><input name="lunch_minutes" placeholder="30"></div>
      <div class="actions"><button class="btn btn-primary" type="submit">Guardar año</button></div>
    </form>

    <h3>Listado</h3>
    <div class="table-responsive">
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
