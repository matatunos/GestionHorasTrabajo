<?php
require_once __DIR__ . '/auth.php';
require_admin();
require_once __DIR__ . '/config.php';

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cfg = [
      'site_name' => trim($_POST['site_name'] ?? 'GestionHoras'),
        'summer_start' => $_POST['summer_start'] ?? '',
        'summer_end' => $_POST['summer_end'] ?? '',
        'work_hours' => [
            'winter' => [
                'mon_thu' => floatval($_POST['winter_mon_thu'] ?? 8),
                'friday' => floatval($_POST['winter_friday'] ?? 6),
            ],
            'summer' => [
                'mon_thu' => floatval($_POST['summer_mon_thu'] ?? 7.5),
                'friday' => floatval($_POST['summer_friday'] ?? 6),
            ],
        ],
        'coffee_minutes' => intval($_POST['coffee_minutes'] ?? 15),
        'lunch_minutes' => intval($_POST['lunch_minutes'] ?? 30),
    ];
    // optional DB creds
    $dbu = trim($_POST['db_user'] ?? '');
    $dbp = trim($_POST['db_pass'] ?? '');
    if ($dbu !== '') {
        $cfg['db'] = ['user' => $dbu, 'pass' => $dbp];
    }

    // preserve existing config keys that aren't managed by this form
    $existing = get_config();
    $merged = array_replace_recursive($existing, $cfg);
    $pdo = null;
    if (function_exists('get_pdo')) $pdo = get_pdo();
    if ($pdo) {
      $pdo->exec("CREATE TABLE IF NOT EXISTS app_settings (
            name VARCHAR(191) PRIMARY KEY,
            value TEXT NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
      $stmt = $pdo->prepare('REPLACE INTO app_settings (name,value) VALUES (?,?)');
      $stmt->execute(['site_config', json_encode($merged, JSON_UNESCAPED_UNICODE)]);
      $msg = 'Configuración guardada en la base de datos.';
    } else {
      $msg = 'Error: no hay conexión con la base de datos para guardar la configuración.';
    }
}

$c = get_config();
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Configuración — GestionHoras</title>
  <link rel="stylesheet" href="styles.css">
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>
<div class="container">
  <div class="card">
    <?php if($msg):?><div class="ok"><?php echo htmlspecialchars($msg);?></div><?php endif; ?>
    <form method="post">
      <div class="form-wrapper config-card">
        <div class="form-grid">
          <div class="form-group">
            <label class="form-label">Nombre del sitio</label>
            <input class="form-control" name="site_name" value="<?php echo htmlspecialchars($c['site_name'] ?? 'GestionHoras'); ?>">
            <div class="form-help">Nombre que aparece en la cabecera.</div>
          </div>

          <div class="form-group">
            <label class="form-label">Inicio verano (MM-DD)</label>
            <input class="form-control" name="summer_start" value="<?php echo htmlspecialchars($c['summer_start']);?>">
          </div>

          <div class="form-group">
            <label class="form-label">Fin verano (MM-DD)</label>
            <input class="form-control" name="summer_end" value="<?php echo htmlspecialchars($c['summer_end']);?>">
          </div>

          <div class="form-group">
            <label class="form-label">Horas Lunes-Jueves (invierno)</label>
            <input class="form-control" name="winter_mon_thu" value="<?php echo htmlspecialchars($c['work_hours']['winter']['mon_thu']);?>">
          </div>
          <div class="form-group">
            <label class="form-label">Horas Viernes (invierno)</label>
            <input class="form-control" name="winter_friday" value="<?php echo htmlspecialchars($c['work_hours']['winter']['friday']);?>">
          </div>

          <div class="form-group">
            <label class="form-label">Horas Lunes-Jueves (verano)</label>
            <input class="form-control" name="summer_mon_thu" value="<?php echo htmlspecialchars($c['work_hours']['summer']['mon_thu']);?>">
          </div>
          <div class="form-group">
            <label class="form-label">Horas Viernes (verano)</label>
            <input class="form-control" name="summer_friday" value="<?php echo htmlspecialchars($c['work_hours']['summer']['friday']);?>">
          </div>

          <div class="form-group">
            <label class="form-label">Minutos Café</label>
            <input class="form-control" name="coffee_minutes" value="<?php echo htmlspecialchars($c['coffee_minutes']);?>">
          </div>
          <div class="form-group">
            <label class="form-label">Minutos Comida</label>
            <input class="form-control" name="lunch_minutes" value="<?php echo htmlspecialchars($c['lunch_minutes']);?>">
          </div>

          <div class="form-group">
            <label class="form-label">DB Usuario (opcional)</label>
            <input class="form-control" name="db_user" value="<?php echo htmlspecialchars($c['db']['user'] ?? '');?>>">
          </div>
          <div class="form-group">
            <label class="form-label">DB Contraseña (opcional)</label>
            <input class="form-control" name="db_pass" value="<?php echo htmlspecialchars($c['db']['pass'] ?? '');?>">
          </div>
        </div>

        <div class="form-actions" style="margin-top:12px;"><button class="btn-primary" type="submit">Guardar configuración</button><button class="btn-secondary" type="button" onclick="location.reload();">Cancelar</button></div>
      </div>
    </form>
    <div style="margin-top:12px;"><a class="btn" href="years.php">Gestionar años</a></div>
    <div class="footer small">Config stored in <strong>DB (app_settings.site_config)</strong></div>
  </div>
</div>
<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
