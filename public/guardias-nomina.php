<?php
/**
 * guardias-nomina.php — Cruce guardias registradas vs. plus guardia en nómina
 *
 * Muestra mes a mes:
 *  - Días de guardia registrados en GestionHorasTrabajo (festivos / finde / laborables)
 *  - Importe Plus Guardia cobrado en nómina (via API CT172)
 *  - Estado: ✓ coincide / ⚠ sin pago / ⚠ sin registro
 */

require_once __DIR__ . '/auth.php';
require_login();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/config.php';

$pdo = get_pdo();

// --- Año seleccionado ---
$all_years_stmt = $pdo->query("
    SELECT DISTINCT YEAR(date) AS y FROM holidays WHERE type='guardia' ORDER BY y DESC
");
$all_years = $all_years_stmt->fetchAll(PDO::FETCH_COLUMN);

$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
if (!in_array($year, $all_years) && !empty($all_years)) {
    $year = (int)$all_years[0];
}

// --- Datos de guardias de este año ---
$current_user = current_user();
$user_id = $current_user['id'];

$stmt = $pdo->prepare("
    SELECT
        h.date,
        DAYOFWEEK(h.date) AS dow,
        CASE
            WHEN EXISTS (
                SELECT 1 FROM holidays f
                WHERE f.date = h.date
                  AND f.type = 'holiday'
                  AND (f.user_id IS NULL OR f.user_id = :uid)
            ) THEN 'festivo'
            WHEN DAYOFWEEK(h.date) IN (1, 7) THEN 'finde'
            ELSE 'laborable'
        END AS tipo_dia,
        COALESCE((
            SELECT f.label FROM holidays f
            WHERE f.date = h.date
              AND f.type = 'holiday'
              AND (f.user_id IS NULL OR f.user_id = :uid2)
            LIMIT 1
        ), '') AS label_festivo
    FROM holidays h
    WHERE h.type = 'guardia'
      AND YEAR(h.date) = :year
      AND (h.user_id IS NULL OR h.user_id = :uid3)
    ORDER BY h.date
");
$stmt->execute([':uid' => $user_id, ':uid2' => $user_id, ':uid3' => $user_id, ':year' => $year]);
$guardias_rows = $stmt->fetchAll();

// Agrupar por mes: $por_mes[$month] = ['total'=>N, 'festivos'=>N, 'laborables'=>N, 'dias'=>[...]]
// Festivos y fines de semana equivalen a efectos de guardia — se unifican en 'festivos'
$por_mes = [];
foreach ($guardias_rows as $g) {
    $m = (int)date('n', strtotime($g['date']));
    if (!isset($por_mes[$m])) {
        $por_mes[$m] = ['total' => 0, 'festivos' => 0, 'laborables' => 0, 'dias' => []];
    }
    $por_mes[$m]['total']++;
    $por_mes[$m][$g['tipo_dia'] === 'laborable' ? 'laborables' : 'festivos']++;
    $por_mes[$m]['dias'][] = $g;
}

// --- Datos de nómina via API CT172 ---
$nomina_por_mes = [];
$nominas_url = "http://nominas.favala.es/api/guardias-data.php?year={$year}";
$ctx = stream_context_create(['http' => ['timeout' => 3]]);
$resp = @file_get_contents($nominas_url, false, $ctx);
if ($resp !== false) {
    $json = json_decode($resp, true);
    if (!empty($json['ok']) && !empty($json['data'])) {
        foreach ($json['data'] as $row) {
            $nomina_por_mes[(int)$row['month']] = $row;
        }
    }
}
$nominas_disponibles = !empty($nomina_por_mes) || $resp !== false;

// Guardias de diciembre se pagan en la nómina de enero del año siguiente.
// Hacemos un fetch adicional para obtener ese dato y cruzarlo correctamente.
$nm_enero_siguiente = null;
$resp_sig = @file_get_contents(
    "http://nominas.favala.es/api/guardias-data.php?year=" . ($year + 1),
    false, $ctx
);
if ($resp_sig !== false) {
    $json_sig = json_decode($resp_sig, true);
    if (!empty($json_sig['ok']) && !empty($json_sig['data'])) {
        foreach ($json_sig['data'] as $row) {
            if ((int)$row['month'] === 1) {
                $nm_enero_siguiente = $row; // enero year+1 = cobro de guardias de diciembre
                break;
            }
        }
    }
}

// --- Nombres de meses en español ---
$meses_es = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
             'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

// --- Meses a mostrar: guardias se pagan a mes vencido (mes M → cobro en nómina M+1) ---
// Vista por mes de guardia. También incluimos meses sin guardia si hay cobro
// en el mes siguiente sin guardias registradas el mes anterior (= "sin registro").
$meses_mostrar = array_keys($por_mes);
foreach (array_keys($nomina_por_mes) as $mes_cobro) {
    $mes_guardia = $mes_cobro - 1;
    if ($mes_guardia >= 1 && !isset($por_mes[$mes_guardia])) {
        $meses_mostrar[] = $mes_guardia; // cobro en nómina sin guardias el mes anterior
    }
}
// Caso cruce de año: enero year+1 tiene cobro de guardia pero no hay guardias en diciembre de este año
if ($nm_enero_siguiente && (float)$nm_enero_siguiente['total_guardia'] > 0 && !isset($por_mes[12])) {
    $meses_mostrar[] = 12; // cobro de guardia en enero siguiente sin diciembre registrado
}
$meses_mostrar = array_unique($meses_mostrar);
sort($meses_mostrar);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>🛡️ Guardias vs. Nómina</title>
  <link rel="icon" type="image/svg+xml" href="images/favicon.svg">
  <link rel="stylesheet" href="styles.css?v=<?php echo filemtime(__DIR__.'/styles.css'); ?>">
  <style>
    /* --- Estilos específicos de guardias-nomina.php --- */

    .gn-page-header {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 12px;
      margin-bottom: 1.75rem;
    }
    .gn-page-title {
      margin: 0;
      font-size: 1.4rem;
      font-weight: 700;
      color: var(--text-primary);
    }
    .gn-page-subtitle {
      margin: 6px 0 0;
      font-size: 0.875rem;
      color: var(--text-muted);
    }
    .gn-year-form select {
      background: #1a2847;
      color: #e6eef8;
      border: 1px solid #2a3f5f;
      border-radius: var(--radius-md, 6px);
      padding: 7px 12px;
      font-size: 14px;
      cursor: pointer;
    }
    .gn-year-form select option {
      background: #1a2847;
      color: #e6eef8;
    }
    /* Tarjetas de resumen anual */
    .gn-stat-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
      gap: 12px;
      margin-bottom: 1.5rem;
    }
    .gn-stat-card {
      padding: 1rem 1.25rem;
      text-align: center;
    }
    .gn-stat-card.gn-card-danger { border-color: #742a2a; }
    .gn-stat-value {
      font-size: 1.75rem;
      font-weight: 700;
      line-height: 1;
      margin-bottom: 0.35rem;
    }
    .gn-stat-value.sm { font-size: 1.35rem; }
    .gn-stat-label {
      font-size: 0.7rem;
      text-transform: uppercase;
      letter-spacing: .5px;
      color: var(--text-muted);
    }
    /* Colores semánticos */
    .gn-c-festivo { color: #fc8181; }
    .gn-c-finde   { color: #f6ad55; }
    .gn-c-labor   { color: #63b3ed; }
    .gn-c-cobrado { color: #68d391; }
    .gn-c-danger  { color: #fc8181; }
    .gn-muted     { color: var(--text-muted); }
    /* Tabla */
    .gn-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .gn-table {
      width: 100%;
      min-width: 640px;
      border-collapse: collapse;
      font-size: 14px;
    }
    .gn-table thead th {
      padding: 10px 14px;
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: .5px;
      font-weight: 600;
      background: rgba(0, 0, 0, 0.25);
      border-bottom: 1px solid #2a3f5f;
      white-space: nowrap;
    }
    .gn-table tbody tr {
      border-bottom: 1px solid rgba(255, 255, 255, 0.04);
      cursor: pointer;
      transition: background 0.15s;
    }
    .gn-table tbody tr:hover { background: rgba(255, 255, 255, 0.04); }
    .gn-table tbody tr.gn-mes-actual { background: rgba(55, 66, 250, 0.07); }
    .gn-table td { padding: 11px 14px; color: var(--text-primary); }
    .gn-table td.tc { text-align: center; }
    .gn-table td.tr { text-align: right; }
    /* Fila de detalle expandible */
    .gn-detalle-row > td {
      background: rgba(0, 0, 0, 0.2);
      padding: 8px 14px 14px 28px;
      cursor: default;
    }
    .gn-dia-chips {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      padding-top: 8px;
    }
    .gn-dia-chip {
      display: inline-block;
      padding: 3px 10px;
      border-radius: 6px;
      font-size: 13px;
      font-weight: 500;
    }
    .gn-chip-festivo { background: rgba(116, 42, 42, 0.45); color: #fc8181; }
    .gn-chip-finde   { background: #744210; color: #f6ad55; }
    .gn-chip-labor   { background: #1c3a5f; color: #63b3ed; }
    /* Badges de estado */
    .gn-badge {
      display: inline-block;
      padding: 3px 10px;
      border-radius: 4px;
      font-size: 12px;
      font-weight: 600;
      white-space: nowrap;
    }
    .gn-badge-ok     { background: #1e4d3a; color: #9ae6b4; }
    .gn-badge-nopago { background: #742a2a; color: #fc8181; }
    .gn-badge-noreg    { background: #744210; color: #f6ad55; }
    .gn-badge-pending  { background: #1a3050; color: #90cdf4; }
    /* Aviso CT172 no disponible */
    .gn-notice {
      margin-top: 1rem;
      padding: 0.75rem 1rem;
      background: rgba(21, 30, 46, 0.9);
      border-left: 3px solid #2a3f5f;
      border-radius: 0 6px 6px 0;
      font-size: 13px;
      color: var(--text-muted);
    }
  
    /* --- Toast notificación --- */
    .gn-toast {
      position: fixed;
      bottom: 24px;
      right: 24px;
      padding: 12px 20px;
      border-radius: 8px;
      font-size: 14px;
      font-weight: 500;
      z-index: 9999;
      animation: gn-fadein 0.3s ease;
      pointer-events: none;
    }
    .gn-toast-success { background: #1e4d3a; color: #9ae6b4; border: 1px solid #2f8a62; }
    .gn-toast-error   { background: #742a2a; color: #fc8181; border: 1px solid #c53030; }
    @keyframes gn-fadein { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }

    /* --- Modal informe --- */
    .gn-modal-backdrop {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, .65);
      z-index: 9000;
      align-items: center;
      justify-content: center;
    }
    .gn-modal-backdrop.active { display: flex; }
    .gn-modal {
      background: #1a2847;
      border: 1px solid #2a3f5f;
      border-radius: 12px;
      padding: 1.75rem;
      min-width: 320px;
      max-width: 420px;
      width: 90%;
    }
    .gn-modal h3 { margin: 0 0 1.25rem; font-size: 1rem; color: #e6eef8; }
    .gn-modal-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 1.25rem; }
    .gn-modal label { font-size: 11px; text-transform: uppercase; letter-spacing: .5px; color: var(--text-muted); display: block; margin-bottom: 5px; }
    .gn-modal select, .gn-modal input[type=number] {
      width: 100%; box-sizing: border-box;
      background: #111d33; color: #e6eef8;
      border: 1px solid #2a3f5f; border-radius: 6px;
      padding: 8px 10px; font-size: 14px;
    }
    .gn-modal-footer { display: flex; gap: 8px; justify-content: flex-end; }

    /* --- Impresión / PDF --- */
    @media print {
      .app-container .sidebar,
      header.header,
      .mobile-menu-toggle,
      .gn-year-form,
      .gn-noprint { display:none !important; }
      .main-content { margin-left:0 !important; }
      .gn-table { font-size:.8rem; }
      body { background:#fff !important; color:#000 !important; }
      .gn-stat-card { border:1px solid #ccc !important; }
    }
  </style>
</head>
<body class="page-guardias-nomina">
<?php include __DIR__ . '/header.php'; ?>
<div class="container" style="padding: 2rem 1.5rem;">

    <!-- Cabecera de página -->
    <div class="gn-page-header">
      <div>
        <h1 class="gn-page-title">🛡️ Guardias vs. Nómina</h1>
        <p class="gn-page-subtitle">
          Comprueba que todos los días de guardia estén correctamente pagados en nómina
        </p>
      </div>
      <form method="get" class="gn-year-form" style="display: flex; align-items: center; gap: 8px;">
        <select name="year" onchange="this.form.submit()">
          <?php foreach ($all_years as $y): ?>
            <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <div style="display:flex;gap:8px;align-items:center;">
        <button class="btn btn-secondary gn-noprint" onclick="abrirModalInforme()" style="white-space:nowrap;">📧&nbsp;Informe</button>
        <button class="btn btn-primary gn-noprint" onclick="window.print()" style="white-space:nowrap;">🖨️&nbsp;PDF</button>
      </div>
    </div>

    <?php if (empty($meses_mostrar)): ?>
      <!-- Sin datos -->
      <div class="card card-body" style="text-align: center; padding: 3.5rem;">
        <div style="font-size: 3rem; margin-bottom: 1rem;">🛡️</div>
        <p class="gn-muted">No hay guardias registradas en <?= $year ?></p>
      </div>

    <?php else: ?>
      <?php
        $total_anual    = array_sum(array_column($por_mes, 'total'));
        $total_festivos = array_sum(array_column($por_mes, 'festivos')); // incluye festivos + finde
        $total_lab      = array_sum(array_column($por_mes, 'laborables'));
        // Total cobrado y meses sin pago con offset +1 (pago mes vencido)
        $total_cobrado  = 0;
        $meses_sin_pago = 0;
        $mes_actual     = (int)date('n');
        foreach ($por_mes as $m => $gd) {
            if ($m < 12) {
                // Meses 1-11: el cobro llega en la nómina del mes siguiente
                $cobro_sum = isset($nomina_por_mes[$m + 1])
                    ? (float)$nomina_por_mes[$m + 1]['total_guardia'] : null;
                $pago_deberia_haber_llegado = $year < (int)date('Y') || ($m + 1) <= $mes_actual;
            } else {
                // Diciembre: el cobro llega en la nómina de enero del año siguiente
                $cobro_sum = $nm_enero_siguiente ? (float)$nm_enero_siguiente['total_guardia'] : null;
                $pago_deberia_haber_llegado = (int)date('Y') > $year; // ya estamos en el año siguiente o posterior
            }
            $total_cobrado += $cobro_sum ?? 0;
            if ($gd['total'] > 0 && $pago_deberia_haber_llegado && ($cobro_sum === null || $cobro_sum == 0)) {
                $meses_sin_pago++;
            }
        }
      ?>

      <!-- Resumen anual -->
      <div class="gn-stat-grid">
        <div class="gn-stat-card card">
          <div class="gn-stat-value"><?= $total_anual ?></div>
          <div class="gn-stat-label">Total guardias</div>
        </div>
        <div class="gn-stat-card card">
          <div class="gn-stat-value gn-c-festivo"><?= $total_festivos ?></div>
          <div class="gn-stat-label">Festivos</div>
        </div>
        <div class="gn-stat-card card">
          <div class="gn-stat-value gn-c-labor"><?= $total_lab ?></div>
          <div class="gn-stat-label">Laborables</div>
        </div>
        <?php if ($nominas_disponibles): ?>
        <div class="gn-stat-card card">
          <div class="gn-stat-value sm gn-c-cobrado"><?= number_format($total_cobrado, 2) ?> €</div>
          <div class="gn-stat-label">Cobrado año</div>
        </div>
        <?php if ($meses_sin_pago > 0): ?>
        <div class="gn-stat-card gn-card-danger card">
          <div class="gn-stat-value gn-c-danger"><?= $meses_sin_pago ?></div>
          <div class="gn-stat-label" style="color: #fc8181;">⚠ Meses sin pago</div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
      </div>

      <!-- Tabla por mes -->
      <div class="card gn-table-wrap">
        <table class="gn-table">
          <thead>
            <tr>
              <th style="text-align: left; color: var(--text-muted);">Mes</th>
              <th class="tc" style="color: var(--text-muted);">Total</th>
              <th class="tc" style="color: #fc8181;">Festivos<br><small style="font-weight:400;font-size:10px;opacity:.7;">fest.+finde</small></th>
              <th class="tc" style="color: #63b3ed;">Laborables</th>
              <?php if ($nominas_disponibles): ?>
              <th class="tr" style="color: #68d391;">Cobrado<br><small style="font-weight:400;font-size:10px;opacity:.7;">nómina siguiente</small></th>
              <th class="tc" style="color: var(--text-muted);">€/día</th>
              <th class="tc" style="color: var(--text-muted);">Estado</th>
              <?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($meses_mostrar as $m):
              $gd = $por_mes[$m] ?? ['total' => 0, 'festivos' => 0, 'laborables' => 0, 'dias' => []];
              // Offset +1: guardias de mes M → cobro en nómina de mes M+1
              $mes_pago = $m + 1;
              if ($mes_pago <= 12) {
                  $nm = $nomina_por_mes[$mes_pago] ?? null;
                  $mes_pago_label = $meses_es[$mes_pago];
              } else {
                  // Diciembre: cobro en enero del año siguiente (dato ya fetcheado al inicio)
                  $nm = $nm_enero_siguiente;
                  $mes_pago_label = 'Ene ' . ($year + 1);
              }
              $cobrado = $nm ? (float)$nm['total_guardia'] : null;
              $por_dia = ($gd['total'] > 0 && $cobrado !== null && $cobrado > 0)
                         ? $cobrado / $gd['total'] : null;

              // "Sin pago" solo si el mes de cobro ya debería haber llegado
              if ($m === 12) {
                  // Diciembre: el cobro llega en enero del año siguiente
                  $pago_deberia_haber_llegado = (int)date('Y') > $year;
              } else {
                  $pago_deberia_haber_llegado = $year < (int)date('Y') || $mes_pago <= (int)date('n');
              }

              if (!$nominas_disponibles) {
                  $estado_html = '';
              } elseif ($gd['total'] > 0 && $pago_deberia_haber_llegado && ($cobrado === null || $cobrado == 0)) {
                  $estado_html = '<span class="gn-badge gn-badge-nopago">⚠ Sin pago</span>';
              } elseif ($gd['total'] == 0 && $cobrado !== null && $cobrado > 0) {
                  $estado_html = '<span class="gn-badge gn-badge-noreg">⚠ Sin registro</span>';
              } elseif ($gd['total'] > 0 && $cobrado !== null && $cobrado > 0) {
                  $estado_html = '<span class="gn-badge gn-badge-ok">✓ OK</span>';
              } elseif ($gd['total'] > 0 && !$pago_deberia_haber_llegado) {
                  $estado_html = '<span class="gn-badge gn-badge-pending">⏳ Pendiente</span>';
              } else {
                  $estado_html = '<span class="gn-muted">—</span>';
              }

              $es_mes_actual = ($m === (int)date('n') && $year === (int)date('Y'));
            ?>
            <tr class="<?= $es_mes_actual ? 'gn-mes-actual' : '' ?>"
                onclick="toggleDetalle('mes-<?= $m ?>')">
              <td style="font-weight: 600;">
                <?= $meses_es[$m] ?>
                <?php if ($es_mes_actual): ?>
                  <span class="gn-muted" style="font-size: 11px; font-weight: 400; margin-left: 6px;">mes actual</span>
                <?php endif; ?>
                <?php if ($gd['total'] > 0): ?>
                  <span class="gn-muted" style="font-size: 11px; margin-left: 4px;">▼</span>
                <?php endif; ?>
              </td>
              <td class="tc" style="font-weight: 600;">
                <?= $gd['total'] > 0 ? $gd['total'] : '<span class="gn-muted">—</span>' ?>
              </td>
              <td class="tc gn-c-festivo">
                <?= $gd['festivos'] > 0 ? $gd['festivos'] : '<span class="gn-muted">—</span>' ?>
              </td>
              <td class="tc gn-c-labor">
                <?= $gd['laborables'] > 0 ? $gd['laborables'] : '<span class="gn-muted">—</span>' ?>
              </td>
              <?php if ($nominas_disponibles): ?>
              <td class="tr gn-c-cobrado" style="font-weight: 600;">
                <?php if ($cobrado !== null): ?>
                  <?= number_format($cobrado, 2) ?> €
                  <div style="font-size: 11px; color: var(--text-muted); font-weight: 400;"><?= $mes_pago_label ?></div>
                <?php elseif (!$pago_deberia_haber_llegado): ?>
                  <span class="gn-muted" style="font-size: 12px;"><?= $mes_pago_label ?></span>
                <?php else: ?>
                  <span class="gn-muted">—</span>
                <?php endif; ?>
              </td>
              <td class="tc gn-muted" style="font-size: 13px;">
                <?= $por_dia !== null ? number_format($por_dia, 2) . ' €' : '<span class="gn-muted">—</span>' ?>
              </td>
              <td class="tc"><?= $estado_html ?></td>
              <?php endif; ?>
            </tr>

            <!-- Detalle días del mes (expandible) -->
            <?php if (!empty($gd['dias'])): ?>
            <tr id="mes-<?= $m ?>" class="gn-detalle-row" style="display: none;">
              <td colspan="<?= $nominas_disponibles ? 7 : 4 ?>">
                <div class="gn-dia-chips">
                  <?php foreach ($gd['dias'] as $dia):
                    $fecha_fmt  = date('d/m', strtotime($dia['date']));
                    $dia_semana = ['', 'Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'][(int)$dia['dow']];
                    $chip_class = match($dia['tipo_dia']) {
                      'festivo', 'finde' => 'gn-chip-festivo',
                      default            => 'gn-chip-labor'
                    };
                    $title = $dia['label_festivo'] ? htmlspecialchars($dia['label_festivo']) : ucfirst($dia['tipo_dia']);
                  ?>
                  <span class="gn-dia-chip <?= $chip_class ?>" title="<?= $title ?>">
                    <?= $dia_semana ?> <?= $fecha_fmt ?>
                    <?php if ($dia['label_festivo']): ?>
                      <span style="font-size: 11px; opacity: .8;">(<?= htmlspecialchars($dia['label_festivo']) ?>)</span>
                    <?php endif; ?>
                  </span>
                  <?php endforeach; ?>
                </div>
              </td>
            </tr>
            <?php endif; ?>

            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if (!$nominas_disponibles): ?>
      <div class="gn-notice">
        ℹ️ La app de nóminas (CT172) no está disponible — se muestran solo los registros de fichaje.
        <a href="http://192.168.1.14/nominas/" target="_blank" style="color: #63b3ed;">Verificar app nóminas</a>
      </div>
      <?php endif; ?>

    <?php endif; ?>
  </div>

<!-- Modal: seleccionar mes/año para enviar informe -->
<div id="modalInforme" class="gn-modal-backdrop">
  <div class="gn-modal">
    <h3>📧 Enviar informe de guardias</h3>
    <div class="gn-modal-grid">
      <div>
        <label for="informe-mes">Mes</label>
        <select id="informe-mes">
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
      <div>
        <label for="informe-year">Año</label>
        <input type="number" id="informe-year" min="2019" max="2030" value="<?= $year ?>">
      </div>
    </div>
    <div style="margin-bottom:1rem;">
      <label for="informe-email" style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);display:block;margin-bottom:5px;">Email destino</label>
      <input type="email" id="informe-email" placeholder="nacho@ejemplo.com"
             style="width:100%;box-sizing:border-box;background:#111d33;color:#e6eef8;border:1px solid #2a3f5f;border-radius:6px;padding:8px 10px;font-size:14px;">
    </div>
    <div class="gn-modal-footer">
      <button class="btn btn-secondary" onclick="cerrarModalInforme()">Cancelar</button>
      <button class="btn btn-primary" id="btnSendInforme" onclick="enviarInforme()">📧 Enviar</button>
    </div>
  </div>
</div>

<script>
function toggleDetalle(id) {
  var row = document.getElementById(id);
  if (row) {
    row.style.display = (row.style.display === '' || row.style.display === 'none') ? 'table-row' : 'none';
  }
}

function abrirModalInforme() {
  // Sugerir el mes anterior por defecto
  var hoy = new Date();
  hoy.setDate(1);
  hoy.setMonth(hoy.getMonth() - 1);
  document.getElementById('informe-mes').value  = hoy.getMonth() + 1;
  document.getElementById('informe-year').value = hoy.getFullYear();
  document.getElementById('modalInforme').classList.add('active');
}

function cerrarModalInforme() {
  document.getElementById('modalInforme').classList.remove('active');
}

function enviarInforme() {
  var mes   = parseInt(document.getElementById('informe-mes').value);
  var year  = parseInt(document.getElementById('informe-year').value);
  var email = document.getElementById('informe-email').value.trim();
  var btn   = document.getElementById('btnSendInforme');

  if (!mes || !year) return;
  if (!email || !email.includes('@')) {
    mostrarToast('⚠ Introduce un email válido', 'error');
    return;
  }

  btn.disabled = true;
  btn.textContent = '⏳ Enviando…';

  fetch('send-guard-report.php', {
    method: 'POST',
    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/json' },
    body: JSON.stringify({ mes: mes, year: year, email: email })
  })
  .then(function(r) { return r.json(); })
  .then(function(data) {
    btn.disabled = false;
    btn.textContent = '📧 Enviar';
    cerrarModalInforme();
    if (data.ok) {
      var meses = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
      var info  = data.total + ' guardias (' + (data.festivos||0) + ' festivos, ' + (data.laborables||0) + ' laborables)';
      mostrarToast('✓ Informe ' + meses[mes] + ' ' + year + ' enviado — ' + info, 'success');
    } else {
      mostrarToast('⚠ ' + (data.error || 'Error al enviar el informe'), 'error');
    }
  })
  .catch(function() {
    btn.disabled = false;
    btn.textContent = '📧 Enviar';
    mostrarToast('⚠ Error de conexión', 'error');
  });
}

function mostrarToast(msg, tipo) {
  var t = document.createElement('div');
  t.className = 'gn-toast gn-toast-' + tipo;
  t.textContent = msg;
  document.body.appendChild(t);
  setTimeout(function() { t.remove(); }, 5000);
}

// Cerrar modal al hacer clic fuera
document.getElementById('modalInforme').addEventListener('click', function(e) {
  if (e.target === this) cerrarModalInforme();
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
