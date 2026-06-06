<?php
require_once __DIR__ . '/auth.php';
require_admin();
require_once __DIR__ . '/config.php';

$msg = '';

// capture runtime errors to help debug 500 responses
set_error_handler(function($sev, $msgText, $file, $line){
  $d = ['type'=>'php_error','sev'=>$sev,'msg'=>$msgText,'file'=>$file,'line'=>$line,'post'=>$_POST,'ts'=>date('c')];
  error_log(json_encode($d, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
});
set_exception_handler(function($e){
  $d = ['type'=>'exception','msg'=>$e->getMessage(),'file'=>$e->getFile(),'line'=>$e->getLine(),'trace'=>$e->getTraceAsString(),'post'=>$_POST,'ts'=>date('c')];
  error_log(json_encode($d, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
  if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH']==='XMLHttpRequest') {
    header('Content-Type: application/json'); http_response_code(500); echo json_encode(['ok'=>false,'error'=>'exception','msg'=>$e->getMessage()]);
  }
});
register_shutdown_function(function(){
  $err = error_get_last();
    if ($err) {
    $d = ['type'=>'shutdown','err'=>$err,'post'=>$_POST,'ts'=>date('c')];
    error_log(json_encode($d, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
  }
});

// Admin-triggered recalculation (moved from years.php)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'recalc') {
  require_once __DIR__ . '/db.php';
  require_once __DIR__ . '/lib.php';
  $pdo = null; if (function_exists('get_pdo')) $pdo = get_pdo();
  if (!$pdo) {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
      header('Content-Type: application/json'); http_response_code(500); echo json_encode(['ok'=>false,'error'=>'no_db']); exit;
    }
    header('Location: settings.php?msg=' . urlencode('No DB connection for recalc')); exit;
  }
  try {
    $ry = intval($_POST['recalc_year'] ?? date('Y'));
    $pdo->exec("CREATE TABLE IF NOT EXISTS app_settings (
            name VARCHAR(191) PRIMARY KEY,
            value TEXT NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $users = $pdo->query('SELECT id FROM users')->fetchAll();
    foreach ($users as $u) {
      $uid = $u['id'];
      $months = array_fill(1,12, ['worked'=>0,'expected'=>0]);
      $cfg = get_year_config($ry);
      $dt = new DateTimeImmutable("$ry-01-01");
      $end = new DateTimeImmutable("$ry-12-31");
      for ($cur = $dt; $cur <= $end; $cur = $cur->modify('+1 day')) {
        $d = $cur->format('Y-m-d'); $m = intval($cur->format('n'));
        $est = $pdo->prepare('SELECT * FROM entries WHERE user_id = ? AND date = ? LIMIT 1');
        $est->execute([$uid,$d]);
        $e = $est->fetch() ?: ['date'=>$d];
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
      $key = 'summary_' . $uid . '_' . $ry;
      $stmt = $pdo->prepare('REPLACE INTO app_settings (name,value) VALUES (?,?)');
      $stmt->execute([$key, json_encode($months)]);
    }
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
      header('Content-Type: application/json'); echo json_encode(['ok' => true]); exit;
    }
    header('Location: settings.php?msg=' . urlencode('Recalculación completada')); exit;
  } catch (Throwable $e) {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
      header('Content-Type: application/json'); http_response_code(500); echo json_encode(['ok'=>false,'error'=>'server_exception','msg'=>$e->getMessage()]); exit;
    }
    header('Location: settings.php?msg=' . urlencode('Error en recálculo: ' . $e->getMessage())); exit;
  }
}

// YEAR CONFIG CRUD: handle specific year-config POSTs before site-config save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['save_year_config']) || isset($_POST['delete_year_config']))) {
  $pdo = null; if (function_exists('get_pdo')) $pdo = get_pdo();
  if ($pdo) {
    if (isset($_POST['save_year_config'])) {
      // removed temp-file debug write; log via error_log instead
      error_log('SAVE_YEAR_CONFIG POST: ' . json_encode($_POST));
      $parse_hours = function($v, $default) {
        if (!isset($v) || $v === '') return $default;
        $v = trim($v);
        if ($v === '') return $default;
        if (strpos($v, ':') !== false) {
          $parts = explode(':', $v);
          $h = intval($parts[0]);
          $m = isset($parts[1]) ? intval($parts[1]) : 0;
          return $h + ($m / 60.0);
        }
        $v = str_replace(',', '.', $v);
        return floatval($v);
      };
      $parse_minutes = function($v, $default) {
        if (!isset($v) || $v === '') return $default;
        $v = trim($v);
        if ($v === '') return $default;
        if (strpos($v, ':') !== false) {
          $parts = explode(':', $v);
          $h = intval($parts[0]);
          $m = isset($parts[1]) ? intval($parts[1]) : 0;
          return $h * 60 + $m;
        }
        $only = preg_replace('/[^0-9]/','',$v);
        return intval($only ?: $default);
      };
      $yy = intval($_POST['yearcfg_year'] ?? 0);
      if ($yy > 0) {
        try {
          $mt = $parse_hours($_POST['yearcfg_mon_thu'] ?? null, 8.0);
          $fr = $parse_hours($_POST['yearcfg_friday'] ?? null, 6.0);
          $smt = $parse_hours($_POST['yearcfg_summer_mon_thu'] ?? null, 7.5);
          $sfr = $parse_hours($_POST['yearcfg_summer_friday'] ?? null, 6.0);
          $cm = $parse_minutes($_POST['yearcfg_coffee_minutes'] ?? null, 15);
          $lm = $parse_minutes($_POST['yearcfg_lunch_minutes'] ?? null, 30);
          $expected_daily_hours_winter = $parse_hours($_POST['yearcfg_expected_daily_hours_winter'] ?? null, 7.65);
          $expected_daily_hours_summer = $parse_hours($_POST['yearcfg_expected_daily_hours_summer'] ?? null, 7.0);
          $pdo->exec("CREATE TABLE IF NOT EXISTS year_configs (
            year INT PRIMARY KEY,
            mon_thu DOUBLE DEFAULT NULL,
            friday DOUBLE DEFAULT NULL,
            summer_mon_thu DOUBLE DEFAULT NULL,
            summer_friday DOUBLE DEFAULT NULL,
            expected_daily_hours_winter DOUBLE DEFAULT NULL,
            expected_daily_hours_summer DOUBLE DEFAULT NULL,
            coffee_minutes INT DEFAULT NULL,
            lunch_minutes INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
          try { $pdo->exec("ALTER TABLE year_configs ADD COLUMN IF NOT EXISTS expected_daily_hours_winter DOUBLE DEFAULT NULL"); } catch(Throwable $e){ try{ $pdo->exec("ALTER TABLE year_configs ADD COLUMN expected_daily_hours_winter DOUBLE DEFAULT NULL"); }catch(Throwable $e2){} }
          try { $pdo->exec("ALTER TABLE year_configs ADD COLUMN IF NOT EXISTS expected_daily_hours_summer DOUBLE DEFAULT NULL"); } catch(Throwable $e){ try{ $pdo->exec("ALTER TABLE year_configs ADD COLUMN expected_daily_hours_summer DOUBLE DEFAULT NULL"); }catch(Throwable $e2){} }
          try { $pdo->exec("ALTER TABLE year_configs ADD COLUMN IF NOT EXISTS mon_thu DOUBLE DEFAULT NULL"); } catch(Throwable $e){ try{ $pdo->exec("ALTER TABLE year_configs ADD COLUMN mon_thu DOUBLE DEFAULT NULL"); }catch(Throwable $e2){} }
          try { $pdo->exec("ALTER TABLE year_configs ADD COLUMN IF NOT EXISTS friday DOUBLE DEFAULT NULL"); } catch(Throwable $e){ try{ $pdo->exec("ALTER TABLE year_configs ADD COLUMN friday DOUBLE DEFAULT NULL"); }catch(Throwable $e2){} }
          try { $pdo->exec("ALTER TABLE year_configs ADD COLUMN IF NOT EXISTS summer_mon_thu DOUBLE DEFAULT NULL"); } catch(Throwable $e){ try{ $pdo->exec("ALTER TABLE year_configs ADD COLUMN summer_mon_thu DOUBLE DEFAULT NULL"); }catch(Throwable $e2){} }
          try { $pdo->exec("ALTER TABLE year_configs ADD COLUMN IF NOT EXISTS summer_friday DOUBLE DEFAULT NULL"); } catch(Throwable $e){ try{ $pdo->exec("ALTER TABLE year_configs ADD COLUMN summer_friday DOUBLE DEFAULT NULL"); }catch(Throwable $e2){} }
          try { $pdo->exec("ALTER TABLE year_configs ADD COLUMN IF NOT EXISTS coffee_minutes INT DEFAULT NULL"); } catch(Throwable $e){ try{ $pdo->exec("ALTER TABLE year_configs ADD COLUMN coffee_minutes INT DEFAULT NULL"); }catch(Throwable $e2){} }
          try { $pdo->exec("ALTER TABLE year_configs ADD COLUMN IF NOT EXISTS lunch_minutes INT DEFAULT NULL"); } catch(Throwable $e){ try{ $pdo->exec("ALTER TABLE year_configs ADD COLUMN lunch_minutes INT DEFAULT NULL"); }catch(Throwable $e2){} }
          $stmt = $pdo->prepare('REPLACE INTO year_configs (year, mon_thu, friday, summer_mon_thu, summer_friday, expected_daily_hours_winter, expected_daily_hours_summer, coffee_minutes, lunch_minutes) VALUES (?,?,?,?,?,?,?,?,?)');
          $stmt->execute([$yy, $mt, $fr, $smt, $sfr, $expected_daily_hours_winter, $expected_daily_hours_summer, $cm, $lm]);
            $msg = 'Configuración del año guardada.';
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
              header('Content-Type: application/json'); echo json_encode(['ok'=>true]); exit;
            }
        } catch (Exception $e) {
          error_log('Error creando configuración de año: ' . $e->getMessage());
          $msg = 'Error al crear la configuración del año: ' . $e->getMessage();
          if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
            header('Content-Type: application/json'); echo json_encode(['error' => $e->getMessage()]); exit;
          }
        }
      } else {
        $msg = 'Año inválido.';
      }
    } elseif (isset($_POST['delete_year_config'])) {
      // removed temp-file debug write; log via error_log instead
      error_log('DELETE_YEAR_CONFIG POST: ' . json_encode($_POST));
      $yy = intval($_POST['delete_year_config']);
      if ($yy > 0) {
        try {
          $stmt = $pdo->prepare('DELETE FROM year_configs WHERE year = ?');
          $stmt->execute([$yy]);
          $msg = 'Configuración del año eliminada.';
          if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
            header('Content-Type: application/json'); echo json_encode(['ok'=>true]); exit;
          }
        } catch (Exception $e) {
          error_log('Error eliminando configuración de año: ' . $e->getMessage());
          $msg = 'Error al eliminar la configuración del año: ' . $e->getMessage();
          if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
            header('Content-Type: application/json'); echo json_encode(['error' => $e->getMessage()]); exit;
          }
        }
      }
    }
  } else {
    $msg = 'No hay conexión con la base de datos.';
  }
}

