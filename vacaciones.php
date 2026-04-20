<?php
/**
 * vacaciones.php — Planificador de Vacaciones
 *
 * Vista anual de calendario para marcar y gestionar días de vacaciones.
 * Los días se guardan en la tabla holidays con type='vacation' y user_id del usuario.
 * Días disponibles por convenio Tragsatec: 22 laborables/año.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib.php';
require_login();

$user = current_user();
$pdo  = get_pdo();

// --- Manejo AJAX para toggle de días ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    $input  = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? '';
    $date   = $input['date']   ?? '';

    if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Fecha inválida']);
        exit;
    }

    if ($action === 'add') {
        $chk = $pdo->prepare("SELECT id FROM holidays WHERE user_id=? AND date=? AND type='vacation'");
        $chk->execute([$user['id'], $date]);
        if (!$chk->fetch()) {
            $ins = $pdo->prepare("INSERT INTO holidays (user_id, date, label, type, annual) VALUES (?,?,'Vacaciones','vacation',0)");
            $ins->execute([$user['id'], $date]);
        }
        echo json_encode(['ok' => true, 'action' => 'added']);
    } elseif ($action === 'remove') {
        $del = $pdo->prepare("DELETE FROM holidays WHERE user_id=? AND date=? AND type='vacation'");
        $del->execute([$user['id'], $date]);
        echo json_encode(['ok' => true, 'action' => 'removed']);
    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Acción inválida']);
    }
    exit;
}

// --- Lógica principal ---
$year = isset($_GET['year']) && ctype_digit($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

// Días de vacaciones disponibles — Convenio Tragsatec: 22 días laborables
$DIAS_DISPONIBLES = 22;

// Vacaciones del usuario para el año
$stmt = $pdo->prepare("
    SELECT DATE_FORMAT(date, '%Y-%m-%d') AS d
    FROM holidays
    WHERE user_id = ? AND type = 'vacation' AND YEAR(date) = ?
    ORDER BY date
");
$stmt->execute([$user['id'], $year]);
$vacaciones = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));

// Festivos del sistema para el año
$fstmt = $pdo->prepare("
    SELECT date, annual FROM holidays
    WHERE type IN ('holiday','FiestasLocales','FiestasConvenio','TurnoNavidad','FiestasAcordadas','FiestaAcordada')
      AND (YEAR(date) = ? OR annual = 1)
      AND (user_id IS NULL OR user_id = ?)
");
$fstmt->execute([$year, $user['id']]);
$festivos_raw = $fstmt->fetchAll(PDO::FETCH_ASSOC);
$festivos = [];
foreach ($festivos_raw as $f) {
    if ($f['annual']) {
        $festivos[$year . substr($f['date'], 4)] = true;
    }
    $festivos[$f['date']] = true;
}

// Contar días laborables de vacaciones (excluye fines de semana y festivos)
$dias_usados = 0;
foreach ($vacaciones as $d => $_) {
    $dow = (int)date('N', strtotime($d));
    if ($dow < 6 && !isset($festivos[$d])) {
        $dias_usados++;
    }
}
$dias_restantes = $DIAS_DISPONIBLES - $dias_usados;

// Años para el selector
$ayrs = $pdo->prepare("SELECT DISTINCT YEAR(date) y FROM entries WHERE user_id=? ORDER BY y DESC");
$ayrs->execute([$user['id']]);
$anios_entries = $ayrs->fetchAll(PDO::FETCH_COLUMN);
$anios = array_unique(array_merge([(int)date('Y'), (int)date('Y') + 1], $anios_entries));
rsort($anios);

$month_names = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
                'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
$today = date('Y-m-d');
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Vacaciones <?= $year ?> — Gestión de Horas</title>
  <link rel="icon" type="image/svg+xml" href="images/favicon.svg">
  <link rel="stylesheet" href="styles.css">
  <style>
    /* --- Grid de meses --- */
    .vac-grid {
      display:grid;
      grid-template-columns:repeat(auto-fill, minmax(220px, 1fr));
      gap:16px;
      margin-top:16px;
    }
    .vac-month {
      background:var(--bg-secondary, #1e293b);
      border-radius:10px;
      padding:14px;
    }
    .vac-month-title {
      font-weight:700;font-size:.9rem;
      text-align:center;margin-bottom:8px;
      color:var(--text-primary, #e2e8f0);
    }
    /* Cabeceras días de semana */
    .vac-cal {
      display:grid;grid-template-columns:repeat(7, 1fr);gap:2px;
    }
    .vac-dow {
      font-size:.65rem;text-align:center;
      color:var(--text-muted, #94a3b8);padding-bottom:2px;font-weight:600;
    }
    /* Celda de día */
    .vac-day {
      aspect-ratio:1;display:flex;align-items:center;justify-content:center;
      font-size:.75rem;border-radius:4px;cursor:pointer;
      transition:filter .12s;user-select:none;border:1px solid transparent;
    }
    .vac-day:hover:not(.vac-empty):not(.vac-finde):not(.vac-festivo) {
      filter:brightness(1.35);
    }
    /* Estados */
    .vac-empty   { cursor:default;pointer-events:none; }
    .vac-finde   { color:var(--text-muted, #64748b);background:transparent;cursor:default; }
    .vac-festivo { background:#c0392b1a;color:#fc8181;cursor:default; }
    .vac-off     { background:var(--bg-card, #2d3748);color:var(--text-secondary, #cbd5e0); }
    .vac-on      { background:#1e4d3a;color:#9ae6b4;font-weight:700; }
    .vac-today   { border-color:var(--accent, #4a9eff) !important; }
    /* Leyenda */
    .vac-legend  { display:flex;gap:14px;flex-wrap:wrap;font-size:.8rem;color:var(--text-muted,#94a3b8);margin-bottom:12px; }
    .vac-dot     { width:11px;height:11px;border-radius:3px;display:inline-block;vertical-align:middle;margin-right:4px; }
    /* Print */
    @media print {
      .app-container .sidebar, header.header, .mobile-menu-toggle,
      form[method=get], .vac-legend, p.vac-help { display:none !important; }
      .main-content { margin-left:0 !important; }
    }
  </style>
</head>
<body class="page-vacaciones">
<?php include __DIR__ . '/header.php'; ?>

<div class="container">
  <div class="card">

    <!-- Cabecera -->
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
      <h2 style="margin:0;">🏖️ Planificador de Vacaciones</h2>
      <form method="get" style="display:flex;align-items:center;gap:8px;">
        <label class="form-label" style="margin:0;">Año
          <select name="year" class="form-control" style="width:90px;" onchange="this.form.submit()">
            <?php foreach ($anios as $a): ?>
              <option value="<?= $a ?>" <?= $a == $year ? 'selected' : '' ?>><?= $a ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </form>
    </div>

    <div class="card-body">

      <!-- Contadores -->
      <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px;">
        <?php
        $stat_items = [
          [$DIAS_DISPONIBLES, 'var(--accent,#4a9eff)', 'Días disponibles'],
          [$dias_usados,       '#68d391',               'Días usados'],
          [$dias_restantes,
           $dias_restantes < 0 ? '#fc8181' : ($dias_restantes <= 5 ? '#f6ad55' : '#e2e8f0'),
           'Días restantes'],
        ];
        foreach ($stat_items as [$val, $col, $lbl]): ?>
        <div class="card" style="flex:1;min-width:120px;text-align:center;background:var(--bg-secondary,#1e293b);">
          <div class="card-body" style="padding:12px;">
            <div style="font-size:1.7rem;font-weight:700;color:<?= $col ?>;"><?= $val ?></div>
            <div style="font-size:.78rem;color:var(--text-muted,#94a3b8);"><?= $lbl ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Leyenda -->
      <div class="vac-legend">
        <span><span class="vac-dot" style="background:#1e4d3a;border:1px solid #68d391;"></span>Vacaciones</span>
        <span><span class="vac-dot" style="background:#c0392b1a;border:1px solid #fc8181;"></span>Festivo</span>
        <span><span class="vac-dot" style="background:var(--bg-card,#2d3748);"></span>Laborable</span>
        <span><span class="vac-dot" style="background:transparent;border:1px solid var(--accent,#4a9eff);"></span>Hoy</span>
        <span style="color:var(--text-muted,#64748b);">Sáb/Dom: no computan</span>
      </div>

      <!-- Cuadrícula de 12 meses -->
      <div class="vac-grid" id="vac-grid">
        <?php for ($m = 1; $m <= 12; $m++):
          $first    = new DateTimeImmutable("$year-$m-01");
          $days_cnt = (int)$first->format('t');
          $start_dow = (int)$first->format('N'); // 1=Lun
        ?>
        <div class="vac-month">
          <div class="vac-month-title"><?= $month_names[$m] ?></div>
          <div class="vac-cal">
            <?php foreach (['L','M','X','J','V','S','D'] as $dl): ?>
              <div class="vac-dow"><?= $dl ?></div>
            <?php endforeach; ?>
            <?php for ($b = 1; $b < $start_dow; $b++): ?>
              <div class="vac-day vac-empty"></div>
            <?php endfor; ?>
            <?php for ($d = 1; $d <= $days_cnt; $d++):
              $ds      = sprintf('%04d-%02d-%02d', $year, $m, $d);
              $dow     = (int)date('N', strtotime($ds));
              $is_finde   = $dow >= 6;
              $is_festivo = !$is_finde && isset($festivos[$ds]);
              $is_vac     = isset($vacaciones[$ds]);
              $is_today   = ($ds === $today);
              $clickable  = !$is_finde && !$is_festivo;
              $cls = 'vac-day';
              if ($is_finde)        $cls .= ' vac-finde';
              elseif ($is_festivo)  $cls .= ' vac-festivo';
              elseif ($is_vac)      $cls .= ' vac-on';
              else                  $cls .= ' vac-off';
              if ($is_today) $cls .= ' vac-today';
            ?>
              <div class="<?= $cls ?>"
                <?= $clickable ? 'data-date="'.$ds.'"' : '' ?>
                title="<?= $ds ?>">
                <?= $d ?>
              </div>
            <?php endfor; ?>
          </div>
        </div>
        <?php endfor; ?>
      </div><!-- /vac-grid -->

      <p class="vac-help" style="font-size:.8rem;color:var(--text-muted,#94a3b8);margin-top:14px;">
        💡 Clic en un día laborable para marcar/desmarcar como vacación.
        Los festivos y fines de semana no cuentan en el cómputo de días disponibles.
      </p>

    </div><!-- /card-body -->
  </div><!-- /card -->
</div>

<!-- Toast de feedback -->
<div id="vac-toast" style="
  position:fixed;bottom:20px;left:50%;transform:translateX(-50%);
  background:#2d3748;color:#fff;border-radius:8px;padding:10px 20px;
  font-size:.9rem;opacity:0;transition:opacity .3s;pointer-events:none;z-index:9999;
"></div>

<script>
(function () {
  'use strict';

  var pendingRequest = false;

  function showToast(msg, bg) {
    var t = document.getElementById('vac-toast');
    t.textContent = msg;
    t.style.background = bg || '#2d3748';
    t.style.opacity = '1';
    clearTimeout(t._timer);
    t._timer = setTimeout(function () { t.style.opacity = '0'; }, 2500);
  }

  document.getElementById('vac-grid').addEventListener('click', function (e) {
    var el = e.target.closest('[data-date]');
    if (!el || pendingRequest) return;

    var date   = el.dataset.date;
    var adding = el.classList.contains('vac-on') ? false : true;
    var action = adding ? 'add' : 'remove';

    pendingRequest = true;
    el.style.opacity = '.4';

    fetch('/vacaciones.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      credentials: 'same-origin',
      body: JSON.stringify({ action: action, date: date })
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      el.style.opacity = '';
      pendingRequest = false;

      if (data.ok) {
        if (action === 'add') {
          el.classList.replace('vac-off', 'vac-on');
          showToast('✅ ' + date + ' → vacación', '#1e4d3a');
        } else {
          el.classList.replace('vac-on', 'vac-off');
          showToast('🗑️ ' + date + ' → desmarcado', '#4a5568');
        }
        // Recargar contador tras 700ms
        setTimeout(function () { window.location.reload(); }, 700);
      } else {
        showToast('⚠️ ' + (data.error || 'Error desconocido'), '#c0392b');
      }
    })
    .catch(function () {
      el.style.opacity = '';
      pendingRequest = false;
      showToast('⚠️ Error de red', '#c0392b');
    });
  });
}());
</script>

<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
