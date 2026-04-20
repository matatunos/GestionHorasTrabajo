<?php
/**
 * guardias-compensacion.php — Compensación de horas de guardia
 *
 * Calcula las horas de compensación debidas por trabajar en días de guardia,
 * aplicando multiplicadores según convenio Tragsatec/TRAGSA:
 *   Laborable ×1.25 · Fin de semana ×1.50 · Festivo ×1.75
 *
 * Compara con días ya compensados (absence_type = 'ExcesoHorasGuardias')
 * y muestra el saldo pendiente.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib.php';
require_login();

$user = current_user();
$pdo  = get_pdo();

$year = isset($_GET['year']) && ctype_digit($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

// --- Multiplicadores del convenio (ajustables aquí) ---
$MULT_LABORABLE = 1.25;
$MULT_FINDE     = 1.50;
$MULT_FESTIVO   = 1.75;
$HORAS_DIA_COMP = 8.0; // Horas que se estiman por cada día de ExcesoHorasGuardias tomado

// --- Guardia days del año con sus entradas ---
$stmt = $pdo->prepare("
    SELECT h.date,
           h.label,
           e.start,
           e.end,
           e.coffee_out,
           e.coffee_in,
           e.lunch_out,
           e.lunch_in
    FROM holidays h
    LEFT JOIN entries e ON e.date = h.date AND e.user_id = :uid
    WHERE h.type = 'guardia'
      AND YEAR(h.date) = :year
    ORDER BY h.date ASC
");
$stmt->execute([':uid' => $user['id'], ':year' => $year]);
$guardias_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- Festivos del año (para clasificar el día de guardia) ---
$fstmt = $pdo->prepare("
    SELECT date, annual FROM holidays
    WHERE type IN ('holiday','FiestasLocales','FiestasConvenio','TurnoNavidad','FiestasAcordadas','FiestaAcordada')
      AND (YEAR(date) = :yr OR annual = 1)
      AND (user_id IS NULL OR user_id = :uid)
");
$fstmt->execute([':yr' => $year, ':uid' => $user['id']]);
$festivos_raw = $fstmt->fetchAll(PDO::FETCH_ASSOC);
$festivos = [];
foreach ($festivos_raw as $f) {
    if ($f['annual']) {
        $festivos[$year . substr($f['date'], 4)] = true;
    }
    $festivos[$f['date']] = true;
}

// --- Días de ExcesoHorasGuardias tomados este año ---
$cstmt = $pdo->prepare("
    SELECT COUNT(*) FROM entries
    WHERE user_id = :uid
      AND YEAR(date) = :yr
      AND absence_type = 'ExcesoHorasGuardias'
");
$cstmt->execute([':uid' => $user['id'], ':yr' => $year]);
$dias_compensados = (int)$cstmt->fetchColumn();

// --- Calcular fila por fila ---
$rows                     = [];
$total_horas_trabajadas   = 0.0;
$total_horas_compensacion = 0.0;
$dias_nombre = ['', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];

foreach ($guardias_raw as $g) {
    $ds  = $g['date'];
    $dow = (int)date('N', strtotime($ds));

    // Clasificar tipo de día
    if (isset($festivos[$ds])) {
        $tipo     = ($dow >= 6) ? 'Festivo+Finde' : 'Festivo';
        $mult     = $MULT_FESTIVO;
        $css_tipo = 'danger';
    } elseif ($dow >= 6) {
        $tipo     = ($dow === 6) ? 'Sábado' : 'Domingo';
        $mult     = $MULT_FINDE;
        $css_tipo = 'warning';
    } else {
        $tipo     = 'Laborable';
        $mult     = $MULT_LABORABLE;
        $css_tipo = 'muted';
    }

    // Horas trabajadas (solo si tiene start y end)
    $horas      = 0.0;
    $trabajado  = ($g['start'] && $g['end']);
    if ($trabajado) {
        $min = time_to_minutes($g['end']) - time_to_minutes($g['start']);
        if ($g['coffee_out'] && $g['coffee_in']) {
            $min -= max(0, time_to_minutes($g['coffee_in']) - time_to_minutes($g['coffee_out']));
        }
        if ($g['lunch_out'] && $g['lunch_in']) {
            $min -= max(0, time_to_minutes($g['lunch_in']) - time_to_minutes($g['lunch_out']));
        }
        $horas = round(max(0, $min) / 60, 2);
    }

    $comp_horas = round($horas * $mult, 2);
    $total_horas_trabajadas   += $horas;
    $total_horas_compensacion += $comp_horas;

    $rows[] = compact('ds', 'dow', 'tipo', 'css_tipo', 'mult', 'horas', 'comp_horas', 'trabajado') + [
        'start' => $g['start'],
        'end'   => $g['end'],
    ];
}

// --- Balance ---
$horas_ya_comp  = $dias_compensados * $HORAS_DIA_COMP;
$horas_saldo    = $total_horas_compensacion - $horas_ya_comp;

// --- Selector de años ---
$ayrs = $pdo->query("SELECT DISTINCT YEAR(date) y FROM holidays WHERE type='guardia' ORDER BY y DESC");
$anios = $ayrs->fetchAll(PDO::FETCH_COLUMN);
if (empty($anios)) $anios = [(int)date('Y')];
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Compensación de Guardias — Gestión de Horas</title>
  <link rel="icon" type="image/svg+xml" href="images/favicon.svg">
  <link rel="stylesheet" href="styles.css?v=<?php echo filemtime(__DIR__.'/styles.css'); ?>">
  <style>
    .gc-badge {
      display:inline-block;padding:2px 9px;border-radius:12px;
      font-size:.78rem;font-weight:600;
    }
    .gc-danger  { background:#c0392b22;color:#fc8181; }
    .gc-warning { background:#c0832233;color:#f6ad55; }
    .gc-muted   { background:#2d374866;color:#94a3b8; }
    .gc-stat-grid {
      display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));
      gap:14px;margin-bottom:24px;
    }
    .gc-stat-card { text-align:center;background:var(--bg-secondary,#1e293b);border-radius:10px;padding:16px 10px; }
    .gc-stat-val  { font-size:1.8rem;font-weight:700; }
    .gc-stat-lbl  { font-size:.8rem;color:var(--text-muted,#94a3b8);margin-top:2px; }
    @media print {
      .app-container .sidebar, header.header, .mobile-menu-toggle { display:none !important; }
      .main-content { margin-left:0 !important; }
      .gc-noprint { display:none !important; }
    }
  </style>
</head>
<body class="page-guardias-compensacion">
<?php include __DIR__ . '/header.php'; ?>

<div class="container" style="max-width:1100px;">
  <div class="card">

    <!-- Cabecera -->
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
      <h2 style="margin:0;">⚖️ Compensación de Guardias</h2>
      <div style="display:flex;gap:8px;align-items:center;" class="gc-noprint">
        <form method="get" style="display:flex;align-items:center;gap:8px;">
          <label class="form-label" style="margin:0;">Año
            <select name="year" class="form-control" style="width:90px;" onchange="this.form.submit()">
              <?php foreach ($anios as $a): ?>
                <option value="<?= $a ?>" <?= $a == $year ? 'selected' : '' ?>><?= $a ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </form>
        <button class="btn btn-primary" onclick="window.print()" style="white-space:nowrap;">🖨️ Imprimir</button>
      </div>
    </div>

    <div class="card-body">

      <!-- Tarjetas de resumen -->
      <div class="gc-stat-grid">
        <div class="gc-stat-card">
          <div class="gc-stat-val" style="color:var(--accent,#4a9eff);"><?= count(array_filter($rows, fn($r) => $r['trabajado'])) ?></div>
          <div class="gc-stat-lbl">Guardias trabajadas</div>
        </div>
        <div class="gc-stat-card">
          <div class="gc-stat-val" style="color:var(--accent,#4a9eff);"><?= number_format($total_horas_trabajadas, 1) ?>h</div>
          <div class="gc-stat-lbl">Horas trabajadas</div>
        </div>
        <div class="gc-stat-card">
          <div class="gc-stat-val" style="color:#68d391;"><?= number_format($total_horas_compensacion, 1) ?>h</div>
          <div class="gc-stat-lbl">Horas a compensar</div>
        </div>
        <div class="gc-stat-card">
          <div class="gc-stat-val" style="color:#f6ad55;"><?= $dias_compensados ?> días</div>
          <div class="gc-stat-lbl">ExcesoGuardias tomados</div>
        </div>
        <div class="gc-stat-card">
          <?php $saldo_color = $horas_saldo > 0 ? '#fc8181' : ($horas_saldo < 0 ? '#f6ad55' : '#68d391'); ?>
          <div class="gc-stat-val" style="color:<?= $saldo_color ?>;">
            <?php if ($horas_saldo > 0): ?>+<?= number_format($horas_saldo, 1) ?>h
            <?php elseif ($horas_saldo < 0): ?><?= number_format($horas_saldo, 1) ?>h
            <?php else: ?>✓<?php endif; ?>
          </div>
          <div class="gc-stat-lbl">Saldo pendiente</div>
        </div>
      </div>

      <!-- Nota informativa -->
      <details style="margin-bottom:16px;background:var(--bg-secondary,#1e293b);border-radius:8px;padding:10px 14px;" class="gc-noprint">
        <summary style="cursor:pointer;color:var(--text-muted,#94a3b8);font-size:.85rem;">
          ℹ️ Criterio de cálculo (Convenio Tragsatec/TRAGSA)
        </summary>
        <div style="margin-top:8px;font-size:.85rem;line-height:1.8;color:var(--text-muted,#94a3b8);">
          <b>Laborable:</b> ×<?= $MULT_LABORABLE ?> &nbsp;·&nbsp;
          <b>Fin de semana:</b> ×<?= $MULT_FINDE ?> &nbsp;·&nbsp;
          <b>Festivo:</b> ×<?= $MULT_FESTIVO ?><br>
          Las horas de compensación se calculan sobre las horas efectivamente trabajadas en cada día de guardia.<br>
          Los días <em>ExcesoHorasGuardias</em> se estiman a <?= $HORAS_DIA_COMP ?>h por día.
          El saldo pendiente = horas a compensar − (días compensados × <?= $HORAS_DIA_COMP ?>h).
        </div>
      </details>

      <!-- Tabla -->
      <?php if (empty($rows)): ?>
        <div style="text-align:center;padding:40px;color:var(--text-muted,#94a3b8);">
          No hay guardias registradas en <?= $year ?>.
        </div>
      <?php else: ?>
      <div style="overflow-x:auto;">
        <table class="sheet" style="width:100%;min-width:580px;">
          <thead>
            <tr>
              <th>Fecha</th>
              <th>Día</th>
              <th>Tipo</th>
              <th>Entrada</th>
              <th>Salida</th>
              <th style="text-align:right;">H. Trabaj.</th>
              <th style="text-align:center;">Mult.</th>
              <th style="text-align:right;">H. Comp.</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
            <tr>
              <td><?= date('d/m/Y', strtotime($r['ds'])) ?></td>
              <td style="color:var(--text-muted,#94a3b8);"><?= $dias_nombre[$r['dow']] ?></td>
              <td><span class="gc-badge gc-<?= htmlspecialchars($r['css_tipo']) ?>"><?= htmlspecialchars($r['tipo']) ?></span></td>
              <td><?= $r['start'] ? substr($r['start'], 0, 5) : '<span style="color:#4a5568">—</span>' ?></td>
              <td><?= $r['end']   ? substr($r['end'],   0, 5) : '<span style="color:#4a5568">—</span>' ?></td>
              <td style="text-align:right;">
                <?= $r['trabajado'] ? number_format($r['horas'], 2).'h' : '<span style="color:#4a5568">—</span>' ?>
              </td>
              <td style="text-align:center;color:var(--text-muted,#94a3b8);">×<?= $r['mult'] ?></td>
              <td style="text-align:right;font-weight:600;color:<?= $r['comp_horas'] > 0 ? '#68d391' : '#4a5568' ?>;">
                <?= $r['comp_horas'] > 0 ? number_format($r['comp_horas'], 2).'h' : '—' ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr style="font-weight:700;border-top:2px solid var(--border,#2d3748);">
              <td colspan="5" style="text-align:right;color:var(--text-muted,#94a3b8);">Total:</td>
              <td style="text-align:right;"><?= number_format($total_horas_trabajadas, 2) ?>h</td>
              <td></td>
              <td style="text-align:right;color:#68d391;"><?= number_format($total_horas_compensacion, 2) ?>h</td>
            </tr>
          </tfoot>
        </table>
      </div>
      <?php endif; ?>

    </div><!-- /card-body -->
  </div><!-- /card -->
</div>

<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
