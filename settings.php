</style>

<style>
/* Final unified edit button style to force exact match */
.btn-edit {
  background: linear-gradient(90deg,#ffd54d,#ffb74d) !important;
  color: #1b1b1b !important;
  border: 1px solid rgba(0,0,0,0.06) !important;
  box-shadow: 0 4px 10px rgba(0,0,0,0.08) !important;
  padding: 6px 8px !important;
  width: 36px !important;
  height: 36px !important;
  display: inline-flex !important;
  align-items: center !important;
  justify-content: center !important;
  border-radius: 6px !important;
  font-size: 14px !important;
}
.btn-edit svg { width: 16px !important; height: 16px !important; }
</style>
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

  // handle holiday POSTs
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['holiday_action'])) {
    if ($_POST['holiday_action'] === 'add' && !empty($_POST['date'])) {
      $d = $_POST['date'];
      $y = intval(date('Y', strtotime($d)));
      $label = trim($_POST['label'] ?? '');
      $type = in_array($_POST['type'] ?? '', ['holiday','vacation','personal','enfermedad','permiso']) ? $_POST['type'] : 'holiday';
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
      $type = in_array($_POST['type'] ?? '', ['holiday','vacation','personal','enfermedad','permiso']) ? $_POST['type'] : 'holiday';
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

        <div class="form-actions" style="margin-top:12px;"><button class="btn-primary" type="submit">Guardar nombre del sitio</button><button class="btn-secondary" type="button" onclick="location.reload();">Cancelar</button></div>
      </div>
    </form>
    <div style="margin-top:12px;">
      <div style="margin-bottom:8px;"></div>
      <div class="card">
        <h3>Festivos — Año <?php echo $selHolidayYear; ?></h3>
        <form method="get" style="margin-bottom:12px" class="form-wrapper">
          <div class="form-grid"><div class="form-group"><label class="form-label">Año</label>
            <select class="form-control" name="holiday_year" onchange="this.form.submit()">
              <?php for($y = date('Y')-2; $y <= date('Y')+2; $y++): ?>
                <option value="<?php echo $y;?>" <?php if($y==$selHolidayYear) echo 'selected';?>><?php echo $y;?></option>
              <?php endfor; ?>
            </select>
          </div></div>
        </form>

        <div style="margin-bottom:12px;">
          <button id="openAddHolidayBtn" class="btn-primary" type="button">Añadir festivo</button>
        </div>

        <!-- Modal for adding a holiday -->
        <div id="holidayModalOverlay" aria-hidden="true" style="display:none;position:fixed;left:0;top:0;width:100%;height:100%;background:rgba(0,0,0,0.4);align-items:center;justify-content:center;z-index:9999">
          <div id="holidayModal" role="dialog" aria-modal="true" style="background:#fff;padding:18px;border-radius:6px;max-width:720px;width:100%;box-shadow:0 6px 24px rgba(0,0,0,0.2)">
            <h3>Añadir festivo</h3>
            <form method="post" id="holidayAddForm" class="form-wrapper">
              <input type="hidden" name="holiday_action" value="add">
              <div class="form-grid">
                <div class="form-group"><label class="form-label">Fecha</label>
                  <div style="display:flex;gap:8px;align-items:center;">
                    <select id="hd_month" class="form-control" aria-label="Mes"></select>
                    <select id="hd_day" class="form-control" aria-label="Día"></select>
                    <input type="hidden" id="hd_date" name="date" required>
                  </div>
                </div>
                <div class="form-group"><label class="form-label">Descripción</label><input class="form-control" type="text" name="label" placeholder="Ej: Año Nuevo"></div>
                <div class="form-group"><label class="form-label">Tipo</label>
                  <select class="form-control" name="type">
                    <option value="holiday">Festivo</option>
                    <option value="vacation">Vacaciones</option>
                    <option value="personal">Asuntos propios</option>
                    <option value="enfermedad">Enfermedad</option>
                    <option value="permiso">Permiso</option>
                  </select>
                </div>
                <div class="form-group"><?php echo render_checkbox('annual', 0, 'Repite anualmente'); ?></div>
                <div class="form-group"><?php echo render_checkbox('global', 0, 'Visible a todos (global)'); ?></div>
              </div>
              <div class="form-actions" style="margin-top:12px;display:flex;gap:8px;justify-content:flex-end;">
                <button class="btn-secondary" type="button" id="closeHolidayModal">Cancelar</button>
                <button class="btn-primary" type="submit">Añadir</button>
              </div>
            </form>
          </div>
        </div>

        <h4>Listado de festivos</h4>
        <div class="table-responsive">
          <table class="sheet compact no-hide-actions">
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
                <td class="holiday-annual"><?php echo !empty($r['annual']) ? '<span class="badge badge-primary">Anual</span>' : ''?></td>
                <td class="holiday-type"><?php echo ($r['type']==='vacation') ? 'Vacaciones' : (($r['type']==='personal') ? 'Asuntos propios' : (($r['type']==='enfermedad') ? 'Enfermedad' : (($r['type']==='permiso') ? 'Permiso' : 'Festivo'))); ?></td>
                <td class="holiday-label"><?php echo htmlspecialchars($r['label'])?></td>
                <td class="holiday-global">
                  <?php if (empty($r['user_id'])): ?>
                    <span class="badge badge-primary">Sí</span>
                  <?php else: ?>
                    <span class="small">No</span>
                  <?php endif; ?>
                </td>
                <td class="holiday-actions">
                  <button class="btn edit-holiday-btn highlight-edit-btn icon-btn btn-edit" type="button" title="Editar festivo"> <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3 17.25V21h3.75L20.71 7.04a1 1 0 0 0 0-1.41L18.36 3.28a1 1 0 0 0-1.41 0L3 17.25z" fill="currentColor"/></svg> </button>
                  <form method="post" onsubmit="return confirm('Eliminar este festivo?');">
                    <input type="hidden" name="holiday_action" value="delete">
                    <input type="hidden" name="id" value="<?php echo $r['id']?>">
                    <button class="btn btn-danger icon-btn btn-delete" type="submit" title="Eliminar festivo"> <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M6 19a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z" fill="currentColor"/></svg> </button>
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
        <button class="btn-primary" type="button" id="openAddYearBtn">Añadir año</button>
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
              <button class="btn edit-year-btn highlight-edit-btn icon-btn btn-edit" type="button" title="Editar configuración"> <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3 17.25V21h3.75L20.71 7.04a1 1 0 0 0 0-1.41L18.36 3.28a1 1 0 0 0-1.41 0L3 17.25z" fill="currentColor"/></svg> </button>
              <form method="post" onsubmit="return confirm('Eliminar configuración del año <?php echo intval($yc['year']); ?>?');">
                <input type="hidden" name="delete_year_config" value="<?php echo intval($yc['year']); ?>">
                <button class="btn btn-danger icon-btn btn-delete" type="submit" title="Eliminar configuración"> <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M6 19a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z" fill="currentColor"/></svg> </button>
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
</div>

<script>
// Holidays date picker and AJAX glue (migrated from holidays.php)
(function(){
  const monthSel = document.getElementById('hd_month');
  const daySel = document.getElementById('hd_day');
  const hidden = document.getElementById('hd_date');
    const selYear = <?php echo intval($selHolidayYear); ?>;
  // whether current user can mark holidays as global
  const holIsAdmin = <?php echo (!empty($hol_user) && !empty($hol_user['is_admin'])) ? 'true' : 'false'; ?>;
  if (monthSel && daySel && hidden) {
    for (let m=1;m<=12;m++){ const v = String(m).padStart(2,'0'); const o=document.createElement('option'); o.value=v; o.textContent=v; monthSel.appendChild(o);} 
    function setDays(n){ daySel.innerHTML=''; for(let d=1;d<=n;d++){ const v=String(d).padStart(2,'0'); const o=document.createElement('option'); o.value=v; o.textContent=v; daySel.appendChild(o);} }
    setDays(31);
    function updateHidden(){ hidden.value = selYear + '-' + monthSel.value + '-' + daySel.value; }
    monthSel.addEventListener('change', function(){ const m=parseInt(this.value,10); const nd = new Date(2000,m,0).getDate(); setDays(nd); if (daySel.options.length<1) setDays(31); if (+daySel.value>nd) daySel.value=String(nd).padStart(2,'0'); updateHidden(); });
    daySel.addEventListener('change', updateHidden);
    monthSel.value = String((new Date()).getMonth()+1).padStart(2,'0');
    daySel.value = String((new Date()).getDate()).padStart(2,'0');
    updateHidden();
    document.addEventListener('submit', function(e){ const f = e.target; if (!(f instanceof HTMLFormElement)) return; if (f.querySelector('#hd_date')) updateHidden(); }, true);
  }

  async function refreshList(){
    try {
      const res = await fetch(location.pathname + location.search, { headers: {'X-Requested-With':'XMLHttpRequest'} });
      const text = await res.text();
      const tmp = document.createElement('div'); tmp.innerHTML = text;
      const newTable = tmp.querySelector('.table-responsive');
      const cur = document.querySelector('.table-responsive');
      if (newTable && cur) cur.innerHTML = newTable.innerHTML;
    } catch(e){ console.error('refreshList error', e); }
  }

  document.addEventListener('submit', function(e){
    const form = e.target; if (!(form instanceof HTMLFormElement)) return; const fd = new FormData(form); const action = fd.get('holiday_action'); if (action !== 'add' && action !== 'delete') return; e.preventDefault();
    const submitBtn = form.querySelector('[type="submit"]'); let origText; if (submitBtn) { origText = submitBtn.innerText; submitBtn.disabled = true; submitBtn.innerText = 'Enviando...'; }
    fetch(location.pathname + location.search, { method: 'POST', body: fd, headers: {'X-Requested-With':'XMLHttpRequest'} })
      .then(async r => { const ct = r.headers.get('Content-Type') || ''; let data; if (ct.indexOf('application/json') !== -1) { data = await r.json(); } else { const text = await r.text(); try { data = JSON.parse(text); } catch(err) { data = { ok: false, text }; } }
        if (submitBtn) { submitBtn.disabled = false; submitBtn.innerText = origText; }
        if (data && data.ok) { if (action === 'add') form.reset(); refreshList(); } else { alert('Error al procesar la solicitud'); }
      }).catch(err => { console.error(err); if (submitBtn){ submitBtn.disabled = false; submitBtn.innerText = origText; } alert('Error de red'); });
  }, false);
})();
</script>
<script>
// Modal open/close for holiday add (mirrors year modal behavior)
(function(){
  const openBtn = document.getElementById('openAddHolidayBtn');
  const overlay = document.getElementById('holidayModalOverlay');
  const closeBtn = document.getElementById('closeHolidayModal');
  const addForm = document.getElementById('holidayAddForm');
  if (!openBtn || !overlay) return;
  openBtn.addEventListener('click', () => { overlay.style.display = 'flex'; overlay.setAttribute('aria-hidden','false'); try{ addForm.reset(); addForm.querySelector('[name="label"]').focus(); }catch(e){} });
  closeBtn && closeBtn.addEventListener('click', () => { overlay.style.display='none'; overlay.setAttribute('aria-hidden','true'); });
  overlay.addEventListener('click', (e)=>{ if (e.target===overlay) { overlay.style.display='none'; overlay.setAttribute('aria-hidden','true'); } });
})();
</script>
<style>
/* Settings page: force listing panels to have white background for readability */
.table-responsive { background: #ffffff !important; padding: 12px !important; border-radius: 8px; }
.table-responsive .sheet { background: #ffffff !important; }

/* Override holiday/vacation row highlights inside settings listing to keep a neutral background */
.table-responsive .sheet tbody tr.holiday td,
.table-responsive .sheet tbody tr.vacation td,
.table-responsive .sheet tbody tr.personal td,
.table-responsive .sheet tbody tr.illness td,
.table-responsive .sheet tbody tr.permiso td {
  background: transparent !important;
}
/* Stronger, more specific overrides to hide icons and ensure transparency */
.container .card .table-responsive .sheet tbody tr.holiday td,
.container .card .table-responsive .sheet tbody tr.vacation td,
.container .card .table-responsive .sheet tbody tr.personal td,
.container .card .table-responsive .sheet tbody tr.illness td,
.container .card .table-responsive .sheet tbody tr.permiso td {
  background-color: transparent !important;
  background: none !important;
}
.container .card .table-responsive .sheet tbody tr.holiday td:first-child::before,
.container .card .table-responsive .sheet tbody tr.vacation td:first-child::before,
.container .card .table-responsive .sheet tbody tr.personal td:first-child::before,
.container .card .table-responsive .sheet tbody tr.illness td:first-child::before,
.container .card .table-responsive .sheet tbody tr.permiso td:first-child::before {
  display: none !important;
}

/* Simple modal styles */
#yearModalOverlay{position:fixed;left:0;top:0;width:100%;height:100%;background:rgba(0,0,0,0.4);display:none;align-items:center;justify-content:center;z-index:9999}
#yearModal{background:#fff;padding:18px;border-radius:6px;max-width:720px;width:100%;box-shadow:0 6px 24px rgba(0,0,0,0.2)}
#yearModal .form-grid {margin-bottom:8px}
#yearModal .modal-actions {display:flex;gap:8px;justify-content:flex-end;margin-top:12px}
</style>

<!-- Modal for adding a new year -->
<div id="yearModalOverlay" aria-hidden="true">
  <div id="yearModal" role="dialog" aria-modal="true">
    <h3>Añadir configuración de año</h3>
    <form method="post" id="yearAddForm">
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
        <button class="btn-secondary" type="button" id="closeYearModal">Cancelar</button>
        <button class="btn-primary" type="submit">Crear año</button>
      </div>
    </form>
  </div>
</div>

<script>
// Modal open/close and simple reset
(function(){
  const openBtn = document.getElementById('openAddYearBtn');
  const overlay = document.getElementById('yearModalOverlay');
  const closeBtn = document.getElementById('closeYearModal');
  const addForm = document.getElementById('yearAddForm');
  if (!openBtn || !overlay) return;
  openBtn.addEventListener('click', () => { overlay.style.display = 'flex'; overlay.setAttribute('aria-hidden','false'); addForm.reset(); addForm.querySelector('[name="yearcfg_year"]').focus(); });
  closeBtn && closeBtn.addEventListener('click', () => { overlay.style.display='none'; overlay.setAttribute('aria-hidden','true'); });
  overlay.addEventListener('click', (e)=>{ if (e.target===overlay) { overlay.style.display='none'; overlay.setAttribute('aria-hidden','true'); } });
})();
</script>

<script>
// Inline edit for year rows: convert cells to inputs, save via AJAX, cancel restores
(function(){
  function el(sel, ctx){ return (ctx||document).querySelector(sel); }
  function els(sel, ctx){ return Array.from((ctx||document).querySelectorAll(sel)); }

  document.addEventListener('click', function(e){
    const btn = e.target.closest('.edit-year-btn'); if (!btn) return;
    const tr = btn.closest('tr'); if (!tr) return;
    if (tr.dataset.editing === '1') return;
    tr.dataset.editing = '1';
    // store original html
    tr._orig = {};
    ['year','mon_thu','friday','summer_mon_thu','summer_friday','coffee_minutes','lunch_minutes'].forEach(function(k){
      const td = tr.querySelector('.yc-' + k.replace(/_/g,'_')) || tr.querySelector('.yc-' + k);
      if (td) tr._orig[k] = td.innerHTML;
    });
    // replace cells with inputs
    function setInput(cls, name, val){
      const td = tr.querySelector('.yc-' + cls);
      if (!td) return;
      td.innerHTML = '<input class="form-control" name="' + name + '" value="' + (val !== null && val !== undefined ? String(val) : '') + '">';
    }
    setInput('year', 'yearcfg_year', tr.dataset.year);
    setInput('mon_thu', 'yearcfg_mon_thu', tr.dataset.mon_thu);
    setInput('friday', 'yearcfg_friday', tr.dataset.friday);
    setInput('summer_mon_thu', 'yearcfg_summer_mon_thu', tr.dataset.summer_mon_thu);
    setInput('summer_friday', 'yearcfg_summer_friday', tr.dataset.summer_friday);
    setInput('coffee_minutes', 'yearcfg_coffee_minutes', tr.dataset.coffee_minutes);
    setInput('lunch_minutes', 'yearcfg_lunch_minutes', tr.dataset.lunch_minutes);

    // replace actions with Save / Cancel (SVG icons)
    const actionsTd = btn.parentElement;
    actionsTd._orig = actionsTd.innerHTML;
    actionsTd.innerHTML = '';
    const saveBtn = document.createElement('button'); saveBtn.className = 'btn-primary save-year-btn icon-btn'; saveBtn.type='button'; saveBtn.title = 'Guardar'; saveBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M17 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V7l-4-4zM12 19a3 3 0 1 1 0-6 3 3 0 0 1 0 6z" fill="currentColor"/></svg>';
    const cancelBtn = document.createElement('button'); cancelBtn.className='btn-secondary cancel-year-btn icon-btn'; cancelBtn.type='button'; cancelBtn.title='Cancelar'; cancelBtn.innerHTML='<svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M18.3 5.71L12 12l6.3 6.29-1.41 1.42L10.59 13.41 4.29 19.71 2.88 18.3 9.17 12 2.88 5.71 4.29 4.29 10.59 10.59 16.88 4.29z" fill="currentColor"/></svg>';
    actionsTd.appendChild(saveBtn); actionsTd.appendChild(cancelBtn);

    cancelBtn.addEventListener('click', function(){
      // restore
      ['year','mon_thu','friday','summer_mon_thu','summer_friday','coffee_minutes','lunch_minutes'].forEach(function(k){
        const td = tr.querySelector('.yc-' + k);
        if (td) td.innerHTML = tr._orig[k] ?? '';
      });
      actionsTd.innerHTML = actionsTd._orig;
      delete tr.dataset.editing; delete tr._orig; delete actionsTd._orig;
    });

    saveBtn.addEventListener('click', function(){
      const fd = new FormData(); fd.append('save_year_config', '1');
      const inputs = tr.querySelectorAll('input[name]');
      inputs.forEach(function(inp){ fd.append(inp.name, inp.value); });
      // send
      saveBtn.disabled = true; saveBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 50 50" xmlns="http://www.w3.org/2000/svg"><circle cx="25" cy="25" r="20" stroke="currentColor" stroke-width="5" fill="none" stroke-linecap="round"/></svg>';
      fetch(location.pathname + location.search, { method:'POST', body:fd, headers: {'X-Requested-With':'XMLHttpRequest'} })
        .then(r=>r.json().catch(()=>({ok:false})))
        .then(function(data){
          if (data && data.ok) {
            // reload to show persisted values
            location.reload();
          } else {
            alert('Error al guardar la configuración del año');
            saveBtn.disabled = false; saveBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M17 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V7l-4-4zM12 19a3 3 0 1 1 0-6 3 3 0 0 1 0 6z" fill="currentColor"/></svg>';
          }
        }).catch(function(err){ console.error(err); alert('Error de red'); saveBtn.disabled=false; saveBtn.innerHTML='💾'; });
    });
  });
})();
</script>
  </div>
</div>
<script>
// Inline edit for holiday rows
(function(){
  document.addEventListener('click', function(e){
    const btn = e.target.closest('.edit-holiday-btn'); if (!btn) return;
    const tr = btn.closest('tr'); if (!tr || tr.dataset.editing==='1') return;
    tr.dataset.editing='1';
    const hid = tr.dataset.hid;
    tr._orig = {};
    ['date','annual','type','label','global'].forEach(k=>{ const td = (k==='global') ? tr.querySelector('.holiday-global') : tr.querySelector('.holiday-' + k); if (td) tr._orig[k]=td.innerHTML; });
    const dateRaw = tr.dataset.date || '';
    const annualRaw = tr.dataset.annual === '1';
    const typeRaw = tr.dataset.type || 'holiday';
    const labelRaw = tr.dataset.label || '';
    const dateTd = tr.querySelector('.holiday-date'); if (dateTd) dateTd.innerHTML = '<input class="form-control" type="date" name="date" value="'+ (dateRaw||'') + '">';
    const annualTd = tr.querySelector('.holiday-annual'); if (annualTd) annualTd.innerHTML = '<input type="checkbox" name="annual" ' + (annualRaw? 'checked':'' ) + '>';
    const typeTd = tr.querySelector('.holiday-type'); if (typeTd) typeTd.innerHTML = '<select name="type" class="form-control"><option value="holiday">Festivo</option><option value="vacation">Vacaciones</option><option value="personal">Asuntos propios</option><option value="enfermedad">Enfermedad</option><option value="permiso">Permiso</option></select>'; if (typeTd) typeTd.querySelector('select').value = typeRaw;
    const labelTd = tr.querySelector('.holiday-label'); if (labelTd) labelTd.innerHTML = '<input class="form-control" name="label" value="'+ (labelRaw.replace(/"/g,'&quot;')) + '">';
    const actionsTd = tr.querySelector('.holiday-actions'); actionsTd._orig = actionsTd.innerHTML; actionsTd.innerHTML = '';
    const globalTd = tr.querySelector('.holiday-global');
    if (globalTd) {
      globalTd._orig = globalTd.innerHTML;
      // Always show editable checkbox for 'global' in inline edit; server enforces admin rights
      const checked = (tr.dataset.global === '1') ? 'checked' : '';
      globalTd.innerHTML = '<label><input type="checkbox" name="global" ' + checked + '> Global</label>';
    }
    const saveBtn = document.createElement('button'); saveBtn.type='button'; saveBtn.className='btn-primary save-holiday-btn icon-btn'; saveBtn.title='Guardar'; saveBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M17 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V7l-4-4zM12 19a3 3 0 1 1 0-6 3 3 0 0 1 0 6z" fill="currentColor"/></svg>';
    const cancelBtn = document.createElement('button'); cancelBtn.type='button'; cancelBtn.className='btn-secondary cancel-holiday-btn icon-btn'; cancelBtn.title='Cancelar'; cancelBtn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M18.3 5.71L12 12l6.3 6.29-1.41 1.42L10.59 13.41 4.29 19.71 2.88 18.3 9.17 12 2.88 5.71 4.29 4.29 10.59 10.59 16.88 4.29z" fill="currentColor"/></svg>';
    actionsTd.appendChild(saveBtn); actionsTd.appendChild(cancelBtn);
    cancelBtn.addEventListener('click', function(){ ['date','annual','type','label','global'].forEach(k=>{ const td = (k==='global') ? tr.querySelector('.holiday-global') : tr.querySelector('.holiday-'+k); if (td) td.innerHTML = tr._orig[k] ?? ''; }); actionsTd.innerHTML = actionsTd._orig; delete tr._orig; delete tr.dataset.editing; });
    saveBtn.addEventListener('click', function(){
      const fd = new FormData(); fd.append('holiday_action','update'); fd.append('id', hid);
      const dateVal = tr.querySelector('.holiday-date [name="date"]')?.value || '';
      const annualVal = tr.querySelector('.holiday-annual [name="annual"]')?.checked ? '1' : '';
      const typeVal = tr.querySelector('.holiday-type [name="type"]')?.value || 'holiday';
      const labelVal = tr.querySelector('.holiday-label [name="label"]')?.value || '';
      const globalEl = tr.querySelector('.holiday-global [name="global"]');
      const globalVal = globalEl ? (globalEl.checked ? '1' : '0') : '0';
      fd.append('date', dateVal); fd.append('annual', annualVal); fd.append('type', typeVal); fd.append('label', labelVal);
      fd.append('global', globalVal);
      saveBtn.disabled = true; saveBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 50 50" xmlns="http://www.w3.org/2000/svg"><circle cx="25" cy="25" r="20" stroke="currentColor" stroke-width="5" fill="none" stroke-linecap="round"/></svg>';
      fetch(location.pathname + location.search, { method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest'} })
        .then(r=>r.json().catch(()=>({ok:false})))
        .then(function(data){ if (data && data.ok) { location.reload(); } else { alert('Error al guardar festivo'); saveBtn.disabled=false; saveBtn.innerHTML='<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M17 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V7l-4-4zM12 19a3 3 0 1 1 0-6 3 3 0 0 1 0 6z" fill="currentColor"/></svg>'; } })
        .catch(function(){ alert('Error de red'); saveBtn.disabled=false; saveBtn.innerHTML='<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M17 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V7l-4-4zM12 19a3 3 0 1 1 0-6 3 3 0 0 1 0 6z" fill="currentColor"/></svg>'; });
    });
  });
})();
</script>
<?php include __DIR__ . '/footer.php'; ?>
<style>
/* Dev: force visibility of inline edit buttons when hidden by other styles */
.edit-holiday-btn, .edit-year-btn {
  display: inline-block !important;
  visibility: visible !important;
  opacity: 1 !important;
  pointer-events: auto !important;
}
.holiday-actions .btn, .yc-actions .btn { display: inline-block !important; }
/* Highlight variant for edit buttons */
.highlight-edit-btn { background: linear-gradient(90deg,#ffd54d,#ffb74d); color:#1b1b1b; border: 1px solid rgba(0,0,0,0.06); box-shadow: 0 4px 10px rgba(0,0,0,0.08); }
</style>

<style>
/* Ensure edit and delete buttons look identical in both holiday and year lists */
.holiday-actions .highlight-edit-btn,
.yc-actions .highlight-edit-btn {
  padding: 6px 8px !important;
  width: 36px !important;
  height: 36px !important;
  display:inline-flex !important;
  align-items:center; justify-content:center;
}
.holiday-actions .btn-danger,
.yc-actions .btn-danger {
  background-color: var(--danger-color) !important;
  color: #ffffff !important;
  border-color: transparent !important;
  padding: 6px 8px !important;
  width: 36px !important;
  height: 36px !important;
  display:inline-flex !important;
  align-items:center; justify-content:center;
}
.holiday-actions .icon-btn, .yc-actions .icon-btn { padding: 6px 8px !important; }

/* Common delete button helper */
.btn-delete {
  background-color: var(--danger-color) !important;
  color: #ffffff !important;
  border: none !important;
  padding: 6px 8px !important;
  width: 36px !important;
  height: 36px !important;
  display: inline-flex !important;
  align-items: center !important;
  justify-content: center !important;
  border-radius: 6px !important;
}
</style>

<style>
/* Force exact same visuals for edit buttons in both lists */
.holiday-actions .edit-holiday-btn,
.yc-actions .edit-year-btn {
  background: linear-gradient(90deg,#ffd54d,#ffb74d) !important;
  background-image: linear-gradient(90deg,#ffd54d,#ffb74d) !important;
  color: #1b1b1b !important;
  border: 1px solid rgba(0,0,0,0.06) !important;
  box-shadow: 0 4px 10px rgba(0,0,0,0.08) !important;
  padding: 6px 8px !important;
  width: 36px !important;
  height: 36px !important;
  display: inline-flex !important;
  align-items: center !important;
  justify-content: center !important;
  background-repeat: no-repeat !important;
}
</style>

<style>
/* Prevent responsive hiding of the actions column for tables marked no-hide-actions */
@media (max-width: 1100px) {
  .sheet.compact.no-hide-actions th:nth-child(5), .sheet.compact.no-hide-actions td:nth-child(5) { display: table-cell !important; }
}
@media (max-width: 900px) {
  .sheet.compact.no-hide-actions th:nth-child(5), .sheet.compact.no-hide-actions td:nth-child(5) { display: table-cell !important; }
}
@media (max-width: 700px) {
  .sheet.compact.no-hide-actions th:nth-child(5), .sheet.compact.no-hide-actions td:nth-child(5) { display: table-cell !important; }
}
</style>

<script>
// Diagnostic: check if edit buttons are present and visible; if not, show a small banner and force visibility
document.addEventListener('DOMContentLoaded', function(){
  function isVisible(el){ if(!el) return false; const cs = window.getComputedStyle(el); return cs && cs.display !== 'none' && cs.visibility !== 'hidden' && parseFloat(cs.opacity || '1') > 0.05; }
  const yearBtns = Array.from(document.querySelectorAll('.edit-year-btn'));
  const holBtns = Array.from(document.querySelectorAll('.edit-holiday-btn'));
  const anyVisible = yearBtns.concat(holBtns).some(isVisible);
  if (!anyVisible) {
    console.warn('No visible edit buttons detected; forcing visibility');
    // force styles
    yearBtns.concat(holBtns).forEach(function(b){ if(b){ b.style.display='inline-block'; b.style.visibility='visible'; b.style.opacity='1'; b.style.pointerEvents='auto'; b.classList.add('highlight-edit-btn'); } });
    // show banner
    const ban = document.createElement('div'); ban.id='editButtonsBanner'; ban.style.position='fixed'; ban.style.left='12px'; ban.style.right='12px'; ban.style.top='12px'; ban.style.zIndex='99999'; ban.style.background='#fff3cd'; ban.style.color='#856404'; ban.style.border='1px solid #ffeeba'; ban.style.padding='10px 14px'; ban.style.borderRadius='6px'; ban.style.boxShadow='0 6px 18px rgba(0,0,0,0.08)'; ban.textContent = 'Atención: botones de edición estaban ocultos; los he forzado visibles para diagnóstico. Por favor recarga la página (Ctrl+F5) y comprueba si persisten.';
    const close = document.createElement('button'); close.textContent='Cerrar'; close.style.marginLeft='12px'; close.className='btn-secondary'; close.addEventListener('click', function(){ ban.remove(); }); ban.appendChild(close);
    document.body.appendChild(ban);
  }
});
</script>
</style>
</body>
</html>
