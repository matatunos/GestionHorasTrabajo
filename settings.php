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
        $mt = $parse_hours($_POST['yearcfg_mon_thu'] ?? null, 8.0);
        $fr = $parse_hours($_POST['yearcfg_friday'] ?? null, 6.0);
        $smt = $parse_hours($_POST['yearcfg_summer_mon_thu'] ?? null, 7.5);
        $sfr = $parse_hours($_POST['yearcfg_summer_friday'] ?? null, 6.0);
        $cm = $parse_minutes($_POST['yearcfg_coffee_minutes'] ?? null, 15);
        $lm = $parse_minutes($_POST['yearcfg_lunch_minutes'] ?? null, 30);
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
        try { $pdo->exec("ALTER TABLE year_configs ADD COLUMN IF NOT EXISTS mon_thu DOUBLE DEFAULT NULL"); } catch(Throwable $e){ try{ $pdo->exec("ALTER TABLE year_configs ADD COLUMN mon_thu DOUBLE DEFAULT NULL"); }catch(Throwable $e2){} }
        try { $pdo->exec("ALTER TABLE year_configs ADD COLUMN IF NOT EXISTS friday DOUBLE DEFAULT NULL"); } catch(Throwable $e){ try{ $pdo->exec("ALTER TABLE year_configs ADD COLUMN friday DOUBLE DEFAULT NULL"); }catch(Throwable $e2){} }
        try { $pdo->exec("ALTER TABLE year_configs ADD COLUMN IF NOT EXISTS summer_mon_thu DOUBLE DEFAULT NULL"); } catch(Throwable $e){ try{ $pdo->exec("ALTER TABLE year_configs ADD COLUMN summer_mon_thu DOUBLE DEFAULT NULL"); }catch(Throwable $e2){} }
        try { $pdo->exec("ALTER TABLE year_configs ADD COLUMN IF NOT EXISTS summer_friday DOUBLE DEFAULT NULL"); } catch(Throwable $e){ try{ $pdo->exec("ALTER TABLE year_configs ADD COLUMN summer_friday DOUBLE DEFAULT NULL"); }catch(Throwable $e2){} }
        try { $pdo->exec("ALTER TABLE year_configs ADD COLUMN IF NOT EXISTS coffee_minutes INT DEFAULT NULL"); } catch(Throwable $e){ try{ $pdo->exec("ALTER TABLE year_configs ADD COLUMN coffee_minutes INT DEFAULT NULL"); }catch(Throwable $e2){} }
        try { $pdo->exec("ALTER TABLE year_configs ADD COLUMN IF NOT EXISTS lunch_minutes INT DEFAULT NULL"); } catch(Throwable $e){ try{ $pdo->exec("ALTER TABLE year_configs ADD COLUMN lunch_minutes INT DEFAULT NULL"); }catch(Throwable $e2){} }
        $stmt = $pdo->prepare('REPLACE INTO year_configs (year, mon_thu, friday, summer_mon_thu, summer_friday, coffee_minutes, lunch_minutes) VALUES (?,?,?,?,?,?,?)');
        $stmt->execute([$yy, $mt, $fr, $smt, $sfr, $cm, $lm]);
          $msg = 'Configuración del año guardada.';
          if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
            header('Content-Type: application/json'); echo json_encode(['ok'=>true]); exit;
          }
      } else {
        $msg = 'Año inválido.';
      }
    } elseif (isset($_POST['delete_year_config'])) {
      // removed temp-file debug write; log via error_log instead
      error_log('DELETE_YEAR_CONFIG POST: ' . json_encode($_POST));
      $yy = intval($_POST['delete_year_config']);
      if ($yy > 0) {
        $stmt = $pdo->prepare('DELETE FROM year_configs WHERE year = ?');
        $stmt->execute([$yy]);
        $msg = 'Configuración del año eliminada.';
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
          header('Content-Type: application/json'); echo json_encode(['ok'=>true]); exit;
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
    $stmt = $pdo->prepare('REPLACE INTO app_settings (name,value) VALUES (?,?)');
    $stmt->execute(['site_config', json_encode($existing, JSON_UNESCAPED_UNICODE)]);
    $msg = 'Nombre del sitio guardado.';
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

// Integrate holidays management (migrated from holidays.php)
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib.php';
$hol_pdo = get_pdo();
$hol_user = null; try { $hol_user = current_user(); } catch (Throwable $e) { $hol_user = null; }
$selHolidayYear = intval($_GET['holiday_year'] ?? date('Y'));
if ($hol_pdo) {
  $hol_pdo->exec("CREATE TABLE IF NOT EXISTS holidays (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    date DATE NOT NULL,
    label VARCHAR(255) DEFAULT NULL,
    type VARCHAR(20) DEFAULT 'holiday',
    annual TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY user_date_unique (user_id,date)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  try { $hol_pdo->exec("ALTER TABLE holidays ADD COLUMN IF NOT EXISTS type VARCHAR(20) DEFAULT 'holiday'"); } catch(Throwable $e){ try{ $hol_pdo->exec("ALTER TABLE holidays ADD COLUMN type VARCHAR(20) DEFAULT 'holiday'"); }catch(Throwable $e2){} }
  try { $hol_pdo->exec("ALTER TABLE holidays ADD COLUMN IF NOT EXISTS annual TINYINT(1) DEFAULT 0"); } catch(Throwable $e){ try{ $hol_pdo->exec("ALTER TABLE holidays ADD COLUMN annual TINYINT(1) DEFAULT 0"); }catch(Throwable $e2){} }
  try { $hol_pdo->exec("ALTER TABLE holidays DROP COLUMN IF EXISTS year"); } catch(Throwable $e){ try{ $hol_pdo->exec("ALTER TABLE holidays DROP COLUMN year"); }catch(Throwable $e2){} }
  try { $hol_pdo->exec("ALTER TABLE holidays DROP INDEX year_date"); } catch(Throwable $e){}
  try { $hol_pdo->exec("ALTER TABLE holidays ADD COLUMN IF NOT EXISTS user_id INT DEFAULT NULL"); } catch(Throwable $e){ try{ $hol_pdo->exec("ALTER TABLE holidays ADD COLUMN user_id INT DEFAULT NULL"); }catch(Throwable $e2){} }

  // Create holiday_types table
  $hol_pdo->exec("CREATE TABLE IF NOT EXISTS holiday_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    label VARCHAR(100) NOT NULL,
    color VARCHAR(7) DEFAULT '#0f172a',
    sort_order INT DEFAULT 0,
    is_system TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  // Seed default holiday types if table is empty
  $typeCheck = $hol_pdo->query("SELECT COUNT(*) as cnt FROM holiday_types")->fetch();
  if ($typeCheck['cnt'] == 0) {
    $defaults = [
      ['holiday', 'Festivo', '#dc2626', 0, 1],
      ['vacation', 'Vacaciones', '#059669', 1, 1],
      ['personal', 'Asuntos propios', '#f97316', 2, 1],
      ['enfermedad', 'Enfermedad', '#3b82f6', 3, 1],
      ['permiso', 'Permiso', '#8b5cf6', 4, 1],
    ];
    $insertStmt = $hol_pdo->prepare('INSERT INTO holiday_types (code, label, color, sort_order, is_system) VALUES (?, ?, ?, ?, ?)');
    foreach ($defaults as $def) {
      $insertStmt->execute($def);
    }
  }

  // handle holiday_type POSTs
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['holiday_type_action'])) {
    if ($_POST['holiday_type_action'] === 'add' && !empty($_POST['type_label']) && !empty($_POST['type_code'])) {
      $code = preg_replace('/[^a-z0-9_]/', '', strtolower($_POST['type_code']));
      $label = trim($_POST['type_label']);
      $color = preg_match('/^#[0-9a-f]{6}$/i', $_POST['type_color'] ?? '') ? $_POST['type_color'] : '#0f172a';
      
      try {
        $stmt = $hol_pdo->prepare('INSERT INTO holiday_types (code, label, color, sort_order) VALUES (?, ?, ?, (SELECT COALESCE(MAX(sort_order), 0) + 1 FROM (SELECT * FROM holiday_types) t))');
        $stmt->execute([$code, $label, $color]);
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest'){
          header('Content-Type: application/json'); echo json_encode(['ok'=>true]); exit;
        }
      } catch (Throwable $e) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest'){
          header('Content-Type: application/json'); echo json_encode(['ok'=>false, 'error'=>'Código duplicado']); exit;
        }
      }
    }
    if ($_POST['holiday_type_action'] === 'delete' && !empty($_POST['type_id'])) {
      $id = intval($_POST['type_id']);
      $typeRow = $hol_pdo->query("SELECT is_system FROM holiday_types WHERE id = $id")->fetch();
      if ($typeRow && !$typeRow['is_system']) {
        $stmt = $hol_pdo->prepare('DELETE FROM holiday_types WHERE id = ?');
        $stmt->execute([$id]);
      }
      if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest'){
        header('Content-Type: application/json'); echo json_encode(['ok'=>true]); exit;
      }
    }
  }

  // handle holiday POSTs
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['holiday_action'])) {
    // Get valid types from database
    $validTypes = [];
    $typesResult = $hol_pdo->query("SELECT code FROM holiday_types ORDER BY sort_order");
    foreach ($typesResult->fetchAll() as $row) {
      $validTypes[] = $row['code'];
    }
    
    if ($_POST['holiday_action'] === 'add' && !empty($_POST['date'])) {
      $d = $_POST['date'];
      $y = intval(date('Y', strtotime($d)));
      $label = trim($_POST['label'] ?? '');
      $type = in_array($_POST['type'] ?? '', $validTypes) ? $_POST['type'] : 'holiday';
      $annual = !empty($_POST['annual']) ? 1 : 0;
      $is_global = (!empty($hol_user) && !empty($hol_user['is_admin']) && !empty($_POST['global']));
      $uid = $is_global ? null : ($hol_user['id'] ?? null);
      $stmt = $hol_pdo->prepare('REPLACE INTO holidays (user_id,date,label,type,annual) VALUES (?,?,?,?,?)');
      $stmt->execute([$uid, $d, $label, $type, $annual]);
      if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest'){
        header('Content-Type: application/json'); echo json_encode(['ok'=>true]); exit;
      }
      header('Location: settings.php?holiday_year=' . urlencode($y)); exit;
    }
    if ($_POST['holiday_action'] === 'update' && !empty($_POST['id'])) {
      $id = intval($_POST['id']);
      $d = $_POST['date'] ?? null;
      $label = trim($_POST['label'] ?? '');
      $type = in_array($_POST['type'] ?? '', $validTypes) ? $_POST['type'] : 'holiday';
      $annual = !empty($_POST['annual']) ? 1 : 0;
      // determine uid: allow admin to make global
      $is_global = (!empty($hol_user) && !empty($hol_user['is_admin']) && !empty($_POST['global']));
      $uid = $is_global ? null : ($hol_user['id'] ?? null);
      // Update the holiday row; if row doesn't exist this will affect 0 rows
      $ust = $hol_pdo->prepare('UPDATE holidays SET user_id = ?, date = ?, label = ?, type = ?, annual = ? WHERE id = ?');
      $ust->execute([$uid, $d, $label, $type, $annual, $id]);
      if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest'){
        header('Content-Type: application/json'); echo json_encode(['ok'=>true]); exit;
      }
      header('Location: settings.php?holiday_year=' . urlencode(intval(date('Y', strtotime($d ?? date('Y-01-01')))))); exit;
    }
    if ($_POST['holiday_action'] === 'delete' && !empty($_POST['id'])) {
      $stmt = $hol_pdo->prepare('DELETE FROM holidays WHERE id = ?');
      $stmt->execute([intval($_POST['id'])]);
      if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest'){
        header('Content-Type: application/json'); echo json_encode(['ok'=>true]); exit;
      }
      header('Location: settings.php?holiday_year=' . urlencode($selHolidayYear)); exit;
    }
  }

  $hstmt = $hol_pdo->prepare('SELECT * FROM holidays WHERE (YEAR(date) = ? OR annual = 1) AND (user_id IS NULL OR user_id = ?) ORDER BY date ASC');
  $hstmt->execute([$selHolidayYear, $hol_user['id'] ?? null]);
  $holiday_rows = $hstmt->fetchAll(PDO::FETCH_ASSOC);
} else {
  $holiday_rows = [];
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Configuración — GestionHoras</title>
  <link rel="stylesheet" href="styles.css">
  <link rel="stylesheet" href="css/settings.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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

        <div class="form-actions mt-2"><button class="btn btn-primary" type="submit">Guardar nombre del sitio</button><button class="btn btn-secondary" type="button" onclick="location.reload();">Cancelar</button></div>
      </div>
    </form>

    <!-- Holiday Types Management Section -->
    <div style="margin-top:24px;">
      <div class="card">
        <h3>Tipos de festivos/ausencias</h3>
        <p style="font-size: 13px; color: #666; margin-bottom: 12px;">Gestiona los tipos de días no trabajados disponibles.</p>
        
        <div style="margin-bottom: 12px;">
          <button id="openAddTypeBtn" class="btn btn-primary" type="button">Añadir tipo</button>
        </div>

        <!-- Holiday Types Modal -->
        <div id="typeModalOverlay" class="modal-overlay" aria-hidden="true" style="display:none;">
          <div id="typeModal" class="modal-dialog" role="dialog" aria-modal="true">
            <div class="modal-header">
              <h3 class="modal-title">Añadir tipo de festivo</h3>
            </div>
            <div class="modal-body">
              <form method="post" id="typeAddForm" class="form-wrapper">
                <input type="hidden" name="holiday_type_action" value="add">
                
                <div class="form-group">
                  <label class="form-label">Código</label>
                  <input class="form-control" type="text" name="type_code" placeholder="ej: medical_leave" required pattern="[a-z0-9_]+">
                  <div class="form-help">Solo letras minúsculas, números y guiones bajos.</div>
                </div>

                <div class="form-group">
                  <label class="form-label">Nombre</label>
                  <input class="form-control" type="text" name="type_label" placeholder="ej: Baja médica" required>
                </div>

                <div class="form-group">
                  <label class="form-label">Color</label>
                  <input class="form-control" type="color" name="type_color" value="#0f172a">
                </div>

                <div class="form-actions modal-actions mt-2">
                  <button class="btn btn-secondary" type="button" id="closeTypeModal">Cancelar</button>
                  <button class="btn btn-primary" type="submit">Crear</button>
                </div>
              </form>
            </div>
          </div>
        </div>

        <!-- Holiday Types Table -->
        <div class="table-responsive">
          <table class="table">
            <thead>
              <tr>
                <th>Color</th>
                <th>Código</th>
                <th>Nombre</th>
                <th style="text-align: center;">Acción</th>
              </tr>
            </thead>
            <tbody>
              <?php
              $types = $hol_pdo->query("SELECT * FROM holiday_types ORDER BY sort_order")->fetchAll();
              foreach ($types as $type):
              ?>
              <tr>
                <td><div style="width: 24px; height: 24px; background: <?php echo htmlspecialchars($type['color']); ?>; border-radius: 3px; border: 1px solid #ccc;"></div></td>
                <td><code><?php echo htmlspecialchars($type['code']); ?></code></td>
                <td><?php echo htmlspecialchars($type['label']); ?></td>
                <td style="text-align: center;">
                  <?php if (!$type['is_system']): ?>
                    <form method="post" style="display: inline;">
                      <input type="hidden" name="holiday_type_action" value="delete">
                      <input type="hidden" name="type_id" value="<?php echo $type['id']; ?>">
                      <button class="btn btn-sm btn-danger" type="submit" onclick="return confirm('¿Eliminar este tipo?');">Eliminar</button>
                    </form>
                  <?php else: ?>
                    <span style="color: #999; font-size: 12px;">Sistema</span>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div style="margin-top:12px;">
      <div style="margin-bottom:8px;"></div>
      <div class="card">
        <h3>Festivos — Año <?php echo $selHolidayYear; ?></h3>
        <form method="get" class="form-wrapper mb-2">
          <div class="form-grid"><div class="form-group"><label class="form-label">Año</label>
            <select class="form-control" name="holiday_year" onchange="this.form.submit()">
              <?php for($y = date('Y')-2; $y <= date('Y')+2; $y++): ?>
                <option value="<?php echo $y;?>" <?php if($y==$selHolidayYear) echo 'selected';?>><?php echo $y;?></option>
              <?php endfor; ?>
            </select>
          </div></div>
        </form>

        <div style="margin-bottom:12px;">
          <button id="openAddHolidayBtn" class="btn btn-primary" type="button">Añadir festivo</button>
        </div>

        <!-- Modal for adding a holiday -->
        <div id="holidayModalOverlay" class="modal-overlay" aria-hidden="true" style="display:none;">
          <div id="holidayModal" class="modal-dialog" role="dialog" aria-modal="true">
            <div class="modal-header">
              <h3 class="modal-title">Añadir festivo/vacaciones</h3>
            </div>
            <div class="modal-body">
            <form method="post" id="holidayAddForm" class="form-wrapper">
              <input type="hidden" name="holiday_action" value="add">
              
              <!-- Calendar selection -->
              <div class="form-group" style="margin-bottom: 16px;">
                <label class="form-label">Selecciona los días (Ctrl+click o Shift+click para periodos)</label>
                <div style="display: flex; gap: 8px; margin-bottom: 12px; align-items: center;">
                  <select id="hd_calendar_month" class="form-control" style="flex: 1;">
                    <option value="1">Enero</option>
                    <option value="2">Febrero</option>
                    <option value="3">Marzo</option>
                    <option value="4">Abril</option>
                    <option value="5">Mayo</option>
                    <option value="6">Junio</option>
                    <option value="7">Julio</option>
                    <option value="8">Agosto</option>
                    <option value="9">Septiembre</option>
                    <option value="10">Octubre</option>
                    <option value="11">Noviembre</option>
                    <option value="12">Diciembre</option>
                  </select>
                  <select id="hd_calendar_year" class="form-control" style="flex: 1;">
                    <?php for($y = date('Y')-2; $y <= date('Y')+2; $y++): ?>
                      <option value="<?php echo $y;?>" <?php if($y==date('Y')) echo 'selected';?>><?php echo $y;?></option>
                    <?php endfor; ?>
                  </select>
                </div>
                <div id="holidayCalendar" class="holiday-calendar"></div>
              </div>
              
              <!-- Selected dates display -->
              <div class="form-group" style="margin-bottom: 16px;">
                <label class="form-label">Días seleccionados</label>
                <div id="selectedDatesDisplay" style="padding: 8px; background: #f0f0f0; border-radius: 4px; min-height: 40px; font-size: 13px; word-wrap: break-word;">
                  <span style="color: #999;">Ninguno seleccionado</span>
                </div>
                <input type="hidden" id="hd_dates_json" name="dates_json" value="">
              </div>
              
              <!-- Common options -->
              <div class="form-grid">
                <div class="form-group"><label class="form-label">Descripción</label><input class="form-control" type="text" name="label" placeholder="Ej: Vacaciones verano"></div>
                <div class="form-group"><label class="form-label">Tipo</label>
                  <select class="form-control" name="type">
                    <?php
                    $types = $hol_pdo->query("SELECT code, label FROM holiday_types ORDER BY sort_order")->fetchAll();
                    foreach ($types as $type):
                    ?>
                    <option value="<?php echo htmlspecialchars($type['code']); ?>"><?php echo htmlspecialchars($type['label']); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="form-group"><?php echo render_checkbox('annual', 0, 'Repite anualmente'); ?></div>
                <div class="form-group"><?php echo render_checkbox('global', 0, 'Visible a todos (global)'); ?></div>
              </div>
              
              <div class="form-actions modal-actions mt-2">
                <button class="btn btn-secondary" type="button" id="closeHolidayModal">Cancelar</button>
                <button class="btn btn-primary" type="submit">Añadir</button>
              </div>
            </form>
            </div>
          </div>
        </div>

        <h4>Listado de festivos</h4>
        <div class="table-responsive">
          <table class="table compact">
            <thead><tr><th>Fecha</th><th>Repite</th><th>Tipo</th><th>Descripción</th><th>Global</th><th>Acciones</th></tr></thead>
            <tbody>
            <?php foreach($holiday_rows as $r):
              $displayDate = $r['date'];
              if (!empty($r['annual'])) {
                $displayDate = sprintf('%04d-%s', $selHolidayYear, substr($r['date'],5));
              }
            ?>
              <tr class="<?php echo $r['type'] === 'vacation' ? 'vacation' : ($r['type'] === 'personal' ? 'personal' : 'holiday'); ?>" data-hid="<?php echo intval($r['id']); ?>" data-date="<?php echo htmlspecialchars($r['date']); ?>" data-annual="<?php echo intval($r['annual']); ?>" data-type="<?php echo htmlspecialchars($r['type']); ?>" data-label="<?php echo htmlspecialchars($r['label']); ?>" data-userid="<?php echo htmlspecialchars($r['user_id'] ?? ''); ?>" data-global="<?php echo empty($r['user_id']) ? '1' : '0'; ?>">
                <td class="holiday-date"><?php echo htmlspecialchars($displayDate)?></td>
                <td class="holiday-annual"><?php echo !empty($r['annual']) ? 'Anual' : ''?></td>
                <td class="holiday-type"><?php echo ($r['type']==='vacation') ? 'Vacaciones' : (($r['type']==='personal') ? 'Asuntos propios' : (($r['type']==='enfermedad') ? 'Enfermedad' : (($r['type']==='permiso') ? 'Permiso' : 'Festivo'))); ?></td>
                <td class="holiday-label"><?php echo htmlspecialchars($r['label'])?></td>
                <td class="holiday-global">
                  <?php if (empty($r['user_id'])): ?>
                    Sí
                  <?php else: ?>
                    No
                  <?php endif; ?>
                </td>
                <td class="holiday-actions">
                  <button class="btn edit-holiday-btn highlight-edit-btn icon-btn btn-edit" type="button" title="Editar festivo"><i class="fas fa-pencil"></i></button>
                  <form method="post" onsubmit="return confirm('Eliminar este festivo?');">
                    <input type="hidden" name="holiday_action" value="delete">
                    <input type="hidden" name="id" value="<?php echo $r['id']?>">
                    <button class="btn btn-danger icon-btn btn-delete" type="submit" title="Eliminar festivo"><i class="fas fa-trash"></i></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <h3>Configuración por año</h3>
      <div style="display:flex;gap:8px;align-items:center;margin-bottom:8px;">
        <button class="btn btn-primary" type="button" id="openAddYearBtn">Añadir año</button>
        <div class="small">Editar un año: pulsa <strong>Editar</strong> en la fila; los datos aparecerán arriba para modificar.</div>
      </div>
      
      </div>

      <?php if (!empty($year_configs)): ?>
      <table class="table" style="margin-top:8px; width:100%;">
        <thead><tr><th>Año</th><th>Lun-Jue</th><th>Viernes</th><th>Verano L-J</th><th>Verano V</th><th>Café</th><th>Comida</th><th>Acciones</th></tr></thead>
        <tbody>
        <?php foreach ($year_configs as $yc): ?>
          <tr data-year="<?php echo intval($yc['year']); ?>" data-mon_thu="<?php echo htmlspecialchars($yc['mon_thu']); ?>" data-friday="<?php echo htmlspecialchars($yc['friday']); ?>" data-summer_mon_thu="<?php echo htmlspecialchars($yc['summer_mon_thu']); ?>" data-summer_friday="<?php echo htmlspecialchars($yc['summer_friday']); ?>" data-coffee_minutes="<?php echo htmlspecialchars($yc['coffee_minutes']); ?>" data-lunch_minutes="<?php echo htmlspecialchars($yc['lunch_minutes']); ?>">
            <td class="yc-year"><?php echo htmlspecialchars($yc['year']); ?></td>
            <td class="yc-mon_thu"><?php echo htmlspecialchars($yc['mon_thu']); ?></td>
            <td class="yc-friday"><?php echo htmlspecialchars($yc['friday']); ?></td>
            <td class="yc-summer_mon_thu"><?php echo htmlspecialchars($yc['summer_mon_thu']); ?></td>
            <td class="yc-summer_friday"><?php echo htmlspecialchars($yc['summer_friday']); ?></td>
            <td class="yc-coffee_minutes"><?php echo htmlspecialchars($yc['coffee_minutes']); ?></td>
            <td class="yc-lunch_minutes"><?php echo htmlspecialchars($yc['lunch_minutes']); ?></td>
            <td class="yc-actions">
              <button class="btn edit-year-btn highlight-edit-btn icon-btn btn-edit" type="button" title="Editar configuración"><i class="fas fa-pencil"></i></button>
              <form method="post" onsubmit="return confirm('Eliminar configuración del año <?php echo intval($yc['year']); ?>?');">
                <input type="hidden" name="delete_year_config" value="<?php echo intval($yc['year']); ?>">
                <button class="btn btn-danger icon-btn btn-delete" type="submit" title="Eliminar configuración"><i class="fas fa-trash"></i></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php else: ?>
        <div class="small">No hay configuraciones por año todavía.</div>
      <?php endif; ?>
    </div>
    <div class="footer small">Config stored in <strong>DB (app_settings.site_config)</strong></div>
  </div>

  <!-- Users section -->
  <div class="card">
    <h3>Gestión de Usuarios</h3>
    <?php
      require_once __DIR__ . '/db.php';
      $pdo = get_pdo();
      
      // Handle add user
      if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add_user_action'])){
        $u = $_POST['username'] ?? '';
        $p = $_POST['password'] ?? '';
        if ($u && $p) {
          $hash = password_hash($p, PASSWORD_DEFAULT);
          $is_admin = !empty($_POST['is_admin']) ? 1 : 0;
          try {
            $stmt = $pdo->prepare('INSERT INTO users (username,password,is_admin) VALUES (?,?,?)');
            $stmt->execute([$u,$hash,$is_admin]);
            echo '<div class="ok">Usuario añadido correctamente.</div>';
          } catch (Throwable $e) {
            echo '<div class="error">Error al añadir usuario: ' . htmlspecialchars($e->getMessage()) . '</div>';
          }
        }
      }
      
      // Handle reset password
      if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['reset_user_id'])){
        $user_id = intval($_POST['reset_user_id']);
        $new_password = $_POST['new_password'] ?? 'Temporal123!';
        $hash = password_hash($new_password, PASSWORD_DEFAULT);
        try {
          $stmt = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
          $stmt->execute([$hash, $user_id]);
          echo '<div class="ok">Contraseña reseteada correctamente.</div>';
        } catch (Throwable $e) {
          echo '<div class="error">Error al resetear contraseña.</div>';
        }
      }
      
      // Handle delete user
      if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['delete_user_id'])){
        $user_id = intval($_POST['delete_user_id']);
        try {
          $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
          $stmt->execute([$user_id]);
          echo '<div class="ok">Usuario eliminado.</div>';
        } catch (Throwable $e) {
          echo '<div class="error">Error al eliminar usuario.</div>';
        }
      }
      
      $rows = $pdo->query('SELECT id,username,is_admin,created_at FROM users ORDER BY id')->fetchAll();
    ?>
    <div class="table-responsive">
      <table class="sheet">
        <thead>
          <tr><th>ID</th><th>Usuario</th><th>Admin</th><th>Creado</th><th>Acciones</th></tr>
        </thead>
        <tbody>
        <?php foreach($rows as $r): ?>
          <tr>
            <td><?php echo $r['id']?></td>
            <td><?php echo htmlspecialchars($r['username'])?></td>
            <td><?php echo $r['is_admin'] ? 'Sí' : '' ?></td>
            <td><?php echo $r['created_at']?></td>
            <td>
              <button class="btn btn-sm icon-btn btn-secondary" type="button" onclick="openResetModal(<?php echo $r['id']; ?>, '<?php echo htmlspecialchars($r['username']); ?>')" title="Resetear contraseña"><i class="fas fa-key"></i></button>
              <button class="btn btn-sm icon-btn btn-danger" type="button" onclick="openDeleteModal(<?php echo $r['id']; ?>, '<?php echo htmlspecialchars($r['username']); ?>')" title="Eliminar usuario"><i class="fas fa-trash"></i></button>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <button class="btn btn-primary" id="open-add-user-btn" type="button" style="margin-top: 1rem;"><i class="fas fa-user-plus"></i> Añadir usuario</button>
  </div>

  <!-- Modal for adding a user -->
  <div id="userModalOverlay" class="modal-overlay" aria-hidden="true" style="display:none;">
    <div id="userModal" class="modal-dialog" role="dialog" aria-modal="true">
      <div class="modal-header">
        <h3 class="modal-title">Añadir usuario</h3>
      </div>
      <div class="modal-body">
        <form id="add-user-form" method="post" class="row-form">
          <input type="hidden" name="add_user_action" value="1">
          <div style="margin-bottom: 1rem;">
            <label class="form-label">Usuario</label>
            <input class="form-control" name="username" required>
          </div>
          <div style="margin-bottom: 1rem;">
            <label class="form-label">Contraseña</label>
            <input class="form-control" type="password" name="password" required>
          </div>
          <div style="margin-bottom: 1rem;">
            <label class="form-label"><input type="checkbox" name="is_admin" value="1"> Administrador</label>
          </div>
          <div class="form-actions modal-actions mt-2">
            <button class="btn btn-secondary" type="button" id="closeUserModal">Cancelar</button>
            <button class="btn btn-primary" type="submit">Añadir</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Modal for resetting password -->
  <div id="resetModalOverlay" class="modal-overlay" aria-hidden="true" style="display:none;">
    <div id="resetModal" class="modal-dialog" role="dialog" aria-modal="true">
      <div class="modal-header">
        <h3 class="modal-title">Resetear contraseña</h3>
      </div>
      <div class="modal-body">
        <form method="post" class="row-form">
          <input type="hidden" name="reset_user_id" id="reset_user_id">
          <div style="margin-bottom: 1rem;">
            <label class="form-label">Usuario: <strong id="reset_username"></strong></label>
          </div>
          <div style="margin-bottom: 1rem;">
            <label class="form-label">Nueva contraseña</label>
            <input class="form-control" type="password" name="new_password" placeholder="Dejar en blanco para usar Temporal123!">
          </div>
          <div class="form-actions modal-actions mt-2">
            <button class="btn btn-secondary" type="button" id="closeResetModal">Cancelar</button>
            <button class="btn btn-primary" type="submit">Resetear</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Modal for deleting user -->
  <div id="deleteModalOverlay" class="modal-overlay" aria-hidden="true" style="display:none;">
    <div id="deleteModal" class="modal-dialog" role="dialog" aria-modal="true">
      <div class="modal-header">
        <h3 class="modal-title">Eliminar usuario</h3>
      </div>
      <div class="modal-body">
        <p>¿Estás seguro de que quieres eliminar el usuario <strong id="delete_username"></strong>?</p>
        <form method="post" class="row-form">
          <input type="hidden" name="delete_user_id" id="delete_user_id">
          <div class="form-actions modal-actions mt-2">
            <button class="btn btn-secondary" type="button" id="closeDeleteModal">Cancelar</button>
            <button class="btn btn-danger" type="submit">Eliminar</button>
          </div>
        </form>
      </div>
    </div>
  </div>

