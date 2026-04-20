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

// Agrupar por mes: $por_mes[$month] = ['total'=>N, 'festivos'=>N, 'finde'=>N, 'laborables'=>N, 'dias'=>[...]]
$por_mes = [];
foreach ($guardias_rows as $g) {
    $m = (int)date('n', strtotime($g['date']));
    if (!isset($por_mes[$m])) {
        $por_mes[$m] = ['total' => 0, 'festivos' => 0, 'finde' => 0, 'laborables' => 0, 'dias' => []];
    }
    $por_mes[$m]['total']++;
    $por_mes[$m][$g['tipo_dia'] === 'festivo' ? 'festivos' : ($g['tipo_dia'] === 'finde' ? 'finde' : 'laborables')]++;
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
  <link rel="stylesheet" href="styles.css">
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
      background: rgba(21, 30, 46, 0.9);
      color: var(--text-primary);
      border: 1px solid #2a3f5f;
      border-radius: var(--radius-md, 6px);
      padding: 7px 12px;
      font-size: 14px;
      cursor: pointer;
    }
    /* Tarjetas de resumen anual */
    .gn-stat-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
      gap: 12px;
      margin-bottom: 1.5rem;
    }
    .gn-stat-card {
      background: rgba(21, 30, 46, 0.9);
      border: 1px solid #2a3f5f;
      border-radius: var(--radius-md, 8px);
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
    .gn-table-wrap { overflow: hidden; }
    .gn-table {
      width: 100%;
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
    .gn-chip-festivo { background: #742a2a; color: #fc8181; }
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
    .gn-badge-ok     { background: #1c4532; color: #68d391; }
    .gn-badge-nopago { background: #742a2a; color: #fc8181; }
    .gn-badge-noreg  { background: #744210; color: #f6ad55; }
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
  </style>
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>
<div class="main-content">
  <div class="container" style="max-width: 1000px; padding: 2rem 1.5rem;">

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
        $total_festivos = array_sum(array_column($por_mes, 'festivos'));
        $total_finde    = array_sum(array_column($por_mes, 'finde'));
        $total_lab      = array_sum(array_column($por_mes, 'laborables'));
        // Total cobrado y meses sin pago con offset +1 (pago mes vencido)
        $total_cobrado  = 0;
        $meses_sin_pago = 0;
        $mes_actual     = (int)date('n');
        foreach ($por_mes as $m => $gd) {
            $mes_pago_sum = ($m < 12) ? $m + 1 : null; // diciembre → enero año siguiente
            $cobro_sum = ($mes_pago_sum && isset($nomina_por_mes[$mes_pago_sum]))
                         ? (float)$nomina_por_mes[$mes_pago_sum]['total_guardia'] : null;
            $total_cobrado += $cobro_sum ?? 0;
            // "Sin pago" solo si el mes de cobro ya debería haber pasado
            $pago_deberia_haber_llegado = $mes_pago_sum !== null
                && ($year < (int)date('Y') || $mes_pago_sum <= $mes_actual);
            if ($gd['total'] > 0 && $pago_deberia_haber_llegado && ($cobro_sum === null || $cobro_sum == 0)) {
                $meses_sin_pago++;
            }
        }
      ?>

      <!-- Resumen anual -->
      <div class="gn-stat-grid">
        <div class="gn-stat-card">
          <div class="gn-stat-value"><?= $total_anual ?></div>
          <div class="gn-stat-label">Total guardias</div>
        </div>
        <div class="gn-stat-card">
          <div class="gn-stat-value gn-c-festivo"><?= $total_festivos ?></div>
          <div class="gn-stat-label">Festivos</div>
        </div>
        <div class="gn-stat-card">
          <div class="gn-stat-value gn-c-finde"><?= $total_finde ?></div>
          <div class="gn-stat-label">Fin de semana</div>
        </div>
        <div class="gn-stat-card">
          <div class="gn-stat-value gn-c-labor"><?= $total_lab ?></div>
          <div class="gn-stat-label">Laborables</div>
        </div>
        <?php if ($nominas_disponibles): ?>
        <div class="gn-stat-card">
          <div class="gn-stat-value sm gn-c-cobrado"><?= number_format($total_cobrado, 2) ?> €</div>
          <div class="gn-stat-label">Cobrado año</div>
        </div>
        <?php if ($meses_sin_pago > 0): ?>
        <div class="gn-stat-card gn-card-danger">
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
              <th class="tc" style="color: #fc8181;">Festivos</th>
              <th class="tc" style="color: #f6ad55;">Finde</th>
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
              $gd = $por_mes[$m] ?? ['total' => 0, 'festivos' => 0, 'finde' => 0, 'laborables' => 0, 'dias' => []];
              // Offset +1: guardias de mes M → cobro en nómina de mes M+1
              $mes_pago = $m + 1;
              if ($mes_pago <= 12) {
                  $nm = $nomina_por_mes[$mes_pago] ?? null;
                  $mes_pago_label = $meses_es[$mes_pago];
              } else {
                  $nm = null; // diciembre: cobro en enero del año siguiente, no disponible
                  $mes_pago_label = 'Ene ' . ($year + 1);
              }
              $cobrado = $nm ? (float)$nm['total_guardia'] : null;
              $por_dia = ($gd['total'] > 0 && $cobrado !== null && $cobrado > 0)
                         ? $cobrado / $gd['total'] : null;

              // "Sin pago" solo si el mes de cobro ya debería haber llegado
              $pago_deberia_haber_llegado = ($mes_pago <= 12)
                  && ($year < (int)date('Y') || $mes_pago <= (int)date('n'));

              if (!$nominas_disponibles) {
                  $estado_html = '';
              } elseif ($gd['total'] > 0 && $pago_deberia_haber_llegado && ($cobrado === null || $cobrado == 0)) {
                  $estado_html = '<span class="gn-badge gn-badge-nopago">⚠ Sin pago</span>';
              } elseif ($gd['total'] == 0 && $cobrado !== null && $cobrado > 0) {
                  $estado_html = '<span class="gn-badge gn-badge-noreg">⚠ Sin registro</span>';
              } elseif ($gd['total'] > 0 && $cobrado !== null && $cobrado > 0) {
                  $estado_html = '<span class="gn-badge gn-badge-ok">✓ OK</span>';
              } elseif ($gd['total'] > 0 && !$pago_deberia_haber_llegado) {
                  $estado_html = '<span class="gn-badge" style="background:#1a2847;color:var(--text-muted);">⏳ Pendiente</span>';
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
              <td class="tc gn-c-finde">
                <?= $gd['finde'] > 0 ? $gd['finde'] : '<span class="gn-muted">—</span>' ?>
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
              <td colspan="<?= $nominas_disponibles ? 8 : 5 ?>">
                <div class="gn-dia-chips">
                  <?php foreach ($gd['dias'] as $dia):
                    $fecha_fmt  = date('d/m', strtotime($dia['date']));
                    $dia_semana = ['', 'Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'][(int)$dia['dow']];
                    $chip_class = match($dia['tipo_dia']) {
                      'festivo' => 'gn-chip-festivo',
                      'finde'   => 'gn-chip-finde',
                      default   => 'gn-chip-labor'
                    };
                    $title = $dia['label_festivo'] ? htmlspecialchars($dia['label_festivo']) : $dia['tipo_dia'];
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
</div>

<script>
function toggleDetalle(id) {
  var row = document.getElementById(id);
  if (row) {
    row.style.display = (row.style.display === '' || row.style.display === 'none') ? 'table-row' : 'none';
  }
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