// then fall through to existing site-config handler
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && !isset($_POST['save_year_config'])
    && !isset($_POST['delete_year_config'])
    && !(isset($_POST['action']) && $_POST['action'] === 'recalc')
) {
  // Only allow saving the site name from this simplified settings form
  $site_name = trim($_POST['site_name'] ?? 'GestionHoras');
  $existing = get_config();
  $existing['site_name'] = $site_name;
  $pdo = null;
  if (function_exists('get_pdo')) $pdo = get_pdo();
  if ($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS app_settings (
          name VARCHAR(191) PRIMARY KEY,
          value TEXT NOT NULL,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // sanitize optional minutes fields from form
    $cm = isset($_POST['site_coffee_minutes']) ? intval(preg_replace('/[^0-9]/','', $_POST['site_coffee_minutes'])) : null;
    $lm = isset($_POST['site_lunch_minutes']) ? intval(preg_replace('/[^0-9]/','', $_POST['site_lunch_minutes'])) : null;
    if ($cm !== null && $cm > 0) $existing['coffee_minutes'] = $cm;
    if ($lm !== null && $lm > 0) $existing['lunch_minutes'] = $lm;
    $stmt = $pdo->prepare('REPLACE INTO app_settings (name,value) VALUES (?,?)');
    $stmt->execute(['site_config', json_encode($existing, JSON_UNESCAPED_UNICODE)]);
    $msg = 'Configuración guardada.';
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
      header('Content-Type: application/json'); echo json_encode(['ok'=>true]); exit;
    }
  } else {
    $msg = 'Error: no hay conexión con la base de datos para guardar la configuración.';
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
      header('Content-Type: application/json'); http_response_code(500); echo json_encode(['ok'=>false,'error'=>'no_db']); exit;
    }
  }
}

