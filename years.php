<?php
// minimal access logging for debugging recalc POST from UI
try {
  $lp = __DIR__ . '/recalc_access.log';
  $fallback = __DIR__ . '/../recalc_access.log';
  $data = [
    'ts' => strftime('%Y-%m-%d %H:%M:%S'),
    'remote' => $_SERVER['REMOTE_ADDR'] ?? 'cli',
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'UNK',
    'uri' => $_SERVER['REQUEST_URI'] ?? '',
    'get' => $_GET,
    'post' => $_POST,
  ];
  $j = json_encode($data, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  // removed temp-file debug writes; log to PHP error log instead
  error_log($j);
} catch (Throwable $e) { /* ignore */ }
// register handlers to capture fatal errors / exceptions for web requests
set_error_handler(function($sev, $msg, $file, $line){
  $d = ['type'=>'php_error','sev'=>$sev,'msg'=>$msg,'file'=>$file,'line'=>$line,'ts'=>strftime('%Y-%m-%d %H:%M:%S')];
  error_log(json_encode($d, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
});
set_exception_handler(function($e){
  $d = ['type'=>'exception','msg'=>$e->getMessage(),'file'=>$e->getFile(),'line'=>$e->getLine(),'trace'=>$e->getTraceAsString(),'ts'=>strftime('%Y-%m-%d %H:%M:%S')];
  error_log(json_encode($d, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
});
register_shutdown_function(function(){
  $err = error_get_last();
  if ($err) {
    $d = ['type'=>'shutdown','err'=>$err,'ts'=>strftime('%Y-%m-%d %H:%M:%S')];
    error_log(json_encode($d, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
  }
});
// Redirect normal web browsing requests to settings.php (keep CLI and AJAX recalc)
if (php_sapi_name() !== 'cli') {
  $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest';
  $is_recalc_post = (!empty($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'recalc');
  if (!($is_ajax && $is_recalc_post)) {
    header('Location: settings.php'); exit;
  }
}
require_once __DIR__ . '/auth.php';
require_admin();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib.php';

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
  // parse hours which may be given as decimal (7.5) or as H:MM (7:30)
  $parse_hours = function($v) {
    if ($v === '' || $v === null) return null;
    $v = trim($v);
    if ($v === '') return null;
    if (strpos($v, ':') !== false) {
      $parts = explode(':', $v);
      $h = intval($parts[0]);
      $m = isset($parts[1]) ? intval($parts[1]) : 0;
      return $h + ($m / 60.0);
    }
    // accept comma as decimal separator
    $v = str_replace(',', '.', $v);
    return floatval($v);
  };
  $mt = $parse_hours($_POST['mon_thu'] ?? '');
  $fr = $parse_hours($_POST['friday'] ?? '');
  $smt = $parse_hours($_POST['summer_mon_thu'] ?? '');
  $sfr = $parse_hours($_POST['summer_friday'] ?? '');
    $parse_minutes = function($v) {
      if ($v === '' || $v === null) return null;
      $v = trim($v);
      if ($v === '') return null;
      // allow H:MM or MM
      if (strpos($v, ':') !== false) {
        $parts = explode(':', $v);
        $h = intval($parts[0]);
        $m = isset($parts[1]) ? intval($parts[1]) : 0;
        return $h * 60 + $m;
      }
      // remove non-digits and parse
      $only = preg_replace('/[^0-9]/','',$v);
      return intval($only);
    };
    $cm = $parse_minutes($_POST['coffee_minutes'] ?? '');
    $lm = $parse_minutes($_POST['lunch_minutes'] ?? '');

    $stmt = $pdo->prepare('REPLACE INTO year_configs (year, mon_thu, friday, summer_mon_thu, summer_friday, coffee_minutes, lunch_minutes) VALUES (?,?,?,?,?,?,?)');
    $stmt->execute([$y, $mt, $fr, $smt, $sfr, $cm, $lm]);
    header('Location: years.php?msg=' . urlencode('Año guardado')); exit;
}

  // Recompute cached summaries for the given year (admin action)
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'recalc' && isset($_POST['recalc_year'])) {
    try {
      $ry = intval($_POST['recalc_year']);
      // basic web-trigger logging to debug duplicate execution
      $who = (function(){ try { $cu = current_user(); return $cu ? ($cu['id'].'/'.$cu['username']) : 'anon'; } catch(Throwable $e){ return 'unk'; } })();
      $logMsg = strftime('%Y-%m-%d %H:%M:%S') . " RECALC START year={$ry} by={$who} \n";
      $logPaths = [__DIR__ . '/recalc_web.log'];
      // log to PHP error log instead of temp files
      error_log($logMsg);
      $pdo->exec("CREATE TABLE IF NOT EXISTS app_settings (
              name VARCHAR(191) PRIMARY KEY,
              value TEXT NOT NULL,
              updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
      // fetch all users
      $users = $pdo->query('SELECT id FROM users')->fetchAll();
      foreach ($users as $u) {
        $uid = $u['id'];
        $msg = strftime('%Y-%m-%d %H:%M:%S') . " RECALC user_start uid={$uid}\n";
        error_log($msg);
        // compute monthly totals for this user
        $months = array_fill(1,12, ['worked'=>0,'expected'=>0]);
        $cfg = get_year_config($ry);
        $dt = new DateTimeImmutable("$ry-01-01");
        $end = new DateTimeImmutable("$ry-12-31");
        for ($cur = $dt; $cur <= $end; $cur = $cur->modify('+1 day')) {
          $d = $cur->format('Y-m-d'); $m = intval($cur->format('n'));
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
        $msg2 = strftime('%Y-%m-%d %H:%M:%S') . " RECALC user_done uid={$uid}\n";
        error_log($msg2);
      }
      $endMsg = strftime('%Y-%m-%d %H:%M:%S') . " RECALC END year={$ry}\n\n";
      error_log($endMsg);
      if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
        header('Content-Type: application/json'); echo json_encode(['ok' => true]); exit;
      }
      header('Location: years.php?msg=' . urlencode('Recalculación completada')); exit;
    } catch (Throwable $e) {
      $err = ['ts'=>strftime('%Y-%m-%d %H:%M:%S'),'error'=>$e->getMessage(),'file'=>$e->getFile(),'line'=>$e->getLine(),'trace'=>$e->getTraceAsString()];
      error_log(json_encode($err, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
      if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
        header('Content-Type: application/json'); http_response_code(500); echo json_encode(['ok'=>false,'error'=>'server_exception','msg'=>$e->getMessage()]); exit;
      }
      http_response_code(500); echo 'Internal Server Error'; exit;
    }
  }

$rows = $pdo->query('SELECT * FROM year_configs ORDER BY year DESC')->fetchAll();
$msg = $_GET['msg'] ?? '';
// prepare form defaults: if a year param is given and has a row use it, otherwise fall back to global settings
$form_year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
$form_defaults = [
  'year' => $form_year,
  'mon_thu' => '',
  'friday' => '',
  'summer_mon_thu' => '',
  'summer_friday' => '',
  'coffee_minutes' => '',
  'lunch_minutes' => ''
];
try {
  $stmt = $pdo->prepare('SELECT * FROM year_configs WHERE year = ? LIMIT 1');
  $stmt->execute([$form_year]);
  $yr = $stmt->fetch();
  if ($yr) {
    $form_defaults['mon_thu'] = $yr['mon_thu'];
    $form_defaults['friday'] = $yr['friday'];
    $form_defaults['summer_mon_thu'] = $yr['summer_mon_thu'];
    $form_defaults['summer_friday'] = $yr['summer_friday'];
    $form_defaults['coffee_minutes'] = $yr['coffee_minutes'];
    $form_defaults['lunch_minutes'] = $yr['lunch_minutes'];
  } else {
    // fallback to global settings
    $cfg = get_config();
    if (!empty($cfg['work_hours'])) {
      $form_defaults['mon_thu'] = $cfg['work_hours']['winter']['mon_thu'] ?? $cfg['work_hours']['summer']['mon_thu'] ?? '';
      $form_defaults['friday'] = $cfg['work_hours']['winter']['friday'] ?? $cfg['work_hours']['summer']['friday'] ?? '';
      $form_defaults['summer_mon_thu'] = $cfg['work_hours']['summer']['mon_thu'] ?? $form_defaults['mon_thu'];
      $form_defaults['summer_friday'] = $cfg['work_hours']['summer']['friday'] ?? $form_defaults['friday'];
    }
    $form_defaults['coffee_minutes'] = $cfg['coffee_minutes'] ?? '';
    $form_defaults['lunch_minutes'] = $cfg['lunch_minutes'] ?? '';
  }
} catch (Throwable $e) { /* ignore */ }
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
        <div class="form-group"><label class="form-label">Horas Invierno (mon-thu)</label><input class="form-control" name="mon_thu" placeholder="ej. 7:30 o 7.5"></div>
        <div class="form-group"><label class="form-label">Horas Invierno Viernes</label><input class="form-control" name="friday" placeholder="ej. 6.0"></div>
        <div class="form-group"><label class="form-label">Horas Verano (mon-thu)</label><input class="form-control" name="summer_mon_thu" placeholder="ej. 7.5"></div>
        <div class="form-group"><label class="form-label">Horas Verano Viernes</label><input class="form-control" name="summer_friday" placeholder="ej. 6.0"></div>
        <div class="form-group"><label class="form-label">Minutos café</label><input class="form-control" name="coffee_minutes" placeholder="ej. 15 o 0:15"></div>
        <div class="form-group"><label class="form-label">Minutos comida</label><input class="form-control" name="lunch_minutes" placeholder="ej. 30 o 0:30"></div>
          <div class="form-group"><label class="form-label">Año</label><input class="form-control" name="year" required value="<?php echo htmlspecialchars($form_defaults['year']); ?>"></div>
          <div class="form-group"><label class="form-label">Horas Invierno (mon-thu)</label><input class="form-control" name="mon_thu" placeholder="ej. 7:30 o 7.5" value="<?php echo htmlspecialchars($form_defaults['mon_thu']); ?>"></div>
          <div class="form-group"><label class="form-label">Horas Invierno Viernes</label><input class="form-control" name="friday" placeholder="ej. 6:00 o 6" value="<?php echo htmlspecialchars($form_defaults['friday']); ?>"></div>
          <div class="form-group"><label class="form-label">Horas Verano (mon-thu)</label><input class="form-control" name="summer_mon_thu" placeholder="ej. 7:30 o 7.5" value="<?php echo htmlspecialchars($form_defaults['summer_mon_thu']); ?>"></div>
          <div class="form-group"><label class="form-label">Horas Verano Viernes</label><input class="form-control" name="summer_friday" placeholder="ej. 6:00 o 6" value="<?php echo htmlspecialchars($form_defaults['summer_friday']); ?>"></div>
          <div class="form-group"><label class="form-label">Minutos café</label><input class="form-control" name="coffee_minutes" placeholder="ej. 15 o 0:15" value="<?php echo htmlspecialchars($form_defaults['coffee_minutes']); ?>"></div>
          <div class="form-group"><label class="form-label">Minutos comida</label><input class="form-control" name="lunch_minutes" placeholder="ej. 30 o 0:30" value="<?php echo htmlspecialchars($form_defaults['lunch_minutes']); ?>"></div>
      </div>
      <div class="form-actions" style="margin-top:12px;"><button class="btn-primary" type="submit">Guardar año</button></div>
    </form>

    <div style="margin-top:12px;">
      <form id="recalc-form" method="post" class="form-wrapper" style="display:flex;gap:8px;align-items:center;">
        <input type="hidden" name="action" value="recalc">
        <label class="form-label">Recalcular estadísticas para año
          <select name="recalc_year">
            <?php for ($yy = date('Y')-2; $yy <= date('Y')+2; $yy++): ?>
              <option value="<?php echo $yy; ?>"><?php echo $yy; ?></option>
            <?php endfor; ?>
          </select>
        </label>
          <button class="btn-primary" type="submit">Recalcular estadísticas</button>
          <button id="recalc-test-btn" type="button" class="btn-secondary" style="margin-left:8px;" onclick="window.location='/recalc_test.php?quick=1'">Test conexión</button>
          <span id="recalc-probe" style="margin-left:10px;color:#666;font-size:90%">JS: idle</span>
      </form>
    </div>

    <h3>Listado</h3>
    <div class="table-responsive">
      <form method="post" class="form-wrapper">
        <div class="form-grid">
          <div class="form-group"><label class="form-label">Año</label><input class="form-control" name="year" required value="<?php echo htmlspecialchars($form_defaults['year']); ?>"></div>
          <div class="form-group"><label class="form-label">Horas Invierno (mon-thu)</label><input class="form-control" name="mon_thu" placeholder="ej. 7:30 o 7.5" value="<?php echo htmlspecialchars($form_defaults['mon_thu']); ?>"></div>
          <div class="form-group"><label class="form-label">Horas Invierno Viernes</label><input class="form-control" name="friday" placeholder="ej. 6:00 o 6" value="<?php echo htmlspecialchars($form_defaults['friday']); ?>"></div>
          <div class="form-group"><label class="form-label">Horas Verano (mon-thu)</label><input class="form-control" name="summer_mon_thu" placeholder="ej. 7:30 o 7.5" value="<?php echo htmlspecialchars($form_defaults['summer_mon_thu']); ?>"></div>
          <div class="form-group"><label class="form-label">Horas Verano Viernes</label><input class="form-control" name="summer_friday" placeholder="ej. 6:00 o 6" value="<?php echo htmlspecialchars($form_defaults['summer_friday']); ?>"></div>
          <div class="form-group"><label class="form-label">Minutos café</label><input class="form-control" name="coffee_minutes" placeholder="ej. 15 o 0:15" value="<?php echo htmlspecialchars($form_defaults['coffee_minutes']); ?>"></div>
          <div class="form-group"><label class="form-label">Minutos comida</label><input class="form-control" name="lunch_minutes" placeholder="ej. 30 o 0:30" value="<?php echo htmlspecialchars($form_defaults['lunch_minutes']); ?>"></div>
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
<script>
// Ensure recalc form submits via fetch so we can see the network request and get JSON response
(function(){
  try {
    const qs = document.querySelector.bind(document);
    const formInput = qs('form input[name="action"][value="recalc"]');
    const form = document.getElementById('recalc-form') || (formInput ? formInput.closest('form') : null);
    if (!form) return;
    console.log('setup recalc handler bound');
    var probe = document.getElementById('recalc-probe'); if (probe) probe.textContent = 'JS: bound';
    var testBtn = document.getElementById('recalc-test-btn');
    if (testBtn) {
      testBtn.addEventListener('click', function(ev){
        try { console.log('recalc-test clicked'); } catch(e){}
        fetch('/recalc_test.php').then(function(r){ return r.json(); }).then(function(j){ console.log('recalc_test response', j); alert('Test conexión: ' + (j && j.ok ? 'OK' : 'NOOK')); }).catch(function(err){ console.error('recalc_test fetch error', err); alert('Error de red en test'); });
      });
    }
    form.addEventListener('submit', function(e){
      e.preventDefault();
      console.log('recalc: submit intercepted');
      const fd = new FormData(form);
      fetch(location.pathname, { method: 'POST', body: fd, headers: {'X-Requested-With':'XMLHttpRequest'} })
        .then(r => {
          console.log('recalc: response status', r.status, 'url', r.url);
          return r.text().then(text => ({status: r.status, url: r.url, text, ct: r.headers.get('Content-Type')||''}));
        })
        .then(obj => {
          console.log('recalc: raw response', obj);
          try {
            const j = JSON.parse(obj.text);
            console.log('recalc: parsed json', j);
            if (j && j.ok) { alert('Recalculo completado'); location.reload(); return; }
            alert('Error en recálculo: servidor respondió OK=false'); location.reload();
          } catch(e) {
            alert('Error en recálculo: respuesta no JSON, revise consola'); console.error('recalc: non-json response', obj.text); location.reload();
          }
        })
        .catch(err => { console.error('recalc error', err); alert('Error de red en recalc'); });
    });
  } catch(e) { console.error('setup recalc handler', e); }
})();
</script>

<?php include __DIR__ . '/footer.php'; ?>
