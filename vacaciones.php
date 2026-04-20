<?php
/**
 * vacaciones.php — Planificador de Vacaciones
 *
 * Vista anual de calendario para marcar y gestionar días de vacaciones.
 * Los días se guardan en la tabla holidays con type='vacation' y user_id del usuario.
 * Días disponibles por convenio Tragsatec: 23 laborables/año + 3 días de Libre Disposición.
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
        // --- Validaciones de convenio (solo si es día laborable no festivo) ---
        $cfg           = get_config();
        $max_periodos  = 6;
        $max_verano    = 13;
        $max_invierno  = 10;

        // Cargar festivos del año
        $yr    = substr($date, 0, 4);
        $uid   = $user['id'];
        $fq    = $pdo->prepare("SELECT date, annual FROM holidays
            WHERE type IN ('holiday','FiestasLocales','FiestasConvenio','TurnoNavidad','FiestasAcordadas','FiestaAcordada')
              AND (YEAR(date)=? OR annual=1) AND (user_id IS NULL OR user_id=?)");
        $fq->execute([$yr, $uid]);
        $fests = [];
        foreach ($fq->fetchAll(PDO::FETCH_ASSOC) as $f) {
            if ($f['annual']) $fests[$yr . substr($f['date'], 4)] = true;
            $fests[$f['date']] = true;
        }

        $dow_new = (int)date('N', strtotime($date));
        $es_lab  = ($dow_new < 6 && !isset($fests[$date]));

        if ($es_lab) {
            // Vacaciones actuales del usuario en ese año
            $vq = $pdo->prepare("SELECT DATE_FORMAT(date,'%Y-%m-%d') d FROM holidays
                WHERE user_id=? AND type='vacation' AND YEAR(date)=? ORDER BY date");
            $vq->execute([$uid, $yr]);
            $vac_dates = $vq->fetchAll(PDO::FETCH_COLUMN);

            // ── Límite verano / invierno ──
            if (is_summer_date($date, $cfg)) {
                $cnt = array_sum(array_map(fn($d) => (is_summer_date($d, $cfg) && (int)date('N', strtotime($d)) < 6 && !isset($fests[$d])) ? 1 : 0, $vac_dates));
                if ($cnt >= $max_verano) {
                    echo json_encode(['ok' => false, 'error' => "Límite de {$max_verano} días en horario de verano alcanzado"]);
                    exit;
                }
            } else {
                $cnt = array_sum(array_map(fn($d) => (!is_summer_date($d, $cfg) && (int)date('N', strtotime($d)) < 6 && !isset($fests[$d])) ? 1 : 0, $vac_dates));
                if ($cnt >= $max_invierno) {
                    echo json_encode(['ok' => false, 'error' => "Límite de {$max_invierno} días en horario de invierno alcanzado"]);
                    exit;
                }
            }

            // ── Límite de periodos ──
            if (!in_array($date, $vac_dates)) {
                $sim = $vac_dates;
                $sim[] = $date;
                sort($sim);
                // función inline de conteo de periodos
                $per = 1;
                for ($i = 1; $i < count($sim); $i++) {
                    $prev = strtotime($sim[$i-1]);
                    $curr = strtotime($sim[$i]);
                    $gap_ok = true;
                    for ($t = $prev + 86400; $t < $curr; $t += 86400) {
                        $dd = date('Y-m-d', $t);
                        if ((int)date('N', $t) < 6 && !isset($fests[$dd])) { $gap_ok = false; break; }
                    }
                    if (!$gap_ok) $per++;
                }
                if ($per > $max_periodos) {
                    echo json_encode(['ok' => false, 'error' => "Máximo {$max_periodos} periodos de vacaciones alcanzado"]);
                    exit;
                }
            }
        }

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
    } elseif ($action === 'add_ld') {
        $chk = $pdo->prepare("SELECT id FROM holidays WHERE user_id=? AND date=? AND type='LibreDisposicion'");
        $chk->execute([$user['id'], $date]);
        if (!$chk->fetch()) {
            $ins = $pdo->prepare("INSERT INTO holidays (user_id, date, label, type, annual) VALUES (?,?,'Libre Disposición','LibreDisposicion',0)");
            $ins->execute([$user['id'], $date]);
        }
        echo json_encode(['ok' => true, 'action' => 'added_ld']);
    } elseif ($action === 'remove_ld') {
        $del = $pdo->prepare("DELETE FROM holidays WHERE user_id=? AND date=? AND type='LibreDisposicion'");
        $del->execute([$user['id'], $date]);
        echo json_encode(['ok' => true, 'action' => 'removed_ld']);
    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Acción inválida']);
    }
    exit;
}

// --- Lógica principal ---
$year = isset($_GET['year']) && ctype_digit($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

// Días de vacaciones disponibles — Convenio Tragsatec: 23 días laborables
$DIAS_DISPONIBLES = 23;
// Días de Libre Disposición disponibles
$LD_DISPONIBLES = 3;

// Vacaciones del usuario para el año
$stmt = $pdo->prepare("
    SELECT DATE_FORMAT(date, '%Y-%m-%d') AS d
    FROM holidays
    WHERE user_id = ? AND type = 'vacation' AND YEAR(date) = ?
    ORDER BY date
");
$stmt->execute([$user['id'], $year]);
$vacaciones = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));

// Días de Libre Disposición del usuario para el año
$lstmt = $pdo->prepare("
    SELECT DATE_FORMAT(date, '%Y-%m-%d') AS d
    FROM holidays
    WHERE user_id = ? AND type = 'LibreDisposicion' AND YEAR(date) = ?
    ORDER BY date
");
$lstmt->execute([$user['id'], $year]);
$libre_disp = array_flip($lstmt->fetchAll(PDO::FETCH_COLUMN));

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

// --- Configuración y límites del convenio ---
$config         = get_config();
$MAX_PERIODOS   = 6;   // Máximo número de periodos de vacaciones
$MAX_VERANO     = 13;  // Máximo días de vacaciones en horario de verano
$MAX_INVIERNO   = 10;  // Máximo días de vacaciones en horario de invierno

/**
 * Cuenta los periodos de vacaciones en un array de fechas ordenadas.
 * Un periodo es un bloque de días donde cualquier hueco está compuesto
 * exclusivamente de fines de semana o festivos (no interrumpen el periodo).
 */