$c = get_config();

$pdo = null; if (function_exists('get_pdo')) $pdo = get_pdo();
$year_configs = [];
$edit_year_cfg = null;
if ($pdo) {
  try {
    $stmt = $pdo->query('SELECT * FROM year_configs ORDER BY year DESC');
    $year_configs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (isset($_GET['edit_year'])) {
      $yy = intval($_GET['edit_year']);
      $st = $pdo->prepare('SELECT * FROM year_configs WHERE year = ?');
      $st->execute([$yy]);
      $edit_year_cfg = $st->fetch(PDO::FETCH_ASSOC);
    }
  } catch (Exception $e) {
    // ignore: table may not exist yet
  }
}


?>
<?php header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0'); header('Pragma: no-cache'); header('Expires: 0'); ?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Configuración — GestionHoras</title>
  <link rel="icon" type="image/svg+xml" href="images/favicon.svg">
  <link rel="stylesheet" href="styles.css?v=<?php echo filemtime(__DIR__.'/styles.css'); ?>">
  <link rel="stylesheet" href="css/settings.css">
  <link rel="stylesheet" href="assets/fontawesome/css/all.min.css">
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>
<div class="container">

  <?php if($msg): ?>
  <div class="ok" style="margin-bottom:16px;"><?php echo htmlspecialchars($msg); ?></div>
  <?php endif; ?>

  <h2 style="margin:0 0 20px;font-size:1.25rem;">⚙️ Configuración</h2>

  <div style="display:flex;flex-direction:column;gap:20px;">

    <!-- ─── NOMBRE DEL SITIO ─── -->
    <div class="card">
      <div class="card-body">
        <h3 style="margin:0 0 16px;font-size:1rem;">🏷️ Nombre del sitio</h3>
        <form method="post">
          <div class="form-group">
            <label class="form-label">Nombre</label>
            <input class="form-control" name="site_name"
              value="<?php echo htmlspecialchars($c['site_name'] ?? 'GestionHoras'); ?>"
              style="max-width:320px;">
            <div class="form-help">Texto que aparece en la cabecera.</div>
          </div>
          <div style="margin-top:16px;display:flex;justify-content:flex-end;">
            <button class="btn btn-primary" type="submit">Guardar</button>
          </div>
        </form>
      </div>
    </div>

    <!-- ─── PAUSAS ─── -->
    <div class="card">
      <div class="card-body">
        <h3 style="margin:0 0 16px;font-size:1rem;">☕ Pausas</h3>
        <form method="post">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;max-width:480px;">
            <div class="form-group">
              <label class="form-label">Café (min)</label>
              <input class="form-control" name="site_coffee_minutes"
                value="<?php echo htmlspecialchars($c['coffee_minutes'] ?? 15); ?>">
            </div>
            <div class="form-group">
              <label class="form-label">Comida (min)</label>
              <input class="form-control" name="site_lunch_minutes"
                value="<?php echo htmlspecialchars($c['lunch_minutes'] ?? 30); ?>">
            </div>
          </div>
          <div style="margin-top:16px;display:flex;justify-content:flex-end;max-width:480px;">
            <button class="btn btn-primary" type="submit">Guardar</button>
          </div>
        </form>
      </div>
    </div>

    <!-- ─── CONFIGURACIÓN POR AÑO ─── -->
    <div class="card">
      <div class="card-body">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
          <h3 style="margin:0;font-size:1rem;">📅 Configuración por año</h3>
          <button id="openAddYearBtn" class="btn btn-primary" type="button" style="font-size:.82rem;padding:6px 14px;">
            <i class="fas fa-plus"></i> Añadir año
          </button>
        </div>
        <table class="sheet" id="year-config-table" style="width:100%;table-layout:fixed;">
            <thead>
              <tr>
                <th style="width:60px;">Año</th>
                <th style="text-align:center;">🌨 Invierno<br><span style="font-weight:400;font-size:.75rem;opacity:.6;">Lu–Ju / Vie</span></th>
                <th style="text-align:center;">☀️ Verano<br><span style="font-weight:400;font-size:.75rem;opacity:.6;">Lu–Ju / Vie</span></th>
                <th style="text-align:center;">H/día<br><span style="font-weight:400;font-size:.75rem;opacity:.6;">Inv / Ver</span></th>
                <th style="text-align:center;">Pausas<br><span style="font-weight:400;font-size:.75rem;opacity:.6;">Café / Comida</span></th>
                <th style="width:80px;"></th>
              </tr>
            </thead>
            <tbody>
            <?php foreach($year_configs as $yc): ?>
              <?php
                $fmt = fn($v) => $v !== null ? sprintf('%02d:%02d', floor($v), round(($v-floor($v))*60)) : '—';
              ?>
              <tr data-year="<?php echo htmlspecialchars($yc['year']); ?>"
                  data-mon_thu="<?php echo htmlspecialchars($yc['mon_thu']); ?>"
                  data-friday="<?php echo htmlspecialchars($yc['friday']); ?>"
                  data-summer_mon_thu="<?php echo htmlspecialchars($yc['summer_mon_thu']); ?>"
                  data-summer_friday="<?php echo htmlspecialchars($yc['summer_friday']); ?>"
                  data-expected_daily_hours_winter="<?php echo $yc['expected_daily_hours_winter'] !== null ? htmlspecialchars($yc['expected_daily_hours_winter']) : ''; ?>"
                  data-expected_daily_hours_summer="<?php echo $yc['expected_daily_hours_summer'] !== null ? htmlspecialchars($yc['expected_daily_hours_summer']) : ''; ?>"
                  data-coffee_minutes="<?php echo htmlspecialchars($yc['coffee_minutes']); ?>"
                  data-lunch_minutes="<?php echo htmlspecialchars($yc['lunch_minutes']); ?>">
                <td class="yc-year" style="font-weight:700;color:var(--brand-accent,#1dd1a1);"><?php echo htmlspecialchars($yc['year']); ?></td>
                <td style="text-align:center;">
                  <span class="yc-mon_thu"><?php echo $fmt($yc['mon_thu']); ?></span>
                  <span style="opacity:.4;margin:0 3px;">/</span>
                  <span class="yc-friday"><?php echo $fmt($yc['friday']); ?></span>
                </td>
                <td style="text-align:center;">
                  <span class="yc-summer_mon_thu"><?php echo $fmt($yc['summer_mon_thu']); ?></span>
                  <span style="opacity:.4;margin:0 3px;">/</span>
                  <span class="yc-summer_friday"><?php echo $fmt($yc['summer_friday']); ?></span>
                </td>
                <td style="text-align:center;">
                  <span class="yc-expected_daily_hours_winter"><?php echo $fmt($yc['expected_daily_hours_winter']); ?></span>
                  <span style="opacity:.4;margin:0 3px;">/</span>
                  <span class="yc-expected_daily_hours_summer"><?php echo $fmt($yc['expected_daily_hours_summer']); ?></span>
                </td>
                <td style="text-align:center;">
                  <span class="yc-coffee_minutes"><?php echo $yc['coffee_minutes']; ?>m</span>
                  <span style="opacity:.4;margin:0 3px;">/</span>
                  <span class="yc-lunch_minutes"><?php echo $yc['lunch_minutes']; ?>m</span>
                </td>
                <td style="white-space:nowrap;">
                  <button class="btn btn-sm icon-btn edit-year-btn" type="button" title="Editar"><i class="fas fa-edit"></i></button>
                  <form method="post" style="display:inline;" onsubmit="return confirm('¿Eliminar año <?php echo htmlspecialchars($yc['year']); ?>?');">
                    <input type="hidden" name="delete_year_config" value="<?php echo htmlspecialchars($yc['year']); ?>">
                    <button class="btn btn-sm icon-btn btn-danger" type="submit" title="Eliminar"><i class="fas fa-trash"></i></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
      </div>
    </div>

    <!-- ─── USUARIOS ─── -->
    <div class="card">
      <div class="card-body">
        <?php
          require_once __DIR__ . '/db.php';
          $pdo = get_pdo();

          if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add_user_action'])){
            $u = $_POST['username'] ?? '';
            $p = $_POST['password'] ?? '';
            if ($u && $p) {
              $hash = password_hash($p, PASSWORD_DEFAULT);
              $is_admin = !empty($_POST['is_admin']) ? 1 : 0;
              try {
                $stmt = $pdo->prepare('INSERT INTO users (username,password,is_admin) VALUES (?,?,?)');
                $stmt->execute([$u,$hash,$is_admin]);
                echo '<div class="ok" style="margin-bottom:12px;">Usuario añadido correctamente.</div>';
              } catch (Throwable $e) {
                echo '<div class="error" style="margin-bottom:12px;">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
              }
            }
          }
          if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['reset_user_id'])){
            $user_id = intval($_POST['reset_user_id']);
            // Sin contraseña indicada → temporal ALEATORIA (ya no un literal predecible)
            $new_password = $_POST['new_password'] ?: bin2hex(random_bytes(5));
            $generated = empty($_POST['new_password']);
            $hash = password_hash($new_password, PASSWORD_DEFAULT);
            try {
              $stmt = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
              $stmt->execute([$hash, $user_id]);
              if ($generated) {
                echo '<div class="ok" style="margin-bottom:12px;">Contraseña temporal generada: <code>' . htmlspecialchars($new_password) . '</code> — comunícala al usuario.</div>';
              } else {
                echo '<div class="ok" style="margin-bottom:12px;">Contraseña actualizada.</div>';
              }
            } catch (Throwable $e) {
              echo '<div class="error" style="margin-bottom:12px;">Error al resetear contraseña.</div>';
            }
          }
          if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['delete_user_id'])){
            $user_id = intval($_POST['delete_user_id']);
            try {
              $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
              $stmt->execute([$user_id]);
              echo '<div class="ok" style="margin-bottom:12px;">Usuario eliminado.</div>';
            } catch (Throwable $e) {
              echo '<div class="error" style="margin-bottom:12px;">Error al eliminar usuario.</div>';
            }
          }
          $rows = $pdo->query('SELECT id,username,is_admin,created_at FROM users ORDER BY id')->fetchAll();
        ?>
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
          <h3 style="margin:0;font-size:1rem;">👤 Gestión de usuarios</h3>
          <button class="btn btn-primary" id="open-add-user-btn" type="button" style="font-size:.82rem;padding:6px 14px;">
            <i class="fas fa-user-plus"></i> Añadir usuario
          </button>
        </div>
        <table class="sheet" style="width:100%;table-layout:fixed;">
            <thead>
              <tr>
                <th style="width:40px;">#</th><th>Usuario</th><th style="width:100px;">Admin</th><th style="width:110px;">Creado</th><th style="width:80px;"></th>
              </tr>
            </thead>
            <tbody>
            <?php foreach($rows as $r): ?>
              <tr>
                <td style="opacity:.5;font-size:.8rem;"><?php echo $r['id'] ?></td>
                <td><strong><?php echo htmlspecialchars($r['username']) ?></strong></td>
                <td><?php echo $r['is_admin'] ? '<span style="background:rgba(55,66,250,.25);color:#93c5fd;border:1px solid rgba(55,66,250,.5);border-radius:4px;font-size:.7rem;font-weight:700;padding:2px 7px;text-transform:uppercase;">Admin</span>' : '' ?></td>
                <td style="opacity:.6;font-size:.82rem;"><?php echo substr($r['created_at'],0,10) ?></td>
                <td style="white-space:nowrap;">
                  <button class="btn btn-sm icon-btn" type="button"
                    onclick="openResetModal(<?php echo $r['id']; ?>, '<?php echo htmlspecialchars($r['username']); ?>')"
                    title="Cambiar contraseña"><i class="fas fa-key"></i></button>
                  <button class="btn btn-sm icon-btn btn-danger" type="button"
                    onclick="openDeleteModal(<?php echo $r['id']; ?>, '<?php echo htmlspecialchars($r['username']); ?>')"
                    title="Eliminar"><i class="fas fa-trash"></i></button>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
      </div>
    </div>

  </div><!-- /flex column -->
