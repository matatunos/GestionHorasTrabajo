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

// Handle AJAX GET for holidays by year/month
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_holidays') {
  require_once __DIR__ . '/db.php';
  require_once __DIR__ . '/auth.php';
  $pdo = get_pdo();
  $year = intval($_GET['year'] ?? date('Y'));
  $month = intval($_GET['month'] ?? date('m'));
  
  // Get current user
  $currentUser = null;
  try {
    $currentUser = current_user();
  } catch (Exception $e) {
    // User not logged in
  }
  
  // Fetch holidays: global (user_id IS NULL) and user-specific
  $query = 'SELECT date, type, label, annual FROM holidays WHERE user_id IS NULL';
  $params = [];
  if ($currentUser) {
    $query .= ' OR user_id = ?';
    $params[] = $currentUser['id'];
  }
  
  $stmt = $pdo->prepare($query);
  $stmt->execute($params);
  $all_holidays = $stmt->fetchAll(PDO::FETCH_ASSOC);
  
  $result = [];
  foreach ($all_holidays as $h) {
    $hDate = $h['date']; // e.g., "2026-01-01"
    
    // Extract month-day from stored date
    $hMonth = intval(substr($hDate, 5, 2)); // Extract MM
    $hDay = intval(substr($hDate, 8, 2));   // Extract DD
    
    // Check if this holiday matches the requested month
    if ($hMonth !== $month) {
      continue; // Skip holidays not in this month
    }
    
    // Reconstruct the date for the selected year
    $dateStr = sprintf('%04d-%02d-%02d', $year, $hMonth, $hDay);
    
    $result[] = [
      'date' => $dateStr,
      'type' => $h['type'] ?? 'holiday',
      'label' => $h['label'] ?? ''
    ];
  }
  
  header('Content-Type: application/json');
  echo json_encode($result);
  exit;
}

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
    && !isset($_POST['holiday_type_action'])
    && !isset($_POST['holiday_action'])
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
        // Get max sort_order first
        $maxOrder = $hol_pdo->query("SELECT COALESCE(MAX(sort_order), 0) as max_order FROM holiday_types")->fetch();
        $nextOrder = intval($maxOrder['max_order']) + 1;
        
        $stmt = $hol_pdo->prepare('INSERT INTO holiday_types (code, label, color, sort_order) VALUES (?, ?, ?, ?)');
        $result = $stmt->execute([$code, $label, $color, $nextOrder]);
        
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest'){
          header('Content-Type: application/json'); echo json_encode(['ok'=>true]); exit;
        }
      } catch (Throwable $e) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest'){
          header('Content-Type: application/json'); echo json_encode(['ok'=>false, 'error'=>'Código duplicado o error: ' . $e->getMessage()]); exit;
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
    
    if ($_POST['holiday_action'] === 'add' && !empty($_POST['dates_json'])) {
      $datesjson = $_POST['dates_json'];
      
      // Intentar parsear como JSON (múltiples fechas)
      $dates = [];
      if (substr($datesjson, 0, 1) === '[') {
        $dates = json_decode($datesjson, true) ?? [];
      } else {
        // Si no es JSON, es una fecha única
        $dates = [$datesjson];
      }
      
      if (empty($dates)) {
        $dates = [];
      }
      
      $label = trim($_POST['label'] ?? '');
      $type = in_array($_POST['type'] ?? '', $validTypes) ? $_POST['type'] : 'holiday';
      $annual = !empty($_POST['annual']) ? 1 : 0;
      $is_global = (!empty($hol_user) && !empty($hol_user['is_admin']) && !empty($_POST['global']));
      $uid = $is_global ? null : ($hol_user['id'] ?? null);
      
      // Procesar cada fecha
      $stmt = $hol_pdo->prepare('REPLACE INTO holidays (user_id,date,label,type,annual) VALUES (?,?,?,?,?)');
      foreach ($dates as $d) {
        $d = trim($d);
        if (!empty($d)) {
          $stmt->execute([$uid, $d, $label, $type, $annual]);
        }
      }
      
      // Determinar año para redirección (usar el primer año de las fechas)
      $y = !empty($dates[0]) ? intval(date('Y', strtotime($dates[0]))) : intval(date('Y'));
      
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
<?php header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0'); header('Pragma: no-cache'); header('Expires: 0'); ?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
  <meta http-equiv="Pragma" content="no-cache">
  <meta http-equiv="Expires" content="0">
  <title>Configuración — GestionHoras</title>
  <link rel="icon" type="image/svg+xml" href="images/favicon.svg">
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
        <h4>Listado de festivos</h4>
        <div style="margin-bottom: 15px; display: flex; gap: 10px; align-items: center;">
          <label for="holiday-year-select" style="font-weight: 600;">Ver festivos del año:</label>
          <select id="holiday-year-select" onchange="window.location.href='settings.php?holiday_year=' + this.value;" style="padding: 8px 12px; border: 1px solid #ccc; border-radius: 4px; min-width: 100px;">
            <?php 
            $currentYear = intval(date('Y'));
            for ($y = $currentYear - 2; $y <= $currentYear + 5; $y++):
            ?>
              <option value="<?php echo $y; ?>" <?php echo $y === $selHolidayYear ? 'selected' : ''; ?>><?php echo $y; ?></option>
            <?php endfor; ?>
          </select>
          <span style="color: #666; font-size: 0.9em;">(Mostrando festivos de <?php echo $selHolidayYear; ?>)</span>
        </div>
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
      <h3>Calendario de Festivos</h3>
      <div style="display:flex;gap:8px;align-items:center;margin-bottom:15px;">
        <button class="btn btn-primary" type="button" id="openAddYearBtn">Añadir año</button>
      </div>

      <div style="margin-bottom: 20px; display: flex; gap: 15px; align-items: center;">
        <div>
          <label for="month-year-select" style="font-weight: 600; margin-right: 10px;">Año:</label>
          <select id="month-year-select" style="padding: 8px 12px; border: 1px solid #ccc; border-radius: 4px;">
            <option value="">Selecciona un año</option>
            <?php 
            // Show configured years first
            if (!empty($year_configs)):
              foreach ($year_configs as $yc):
            ?>
              <option value="<?php echo intval($yc['year']); ?>"><?php echo intval($yc['year']); ?></option>
            <?php 
              endforeach;
            endif;
            // Also show current year and next few years as options
            $currentYear = intval(date('Y'));
            for ($y = $currentYear; $y <= $currentYear + 5; $y++):
              $found = false;
              if (!empty($year_configs)):
                foreach ($year_configs as $yc):
                  if (intval($yc['year']) === $y) { $found = true; break; }
                endforeach;
              endif;
              if (!$found):
            ?>
              <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
            <?php 
              endif;
            endfor;
            ?>
          </select>
        </div>
        <div>
          <label for="month-month-select" style="font-weight: 600; margin-right: 10px;">Mes:</label>
          <select id="month-month-select" style="padding: 8px 12px; border: 1px solid #ccc; border-radius: 4px;">
            <option value="">Selecciona un mes</option>
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
        </div>
      </div>

      <div id="calendar-container" style="display: none; margin-top: 20px; position: relative;">
        <div style="margin-bottom: 16px;">
          <strong style="font-size: 1.1em;" id="month-display"></strong>
          <div style="margin-top: 8px; font-size: 0.85em;">
            <span style="display: inline-block; margin-right: 20px;"><span style="display: inline-block; width: 14px; height: 14px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 3px; margin-right: 6px;"></span>Festivo</span>
            <span style="display: inline-block; margin-right: 20px;"><span style="display: inline-block; width: 14px; height: 14px; background: #cfe2ff; border: 1px solid #0d6efd; border-radius: 3px; margin-right: 6px;"></span>Vacaciones</span>
            <span style="display: inline-block; margin-right: 20px;"><span style="display: inline-block; width: 14px; height: 14px; background: #d1e7dd; border: 1px solid #198754; border-radius: 3px; margin-right: 6px;"></span>Personal</span>
            <span style="display: inline-block;"><span style="display: inline-block; width: 14px; height: 14px; background: #f8d7da; border: 1px solid #dc3545; border-radius: 3px; margin-right: 6px;"></span>Enfermedad</span>
          </div>
          <div style="margin-top: 8px; font-size: 0.8em; color: #666; font-style: italic;">
            <strong>Selecciona múltiples días:</strong> Click = selecciona día • Shift+Click = rango • Ctrl/Cmd+Click = agregar/quitar • Doble click o botón = abrir formulario
          </div>
        </div>
        <div style="padding: 15px; background: white; border-radius: 8px; border: 1px solid #e5e7eb;">
          <div style="display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px;">
            <div style="font-weight: 600; text-align: center; font-size: 0.85em; color: #666; padding: 6px 0;">Lun</div>
            <div style="font-weight: 600; text-align: center; font-size: 0.85em; color: #666; padding: 6px 0;">Mar</div>
            <div style="font-weight: 600; text-align: center; font-size: 0.85em; color: #666; padding: 6px 0;">Mié</div>
            <div style="font-weight: 600; text-align: center; font-size: 0.85em; color: #666; padding: 6px 0;">Jue</div>
            <div style="font-weight: 600; text-align: center; font-size: 0.85em; color: #666; padding: 6px 0;">Vie</div>
            <div style="font-weight: 600; text-align: center; font-size: 0.85em; color: #666; padding: 6px 0;">Sab</div>
            <div style="font-weight: 600; text-align: center; font-size: 0.85em; color: #666; padding: 6px 0;">Dom</div>
            <div id="calendar-grid" style="grid-column: 1/-1; display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px;"></div>
          </div>
        </div>
        
        <!-- Modal for adding a holiday -->
        <div id="holidayModalOverlay" class="modal-overlay-compact" aria-hidden="true" style="display:none;">
          <div id="holidayModal" class="modal-dialog-compact" role="dialog" aria-modal="true">
            <div class="modal-header">
              <h3 class="modal-title">Añadir festivo/vacaciones</h3>
            </div>
            <div class="modal-body">
            <form method="post" id="holidayAddForm" class="form-wrapper">
              <input type="hidden" name="holiday_action" value="add">
              <input type="hidden" id="hd_single_date" name="dates_json" value="">
              
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
              
              <!-- Selected date display -->
              <div class="form-group" style="margin-bottom: 16px; margin-top: 16px;">
                <label class="form-label">Día seleccionado</label>
                <div id="selectedDatesDisplay" style="padding: 8px; background: #f0f0f0; border-radius: 4px; min-height: 30px; font-size: 13px;">
                  <span style="color: #999;">Ninguno seleccionado</span>
                </div>
              </div>
              
              <div class="form-actions modal-actions mt-2">
                <button class="btn btn-secondary" type="button" id="closeHolidayModal">Cancelar</button>
                <button class="btn btn-primary" type="submit">Añadir</button>
              </div>
            </form>
            </div>
          </div>
        </div>
      </div>

      <style>
        .calendar-day-cell {
          aspect-ratio: 1;
          display: flex;
          flex-direction: column;
          align-items: center;
          justify-content: flex-start;
          padding: 6px 4px;
          border: 1px solid #e5e7eb;
          border-radius: 4px;
          background: white;
          font-size: 0.8em;
          min-height: 70px;
        }
        .calendar-day-cell.other-month {
          background: #f3f4f6;
          color: #d1d5db;
          border-color: #d1d5db;
        }
        .calendar-day-number {
          font-weight: 700;
          font-size: 0.95em;
          margin-bottom: 2px;
        }
        .calendar-day-label {
          font-size: 0.65em;
          text-align: center;
          line-height: 1.2;
          color: #374151;
          flex: 1;
          display: flex;
          align-items: center;
          justify-content: center;
          word-break: break-word;
        }
        .calendar-day-cell.holiday {
          background: #fff3cd;
          border-color: #ffc107;
        }
        .calendar-day-cell.vacation {
          background: #cfe2ff;
          border-color: #0d6efd;
        }
        .calendar-day-cell.personal {
          background: #d1e7dd;
          border-color: #198754;
        }
        .calendar-day-cell.enfermedad {
          background: #f8d7da;
          border-color: #dc3545;
        }
      </style>

      <script>
        let currentCalendarYear = null;
        let currentCalendarMonth = null;
        let selectedDates = new Set();
        let lastClickedDate = null;
        
        function openHolidayModalForDates(dates) {
          // Convertir Set a array si es necesario
          const dateArray = Array.from(dates).sort();
          
          if (dateArray.length === 0) return;
          
          // Establecer las fechas en el input oculto como JSON
          document.getElementById('hd_single_date').value = JSON.stringify(dateArray);
          document.getElementById('holidayAddForm').reset();
          
          // Mostrar fechas seleccionadas
          let displayText = '';
          if (dateArray.length === 1) {
            displayText = '<span style="color: #333; font-weight: 600;">' + dateArray[0] + '</span>';
          } else {
            displayText = '<span style="color: #333; font-weight: 600;">' + dateArray.length + ' días seleccionados:</span><br>';
            displayText += dateArray.map(d => '<span style="display: inline-block; margin: 4px 6px 4px 0; padding: 4px 8px; background: #e9ecef; border-radius: 3px; font-size: 12px;">' + d + '</span>').join('');
          }
          document.getElementById('selectedDatesDisplay').innerHTML = displayText;
          
          document.getElementById('holidayModalOverlay').style.display = 'flex';
        }
        
        function toggleDateSelection(dateStr, event) {
          if (event.shiftKey && lastClickedDate) {
            // Rango: desde last clicked hasta actual
            const [y1, m1, d1] = lastClickedDate.split('-').map(Number);
            const [y2, m2, d2] = dateStr.split('-').map(Number);
            
            const start = new Date(y1, m1 - 1, d1);
            const end = new Date(y2, m2 - 1, d2);
            if (start > end) {
              [start, end] = [end, start];
            }
            
            for (let d = new Date(start); d <= end; d.setDate(d.getDate() + 1)) {
              const ds = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
              selectedDates.add(ds);
            }
          } else if (event.ctrlKey || event.metaKey) {
            // Ctrl/Cmd: toggle individual
            if (selectedDates.has(dateStr)) {
              selectedDates.delete(dateStr);
            } else {
              selectedDates.add(dateStr);
            }
          } else {
            // Click normal: seleccionar solo este día
            selectedDates.clear();
            selectedDates.add(dateStr);
          }
          
          lastClickedDate = dateStr;
          
          // Actualizar visual (sin abrir modal)
          updateCalendarSelectionUI();
        }
        
        function updateCalendarSelectionUI() {
          const cells = document.querySelectorAll('[data-date]');
          cells.forEach(cell => {
            const dateStr = cell.getAttribute('data-date');
            if (selectedDates.has(dateStr)) {
              cell.style.boxShadow = 'inset 0 0 0 2px #0056b3';
              cell.style.backgroundColor = cell.style.backgroundColor || 'white';
            } else {
              cell.style.boxShadow = 'none';
            }
          });
        }
        
        function renderCalendar() {
          const year = document.getElementById('month-year-select').value;
          const month = document.getElementById('month-month-select').value;
          const container = document.getElementById('calendar-container');
          
          if (!year || !month) {
            container.style.display = 'none';
            return;
          }
          
          currentCalendarYear = year;
          currentCalendarMonth = month;
          selectedDates.clear();
          lastClickedDate = null;
          
          fetch(location.pathname + '?action=get_holidays&year=' + year + '&month=' + month, {
            headers: {'X-Requested-With': 'XMLHttpRequest'}
          })
          .then(r => r.json())
          .then(holidays => {
            const monthNames = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
                               'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
            document.getElementById('month-display').textContent = monthNames[parseInt(month)] + ' de ' + year;
            
            const holidayMap = {};
            holidays.forEach(h => {
              holidayMap[h.date] = { type: h.type, label: h.label };
            });
            
            const firstDay = new Date(year, month - 1, 1);
            const lastDay = new Date(year, month, 0);
            const startDow = firstDay.getDay() || 7;
            
            const grid = document.getElementById('calendar-grid');
            grid.innerHTML = '';
            
            // Empty cells before first day
            for (let i = 1; i < startDow; i++) {
              const cell = document.createElement('div');
              cell.className = 'calendar-day-cell other-month';
              grid.appendChild(cell);
            }
            
            // Days of month
            for (let d = 1; d <= lastDay.getDate(); d++) {
              const dateStr = year + '-' + String(month).padStart(2, '0') + '-' + String(d).padStart(2, '0');
              const cell = document.createElement('div');
              cell.className = 'calendar-day-cell';
              cell.style.cursor = 'pointer';
              cell.setAttribute('data-date', dateStr);
              
              const numDiv = document.createElement('div');
              numDiv.className = 'calendar-day-number';
              numDiv.textContent = d;
              cell.appendChild(numDiv);
              
              if (holidayMap[dateStr]) {
                const event = holidayMap[dateStr];
                cell.classList.add(event.type || 'holiday');
                if (event.label) {
                  const labelDiv = document.createElement('div');
                  labelDiv.className = 'calendar-day-label';
                  labelDiv.textContent = event.label;
                  cell.appendChild(labelDiv);
                }
              }
              
              // Selección múltiple: click, shift+click, ctrl+click
              cell.addEventListener('click', function(e) {
                e.preventDefault();
                toggleDateSelection(dateStr, e);
              });
              
              // Doble click para abrir el modal
              cell.addEventListener('dblclick', function(e) {
                e.preventDefault();
                openHolidayModalForDates(selectedDates);
              });
              
              grid.appendChild(cell);
            }
            
            // Agregar botón para abrir modal con selección actual
            const buttonDiv = document.createElement('div');
            buttonDiv.style.marginTop = '12px';
            buttonDiv.style.textAlign = 'center';
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-primary';
            btn.textContent = 'Crear festivo con selección';
            btn.addEventListener('click', function() {
              if (selectedDates.size === 0) {
                alert('Selecciona al menos un día');
                return;
              }
              openHolidayModalForDates(selectedDates);
            });
            buttonDiv.appendChild(btn);
            document.getElementById('calendar-container').insertBefore(buttonDiv, document.getElementById('calendar-container').querySelector('.modal-overlay-compact'));
            
            container.style.display = 'block';
          })
          .catch(err => console.error('Error loading holidays:', err));
        }
        
        // Esperar a que el DOM esté listo
        document.addEventListener('DOMContentLoaded', function() {
          document.getElementById('month-year-select').addEventListener('change', renderCalendar);
          document.getElementById('month-month-select').addEventListener('change', renderCalendar);
          
          // Cerrar modal
          document.getElementById('closeHolidayModal').addEventListener('click', function() {
            document.getElementById('holidayModalOverlay').style.display = 'none';
            selectedDates.clear();
            lastClickedDate = null;
            updateCalendarSelectionUI();
          });
        });
      </script>
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
            <input class="form-control" name="yearcfg_mon_thu" placeholder="Lun-Jue e.g. 08:00 o 8.5">
            <input class="form-control" name="yearcfg_friday" placeholder="Viernes e.g. 06:00 o 6">
          </div>
        </div>
      </div>
      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Verano</label>
          <div style="display:flex;gap:8px;">
            <input class="form-control" name="yearcfg_summer_mon_thu" placeholder="Lun-Jue e.g. 07:30 o 7.5">
            <input class="form-control" name="yearcfg_summer_friday" placeholder="Viernes e.g. 06:00 o 6">
          </div>
        </div>
      </div>
      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Descansos</label>
          <div style="display:flex;gap:8px;">
            <input class="form-control" name="yearcfg_coffee_minutes" placeholder="Café e.g. 00:15 o 15">
            <input class="form-control" name="yearcfg_lunch_minutes" placeholder="Comida e.g. 00:30 o 30">
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
<script src="js/settings-holiday-types.js?v=<?php echo time(); ?>"></script>
<script src="js/holiday-calendar.js"></script>
<script src="js/settings-holiday-form.js"></script>
<script src="js/settings-edit-years.js"></script>
<script src="js/settings-edit-holidays.js"></script>

<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>