function contar_periodos(array $fechas_ordenadas, array $festivos): int {
    if (empty($fechas_ordenadas)) return 0;
    $periodos = 1;
    for ($i = 1, $n = count($fechas_ordenadas); $i < $n; $i++) {
        $prev = strtotime($fechas_ordenadas[$i - 1]);
        $curr = strtotime($fechas_ordenadas[$i]);
        // Recorrer cada día entre las dos fechas (sin incluirlas)
        $solo_no_laborables = true;
        for ($t = $prev + 86400; $t < $curr; $t += 86400) {
            $d   = date('Y-m-d', $t);
            $dow = (int)date('N', $t);
            if ($dow < 6 && !isset($festivos[$d])) {
                $solo_no_laborables = false;
                break;
            }
        }
        if (!$solo_no_laborables) {
            $periodos++;
        }
    }
    return $periodos;
}

// Contar días laborables de vacaciones (excluye fines de semana y festivos)
$dias_usados   = 0;
$dias_verano   = 0;
$dias_invierno = 0;
foreach ($vacaciones as $d => $_) {
    $dow = (int)date('N', strtotime($d));
    if ($dow < 6 && !isset($festivos[$d])) {
        $dias_usados++;
        if (is_summer_date($d, $config)) {
            $dias_verano++;
        } else {
            $dias_invierno++;
        }
    }
}
$dias_restantes = $DIAS_DISPONIBLES - $dias_usados;

// Contar periodos actuales
$fechas_vac_ord = array_keys($vacaciones);
sort($fechas_vac_ord);
$periodos_usados = contar_periodos($fechas_vac_ord, $festivos);