</div><!-- /container -->

<!-- ══ MODALES ════════════════════════════════════════════════ -->

<!-- Añadir usuario -->
<div id="userModalOverlay" class="modal-overlay" aria-hidden="true" style="display:none;">
  <div class="modal-dialog">
    <div class="modal-header"><h3 class="modal-title">Añadir usuario</h3></div>
    <div class="modal-body">
      <form id="add-user-form" method="post">
        <input type="hidden" name="add_user_action" value="1">
        <div class="form-group" style="margin-bottom:14px;">
          <label class="form-label">Usuario</label>
          <input class="form-control" name="username" required autofocus>
        </div>
        <div class="form-group" style="margin-bottom:14px;">
          <label class="form-label">Contraseña</label>
          <input class="form-control" type="password" name="password" required>
        </div>
        <div class="form-group" style="margin-bottom:14px;">
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
            <input type="checkbox" name="is_admin" value="1"> Administrador
          </label>
        </div>
        <div class="modal-actions">
          <button class="btn btn-secondary" type="button" id="closeUserModal">Cancelar</button>
          <button class="btn btn-primary" type="submit">Añadir</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Resetear contraseña -->
<div id="resetModalOverlay" class="modal-overlay" aria-hidden="true" style="display:none;">
  <div class="modal-dialog">
    <div class="modal-header"><h3 class="modal-title">Cambiar contraseña</h3></div>
    <div class="modal-body">
      <form method="post">
        <input type="hidden" name="reset_user_id" id="reset_user_id">
        <p style="margin:0 0 14px;">Usuario: <strong id="reset_username"></strong></p>
        <div class="form-group" style="margin-bottom:14px;">
          <label class="form-label">Nueva contraseña</label>
          <input class="form-control" type="password" name="new_password" placeholder="Dejar vacío → temporal aleatoria">
        </div>
        <div class="modal-actions">
          <button class="btn btn-secondary" type="button" id="closeResetModal">Cancelar</button>
          <button class="btn btn-primary" type="submit">Guardar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Eliminar usuario -->
