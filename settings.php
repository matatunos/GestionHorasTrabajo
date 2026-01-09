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
<script src="js/settings-edit-years.js"></script>

<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>