// Contar días de Libre Disposición usados
$ld_usados = 0;
foreach ($libre_disp as $d => $_) {
    $dow = (int)date('N', strtotime($d));
    if ($dow < 6 && !isset($festivos[$d])) {
        $ld_usados++;
    }
}
$ld_restantes = $LD_DISPONIBLES - $ld_usados;

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
  <link rel="stylesheet" href="styles.css?v=<?php echo filemtime(__DIR__.'/styles.css'); ?>">
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
    .vac-ld      { background:#312e81;color:#c4b5fd;font-weight:700; }
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
      <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
        <h2 style="margin:0;">🏖️ Planificador de Vacaciones</h2>
        <div style="display:flex;gap:0;border-radius:6px;overflow:hidden;border:1px solid rgba(255,255,255,0.1);">
          <button id="btn-mode-vac" onclick="setMode('vacation')" type="button"
            style="padding:5px 14px;font-size:.82rem;font-weight:600;border:none;cursor:pointer;background:#1e4d3a;color:#9ae6b4;">
            🏖️ Vacaciones
          </button>
          <button id="btn-mode-ld" onclick="setMode('ld')" type="button"
            style="padding:5px 14px;font-size:.82rem;font-weight:600;border:none;cursor:pointer;background:var(--bg-secondary,#1e293b);color:var(--text-muted,#94a3b8);">
            📋 Libre Disposición
          </button>
        </div>
      </div>
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
      <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-start;margin-bottom:16px;">

        <!-- Vacaciones: Disponibles / Usados / Restantes -->
        <div style="display:flex;gap:8px;flex-wrap:wrap;flex:3;min-width:260px;">
          <div style="font-size:.7rem;color:var(--text-muted,#94a3b8);width:100%;margin-bottom:-4px;
                      font-weight:600;text-transform:uppercase;letter-spacing:.5px;">🏖️ Vacaciones</div>
          <?php
          $stat_vac = [
            [$DIAS_DISPONIBLES, 'var(--accent,#4a9eff)', 'Disponibles'],
            [$dias_usados,       '#68d391',               'Usados'],
            [$dias_restantes,
             $dias_restantes < 0 ? '#fc8181' : ($dias_restantes <= 5 ? '#f6ad55' : '#e2e8f0'),
             'Restantes'],
          ];
          foreach ($stat_vac as [$val, $col, $lbl]): ?>
          <div class="card" style="flex:1;min-width:80px;text-align:center;
                                   background:var(--bg-secondary,#1e293b);border:1px solid #1e4d3a;">
            <div class="card-body" style="padding:10px;">
              <div style="font-size:1.5rem;font-weight:700;color:<?= $col ?>;"><?= $val ?></div>
              <div style="font-size:.75rem;color:var(--text-muted,#94a3b8);"><?= $lbl ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>

        <!-- Distribución: Periodos / Días verano / Días invierno -->
        <div style="display:flex;gap:8px;flex-wrap:wrap;flex:3;min-width:260px;">
          <div style="font-size:.7rem;color:var(--text-muted,#94a3b8);width:100%;margin-bottom:-4px;
                      font-weight:600;text-transform:uppercase;letter-spacing:.5px;">📆 Distribución</div>
          <?php
          $stat_dist = [
            [$periodos_usados . '/' . $MAX_PERIODOS,
             $periodos_usados >= $MAX_PERIODOS ? '#fc8181' : ($periodos_usados >= $MAX_PERIODOS - 1 ? '#f6ad55' : '#e2e8f0'),
             'Periodos'],
            [$dias_verano . '/' . $MAX_VERANO,
             $dias_verano >= $MAX_VERANO ? '#fc8181' : ($dias_verano >= $MAX_VERANO - 2 ? '#f6ad55' : '#68d391'),
             'Días verano'],
            [$dias_invierno . '/' . $MAX_INVIERNO,
             $dias_invierno >= $MAX_INVIERNO ? '#fc8181' : ($dias_invierno >= $MAX_INVIERNO - 2 ? '#f6ad55' : '#68d391'),
             'Días invierno'],
          ];
          foreach ($stat_dist as [$val, $col, $lbl]): ?>
          <div class="card" style="flex:1;min-width:80px;text-align:center;
                                   background:var(--bg-secondary,#1e293b);border:1px solid rgba(255,255,255,0.07);">
            <div class="card-body" style="padding:10px;">
              <div style="font-size:1.2rem;font-weight:700;color:<?= $col ?>;"><?= $val ?></div>
              <div style="font-size:.75rem;color:var(--text-muted,#94a3b8);"><?= $lbl ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>

        <!-- Libre Disposición: Disponibles / Usados / Restantes -->
        <div style="display:flex;gap:8px;flex-wrap:wrap;flex:2;min-width:200px;">
          <div style="font-size:.7rem;color:var(--text-muted,#94a3b8);width:100%;margin-bottom:-4px;
                      font-weight:600;text-transform:uppercase;letter-spacing:.5px;">📋 Libre Disposición</div>
          <?php
          $stat_ld = [
            [$LD_DISPONIBLES, '#a78bfa', 'Disponibles'],
            [$ld_usados,      '#c4b5fd', 'Usados'],
            [$ld_restantes,
             $ld_restantes < 0 ? '#fc8181' : ($ld_restantes <= 1 ? '#f6ad55' : '#e2e8f0'),
             'Restantes'],
          ];
          foreach ($stat_ld as [$val, $col, $lbl]): ?>
          <div class="card" style="flex:1;min-width:60px;text-align:center;
                                   background:var(--bg-secondary,#1e293b);border:1px solid #312e81;">
            <div class="card-body" style="padding:10px;">
              <div style="font-size:1.5rem;font-weight:700;color:<?= $col ?>;"><?= $val ?></div>
              <div style="font-size:.75rem;color:var(--text-muted,#94a3b8);"><?= $lbl ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>

      </div>

      <!-- Leyenda -->
      <div class="vac-legend">
        <span><span class="vac-dot" style="background:#1e4d3a;border:1px solid #68d391;"></span>Vacaciones</span>
        <span><span class="vac-dot" style="background:#312e81;border:1px solid #a78bfa;"></span>Libre disposición</span>
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
              $is_ld      = isset($libre_disp[$ds]);
              $clickable  = !$is_finde && !$is_festivo;
              $cls = 'vac-day';
              if ($is_finde)        $cls .= ' vac-finde';
              elseif ($is_festivo)  $cls .= ' vac-festivo';
              elseif ($is_vac)      $cls .= ' vac-on';
              elseif ($is_ld)       $cls .= ' vac-ld';
              else                  $cls .= ' vac-off';
              if ($is_today) $cls .= ' vac-today';
              // data-dtype: estado actual del día para el JS de modo
              $dtype = $is_vac ? 'vacation' : ($is_ld ? 'ld' : 'free');
            ?>
              <div class="<?= $cls ?>"
                <?= $clickable ? 'data-date="'.$ds.'" data-dtype="'.$dtype.'"' : '' ?>
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
// --- Variables del convenio (generadas por PHP) ---
var VAC_SUMMER_START  = '<?= $config['summer_start'] ?>';   // MM-DD
var VAC_SUMMER_END    = '<?= $config['summer_end'] ?>';     // MM-DD
var VAC_MAX_PERIODOS  = <?= $MAX_PERIODOS ?>;
var VAC_MAX_VERANO    = <?= $MAX_VERANO ?>;
var VAC_MAX_INVIERNO  = <?= $MAX_INVIERNO ?>;
var VAC_DIAS_VERANO   = <?= $dias_verano ?>;
var VAC_DIAS_INVIERNO = <?= $dias_invierno ?>;
var VAC_PERIODOS      = <?= $periodos_usados ?>;
// Festivos como objeto {fecha:true} para no romper periodos
var VAC_FESTIVOS      = <?= json_encode(array_map(fn($_) => true, $festivos)) ?>;
// Fechas de vacaciones actuales (array ordenado) para calcular periodos
var VAC_FECHAS        = <?= json_encode(array_values(array_keys($vacaciones))) ?>;
</script>

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

  // --- Helpers de validación client-side ---

  // Comprueba si una fecha YYYY-MM-DD cae en horario de verano
  function isSummerDate(dateStr) {
    var parts  = dateStr.split('-');
    var year   = parts[0];
    var mmdd   = parts[1] + '-' + parts[2]; // MM-DD del día a comprobar
    return mmdd >= VAC_SUMMER_START && mmdd <= VAC_SUMMER_END;
  }

  // Cuenta los periodos de vacaciones dado un array de fechas YYYY-MM-DD ordenado
  function countPeriods(sortedDates) {
    if (!sortedDates.length) return 0;
    var periods = 1;
    for (var i = 1; i < sortedDates.length; i++) {
      var prev = new Date(sortedDates[i-1] + 'T00:00:00');
      var curr = new Date(sortedDates[i]   + 'T00:00:00');
      var gapOk = true;
      // Recorrer cada día entre prev y curr (sin incluirlos)
      for (var t = new Date(prev.getTime() + 86400000); t < curr; t = new Date(t.getTime() + 86400000)) {
        var d   = t.toISOString().slice(0, 10);
        var dow = t.getDay(); // 0=Dom, 6=Sáb
        if (dow !== 0 && dow !== 6 && !VAC_FESTIVOS[d]) {
          gapOk = false;
          break;
        }
      }
      if (!gapOk) periods++;
    }
    return periods;
  }

  // Valida si se puede añadir una fecha de vacaciones; devuelve string de error o null
  function validarAnadirVacacion(date) {
    var dow = new Date(date + 'T00:00:00').getDay();
    // Si es festivo o fin de semana no hay límite que comprobar (no computa)
    if (dow === 0 || dow === 6 || VAC_FESTIVOS[date]) return null;

    // ── Límite verano / invierno ──
    if (isSummerDate(date)) {
      if (VAC_DIAS_VERANO >= VAC_MAX_VERANO) {
        return 'Límite de ' + VAC_MAX_VERANO + ' días en horario de verano alcanzado';
      }
    } else {
      if (VAC_DIAS_INVIERNO >= VAC_MAX_INVIERNO) {
        return 'Límite de ' + VAC_MAX_INVIERNO + ' días en horario de invierno alcanzado';
      }
    }

    // ── Límite de periodos ──
    if (VAC_FECHAS.indexOf(date) === -1) {
      var sim = VAC_FECHAS.slice();
      sim.push(date);
      sim.sort();
      if (countPeriods(sim) > VAC_MAX_PERIODOS) {
        return 'Máximo ' + VAC_MAX_PERIODOS + ' periodos de vacaciones alcanzado';
      }
    }

    return null; // sin error → se puede añadir
  }

  // --- Modo: vacaciones o libre disposición ---
  var currentMode = 'vacation';

  window.setMode = function (mode) {
    currentMode = mode;
    var btnVac = document.getElementById('btn-mode-vac');
    var btnLd  = document.getElementById('btn-mode-ld');
    if (mode === 'vacation') {
      btnVac.style.background = '#1e4d3a'; btnVac.style.color = '#9ae6b4';
      btnLd.style.background  = 'var(--bg-secondary,#1e293b)'; btnLd.style.color = 'var(--text-muted,#94a3b8)';
    } else {
      btnLd.style.background  = '#312e81'; btnLd.style.color = '#c4b5fd';
      btnVac.style.background = 'var(--bg-secondary,#1e293b)'; btnVac.style.color = 'var(--text-muted,#94a3b8)';
    }
  };

  document.getElementById('vac-grid').addEventListener('click', function (e) {
    var el = e.target.closest('[data-date]');
    if (!el || pendingRequest) return;

    var date  = el.dataset.date;
    var dtype = el.dataset.dtype || 'free'; // 'vacation' | 'ld' | 'free'
    var action, addCls, rmCls, toastOk, toastRm, toastColor;

    if (currentMode === 'vacation') {
      // En modo vacaciones: no tocar días LD ya marcados
      if (dtype === 'ld') {
        showToast('⚠️ Día de Libre Disposición — cambia al modo 📋', '#4a5568');
        return;
      }
      var adding = dtype !== 'vacation';
      action     = adding ? 'add' : 'remove';
      addCls     = 'vac-on'; rmCls = 'vac-off';
      toastOk    = '✅ ' + date + ' → vacación';
      toastRm    = '🗑️ ' + date + ' → desmarcado';
      toastColor = '#1e4d3a';

      // Pre-validación client-side (solo al añadir)
      if (adding) {
        var err = validarAnadirVacacion(date);
        if (err) { showToast('⛔ ' + err, '#7f1d1d'); return; }
      }
    } else {
      // En modo LD: no tocar días de vacaciones ya marcados
      if (dtype === 'vacation') {
        showToast('⚠️ Día de vacación — cambia al modo 🏖️', '#4a5568');
        return;
      }
      var adding = dtype !== 'ld';
      action     = adding ? 'add_ld' : 'remove_ld';
      addCls     = 'vac-ld'; rmCls = 'vac-off';
      toastOk    = '📋 ' + date + ' → libre disposición';
      toastRm    = '🗑️ ' + date + ' → desmarcado';
      toastColor = '#312e81';
    }

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
        if (adding) {
          el.classList.replace('vac-off', addCls);
          el.dataset.dtype = currentMode === 'vacation' ? 'vacation' : 'ld';
          showToast(toastOk, toastColor);
        } else {
          el.classList.replace(addCls, rmCls);
          el.dataset.dtype = 'free';
          showToast(toastRm, '#4a5568');
        }
        setTimeout(function () { window.location.reload(); }, 700);
      } else {
        showToast('⛔ ' + (data.error || 'Error desconocido'), '#7f1d1d');
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