<div id="deleteModalOverlay" class="modal-overlay" aria-hidden="true" style="display:none;">
  <div class="modal-dialog">
    <div class="modal-header"><h3 class="modal-title">Eliminar usuario</h3></div>
    <div class="modal-body">
      <form method="post">
        <input type="hidden" name="delete_user_id" id="delete_user_id">
        <p style="margin:0 0 20px;">¿Eliminar a <strong id="delete_username"></strong>?</p>
        <div class="modal-actions">
          <button class="btn btn-secondary" type="button" id="closeDeleteModal">Cancelar</button>
          <button class="btn btn-danger" type="submit">Eliminar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Añadir/editar año -->
<div id="yearModalOverlay" class="modal-overlay" aria-hidden="true" style="display:none;">
  <div class="modal-dialog" style="max-width:560px;">
    <div class="modal-header"><h3 class="modal-title">Configuración de año</h3></div>
    <div class="modal-body">
      <form method="post" id="yearAddForm">
        <input type="hidden" name="save_year_config" value="1">
        <div class="form-group" style="margin-bottom:16px;">
          <label class="form-label">Año</label>
          <input class="form-control" name="yearcfg_year" type="number" required style="max-width:100px;">
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:20px;margin-bottom:16px;">
          <div>
            <div style="font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px;opacity:.6;margin-bottom:10px;padding-bottom:6px;border-bottom:1px solid rgba(255,255,255,.08);">🌨 Invierno</div>
            <div class="form-group" style="margin-bottom:10px;">
              <label class="form-label" style="font-size:.78rem;">Lun–Jue</label>
              <input class="form-control" name="yearcfg_mon_thu" placeholder="08:00">
            </div>
            <div class="form-group" style="margin-bottom:10px;">
              <label class="form-label" style="font-size:.78rem;">Viernes</label>
              <input class="form-control" name="yearcfg_friday" placeholder="06:00">
            </div>
            <div class="form-group">
              <label class="form-label" style="font-size:.78rem;">Horas/día</label>
              <input class="form-control" name="yearcfg_expected_daily_hours_winter" placeholder="07:39">
            </div>
          </div>
          <div>
            <div style="font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px;opacity:.6;margin-bottom:10px;padding-bottom:6px;border-bottom:1px solid rgba(255,255,255,.08);">☀️ Verano</div>
            <div class="form-group" style="margin-bottom:10px;">
              <label class="form-label" style="font-size:.78rem;">Lun–Jue</label>
              <input class="form-control" name="yearcfg_summer_mon_thu" placeholder="07:30">
            </div>
            <div class="form-group" style="margin-bottom:10px;">
              <label class="form-label" style="font-size:.78rem;">Viernes</label>
              <input class="form-control" name="yearcfg_summer_friday" placeholder="06:00">
            </div>
            <div class="form-group">
              <label class="form-label" style="font-size:.78rem;">Horas/día</label>
              <input class="form-control" name="yearcfg_expected_daily_hours_summer" placeholder="07:00">
            </div>
          </div>
          <div>
            <div style="font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px;opacity:.6;margin-bottom:10px;padding-bottom:6px;border-bottom:1px solid rgba(255,255,255,.08);">☕ Pausas</div>
            <div class="form-group" style="margin-bottom:10px;">
              <label class="form-label" style="font-size:.78rem;">Café (min)</label>
              <input class="form-control" name="yearcfg_coffee_minutes" placeholder="15">
            </div>
            <div class="form-group">
              <label class="form-label" style="font-size:.78rem;">Comida (min)</label>
              <input class="form-control" name="yearcfg_lunch_minutes" placeholder="30">
            </div>
          </div>
        </div>
        <div class="modal-actions">
          <button class="btn btn-secondary" type="button" id="closeYearModal">Cancelar</button>
          <button class="btn btn-primary" type="submit">Guardar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="js/settings-modals.js"></script>
<script src="js/settings-user-management.js"></script>
<script src="js/settings-edit-years.js"></script>

<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
