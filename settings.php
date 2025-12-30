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

    $dir = __DIR__ . '/data';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $f = $dir . '/config.json';
    // preserve existing config keys that aren't managed by this form
    $existing = [];
    if (file_exists($f)) {
      $existing = json_decode(file_get_contents($f), true) ?: [];
    }
    $merged = array_replace_recursive($existing, $cfg);
    file_put_contents($f, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    @chown($f, 'www-data');
    $msg = 'Configuración guardada.';
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
      <div class="config-grid">
        <div class="field">
          <label>Nombre del sitio</label>
          <input name="site_name" value="<?php echo htmlspecialchars($c['site_name'] ?? 'GestionHoras'); ?>">
        </div>
        <div class="field">
          <label>Inicio verano (MM-DD)</label>
          <input name="summer_start" value="<?php echo htmlspecialchars($c['summer_start']);?>">
        </div>
        <div class="field">
          <label>Fin verano (MM-DD)</label>
          <input name="summer_end" value="<?php echo htmlspecialchars($c['summer_end']);?>">
        </div>

        <div class="field">
          <label>Horas Lunes-Jueves (invierno)</label>
          <input name="winter_mon_thu" value="<?php echo htmlspecialchars($c['work_hours']['winter']['mon_thu']);?>">
        </div>
        <div class="field">
          <label>Horas Viernes (invierno)</label>
          <input name="winter_friday" value="<?php echo htmlspecialchars($c['work_hours']['winter']['friday']);?>">
        </div>

        <div class="field">
          <label>Horas Lunes-Jueves (verano)</label>
          <input name="summer_mon_thu" value="<?php echo htmlspecialchars($c['work_hours']['summer']['mon_thu']);?>">
        </div>
        <div class="field">
          <label>Horas Viernes (verano)</label>
          <input name="summer_friday" value="<?php echo htmlspecialchars($c['work_hours']['summer']['friday']);?>">
        </div>

        <div class="field">
          <label>Minutos Café</label>
          <input name="coffee_minutes" value="<?php echo htmlspecialchars($c['coffee_minutes']);?>">
        </div>
        <div class="field">
          <label>Minutos Comida</label>
          <input name="lunch_minutes" value="<?php echo htmlspecialchars($c['lunch_minutes']);?>">
        </div>

        <div class="field">
          <label>DB Usuario (opcional)</label>
          <input name="db_user" value="<?php echo htmlspecialchars($c['db']['user'] ?? '');?>">
        </div>
        <div class="field">
          <label>DB Contraseña (opcional)</label>
          <input name="db_pass" value="<?php echo htmlspecialchars($c['db']['pass'] ?? '');?>">
        </div>
      </div>

      <div class="actions"><button type="submit">Guardar configuración</button></div>
    </form>
    <div class="footer small">Config stored in <strong>data/config.json</strong></div>
  </div>
</div>
<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