</div>

<!-- Modal for adding a new year -->
<div id="yearModalOverlay" class="modal-overlay" aria-hidden="true" style="display:none;">
  <div id="yearModal" class="modal-dialog" role="dialog" aria-modal="true">
    <div class="modal-header">
      <h3 class="modal-title">Añadir configuración de año</h3>
    </div>
    <div class="modal-body">
    <form method="post" id="yearAddForm" class="form-wrapper">
      <input type="hidden" name="save_year_config" value="1">
      <div class="form-grid">
        <div class="form-group"><label class="form-label">Año</label><input class="form-control" name="yearcfg_year" type="number" required></div>
      </div>
      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Invierno</label>
          <div style="display:flex;gap:8px;">
            <input class="form-control" name="yearcfg_mon_thu" placeholder="Lun-Jue e.g. 7:30 o 7.5">
            <input class="form-control" name="yearcfg_friday" placeholder="Viernes e.g. 6">
          </div>
        </div>
      </div>
      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Verano</label>
          <div style="display:flex;gap:8px;">
            <input class="form-control" name="yearcfg_summer_mon_thu" placeholder="Lun-Jue e.g. 7:30">
            <input class="form-control" name="yearcfg_summer_friday" placeholder="Viernes e.g. 6">
          </div>
        </div>
      </div>
      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Minutos</label>
          <div style="display:flex;gap:8px;">
            <input class="form-control" name="yearcfg_coffee_minutes" placeholder="Café e.g. 15 o 0:15">
            <input class="form-control" name="yearcfg_lunch_minutes" placeholder="Comida e.g. 30 o 0:30">
          </div>
        </div>
      </div>
      <div class="modal-actions">
        <button class="btn btn-secondary" type="button" id="closeYearModal">Cancelar</button>
        <button class="btn btn-primary" type="submit">Crear año</button>
      </div>
    </form>
    </div>
  </div>
</div>

<script src="js/settings-modals.js"></script>
<script src="js/settings-user-management.js"></script>
<script src="js/settings-holiday-types.js"></script>
<script src="js/holiday-calendar.js"></script>
<script src="js/settings-holiday-form.js"></script>
<script src="js/settings-edit-years.js"></script>
<script src="js/settings-edit-holidays.js"></script>

<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>

