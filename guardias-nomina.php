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

// --- Meses a mostrar: unión de meses con guardias y con cobro en nómina ---
$meses_mostrar = array_unique(array_merge(array_keys($por_mes), array_keys($nomina_por_mes)));
sort($meses_mostrar);

$page_title = "Guardias vs. Nómina {$year}";
?>
<?php require_once __DIR__ . '/header.php'; ?>

<main class="main-content" style="padding:32px 24px;max-width:1000px;margin:0 auto">

  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:28px;flex-wrap:wrap;gap:12px">
    <div>
      <h1 style="margin:0;font-size:22px;font-weight:700;color:#e2e8f0">🛡️ Guardias vs. Nómina</h1>
      <p style="margin:6px 0 0;color:#718096;font-size:14px">
        Comprueba que todos los días de guardia estén correctamente pagados en nómina
      </p>
    </div>
    <!-- Selector de año -->
    <form method="get" style="display:flex;align-items:center;gap:8px">
      <select name="year" onchange="this.form.submit()"
              style="background:#2d3748;color:#e2e8f0;border:1px solid #4a5568;border-radius:6px;padding:7px 12px;font-size:14px;cursor:pointer">
        <?php foreach ($all_years as $y): ?>
          <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>

  <?php if (empty($meses_mostrar)): ?>
    <div style="text-align:center;padding:60px;color:#718096">
      <div style="font-size:48px;margin-bottom:16px">🛡️</div>
      <p style="font-size:16px">No hay guardias registradas en <?= $year ?></p>
    </div>
  <?php else: ?>

    <!-- Resumen anual -->
    <?php
    $total_anual   = array_sum(array_column($por_mes, 'total'));
    $total_festivos = array_sum(array_column($por_mes, 'festivos'));
    $total_finde    = array_sum(array_column($por_mes, 'finde'));
    $total_lab      = array_sum(array_column($por_mes, 'laborables'));
    $total_cobrado  = array_sum(array_column($nomina_por_mes, 'total_guardia'));
    $meses_sin_pago = 0;
    foreach ($por_mes as $m => $gd) {
        if ($gd['total'] > 0 && empty($nomina_por_mes[$m])) $meses_sin_pago++;
    }
    ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:28px">
      <div style="background:#2d3748;border-radius:10px;padding:16px 20px;text-align:center">
        <div style="font-size:28px;font-weight:700;color:#e2e8f0"><?= $total_anual ?></div>
        <div style="font-size:12px;color:#718096;margin-top:4px;text-transform:uppercase">Total guardias</div>
      </div>
      <div style="background:#2d3748;border-radius:10px;padding:16px 20px;text-align:center">
        <div style="font-size:28px;font-weight:700;color:#fc8181"><?= $total_festivos ?></div>
        <div style="font-size:12px;color:#718096;margin-top:4px;text-transform:uppercase">Festivos</div>
      </div>
      <div style="background:#2d3748;border-radius:10px;padding:16px 20px;text-align:center">
        <div style="font-size:28px;font-weight:700;color:#f6ad55"><?= $total_finde ?></div>
        <div style="font-size:12px;color:#718096;margin-top:4px;text-transform:uppercase">Fin de semana</div>
      </div>
      <div style="background:#2d3748;border-radius:10px;padding:16px 20px;text-align:center">
        <div style="font-size:28px;font-weight:700;color:#63b3ed"><?= $total_lab ?></div>
        <div style="font-size:12px;color:#718096;margin-top:4px;text-transform:uppercase">Laborables</div>
      </div>
      <?php if ($nominas_disponibles): ?>
      <div style="background:#2d3748;border-radius:10px;padding:16px 20px;text-align:center">
        <div style="font-size:22px;font-weight:700;color:#68d391"><?= number_format($total_cobrado, 2) ?> €</div>
        <div style="font-size:12px;color:#718096;margin-top:4px;text-transform:uppercase">Cobrado año</div>
      </div>
      <?php if ($meses_sin_pago > 0): ?>
      <div style="background:#742a2a;border-radius:10px;padding:16px 20px;text-align:center">
        <div style="font-size:28px;font-weight:700;color:#fc8181"><?= $meses_sin_pago ?></div>
        <div style="font-size:12px;color:#fc8181;margin-top:4px;text-transform:uppercase">⚠ Meses sin pago</div>
      </div>
      <?php endif; ?>
      <?php endif; ?>
    </div>

    <!-- Tabla por mes -->
    <div style="background:#2d3748;border-radius:12px;overflow:hidden">
      <table style="width:100%;border-collapse:collapse;font-size:14px">
        <thead>
          <tr style="background:#1a202c">
            <th style="padding:12px 16px;text-align:left;color:#a0aec0;font-size:11px;text-transform:uppercase;letter-spacing:.5px;font-weight:600">Mes</th>
            <th style="padding:12px 16px;text-align:center;color:#a0aec0;font-size:11px;text-transform:uppercase;letter-spacing:.5px;font-weight:600">Total</th>
            <th style="padding:12px 16px;text-align:center;color:#fc8181;font-size:11px;text-transform:uppercase;letter-spacing:.5px;font-weight:600">Festivos</th>
            <th style="padding:12px 16px;text-align:center;color:#f6ad55;font-size:11px;text-transform:uppercase;letter-spacing:.5px;font-weight:600">Finde</th>
            <th style="padding:12px 16px;text-align:center;color:#63b3ed;font-size:11px;text-transform:uppercase;letter-spacing:.5px;font-weight:600">Laborables</th>
            <?php if ($nominas_disponibles): ?>
            <th style="padding:12px 16px;text-align:right;color:#68d391;font-size:11px;text-transform:uppercase;letter-spacing:.5px;font-weight:600">Cobrado</th>
            <th style="padding:12px 16px;text-align:center;color:#a0aec0;font-size:11px;text-transform:uppercase;letter-spacing:.5px;font-weight:600">€/día</th>
            <th style="padding:12px 16px;text-align:center;color:#a0aec0;font-size:11px;text-transform:uppercase;letter-spacing:.5px;font-weight:600">Estado</th>
            <?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($meses_mostrar as $m):
            $gd = $por_mes[$m] ?? ['total' => 0, 'festivos' => 0, 'finde' => 0, 'laborables' => 0, 'dias' => []];
            $nm = $nomina_por_mes[$m] ?? null;
            $cobrado = $nm ? (float)$nm['total_guardia'] : null;
            $por_dia = ($gd['total'] > 0 && $cobrado !== null && $cobrado > 0)
                       ? $cobrado / $gd['total'] : null;

            // Estado del mes
            if (!$nominas_disponibles) {
                $estado_html = '';
            } elseif ($gd['total'] > 0 && ($cobrado === null || $cobrado == 0)) {
                $estado_html = '<span style="background:#742a2a;color:#fc8181;padding:3px 10px;border-radius:4px;font-size:12px;font-weight:600">⚠ Sin pago</span>';
            } elseif ($gd['total'] == 0 && $cobrado !== null && $cobrado > 0) {
                $estado_html = '<span style="background:#744210;color:#f6ad55;padding:3px 10px;border-radius:4px;font-size:12px;font-weight:600">⚠ Sin registro</span>';
            } elseif ($gd['total'] > 0 && $cobrado !== null && $cobrado > 0) {
                $estado_html = '<span style="background:#1c4532;color:#68d391;padding:3px 10px;border-radius:4px;font-size:12px;font-weight:600">✓ OK</span>';
            } else {
                $estado_html = '<span style="color:#4a5568;font-size:12px">—</span>';
            }

            $es_mes_actual = ($m === (int)date('n') && $year === (int)date('Y'));
          ?>
          <tr style="border-top:1px solid #4a5568;<?= $es_mes_actual ? 'background:#2c3e50' : '' ?>"
              onclick="toggleDetalle('mes-<?= $m ?>')"
              style="cursor:pointer;<?= $es_mes_actual ? 'background:#2c3e50' : '' ?>">
            <td style="padding:12px 16px;font-weight:600;color:#e2e8f0;cursor:pointer">
              <?= $meses_es[$m] ?>
              <?php if ($es_mes_actual): ?>
                <span style="font-size:11px;color:#718096;font-weight:400;margin-left:6px">mes actual</span>
              <?php endif; ?>
              <?php if ($gd['total'] > 0): ?>
                <span style="font-size:11px;color:#718096;font-weight:400;margin-left:4px">▼</span>
              <?php endif; ?>
            </td>
            <td style="padding:12px 16px;text-align:center;color:#e2e8f0;font-weight:600">
              <?= $gd['total'] > 0 ? $gd['total'] : '<span style="color:#4a5568">—</span>' ?>
            </td>
            <td style="padding:12px 16px;text-align:center;color:#fc8181">
              <?= $gd['festivos'] > 0 ? $gd['festivos'] : '<span style="color:#4a5568">—</span>' ?>
            </td>
            <td style="padding:12px 16px;text-align:center;color:#f6ad55">
              <?= $gd['finde'] > 0 ? $gd['finde'] : '<span style="color:#4a5568">—</span>' ?>
            </td>
            <td style="padding:12px 16px;text-align:center;color:#63b3ed">
              <?= $gd['laborables'] > 0 ? $gd['laborables'] : '<span style="color:#4a5568">—</span>' ?>
            </td>
            <?php if ($nominas_disponibles): ?>
            <td style="padding:12px 16px;text-align:right;color:#68d391;font-weight:600">
              <?= $cobrado !== null ? number_format($cobrado, 2) . ' €' : '<span style="color:#4a5568">—</span>' ?>
            </td>
            <td style="padding:12px 16px;text-align:center;color:#a0aec0;font-size:13px">
              <?= $por_dia !== null ? number_format($por_dia, 2) . ' €' : '<span style="color:#4a5568">—</span>' ?>
            </td>
            <td style="padding:12px 16px;text-align:center"><?= $estado_html ?></td>
            <?php endif; ?>
          </tr>
          <!-- Detalle días del mes (expandible) -->
          <?php if (!empty($gd['dias'])): ?>
          <tr id="mes-<?= $m ?>" style="display:none">
            <td colspan="<?= $nominas_disponibles ? 8 : 5 ?>" style="padding:0 16px 12px 32px;background:#1e2a3a">
              <div style="display:flex;flex-wrap:wrap;gap:8px;padding-top:10px">
                <?php foreach ($gd['dias'] as $dia):
                  $fecha_fmt  = date('d/m', strtotime($dia['date']));
                  $dia_semana = ['', 'Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'][(int)$dia['dow']];
                  $color = match($dia['tipo_dia']) {
                    'festivo'   => '#742a2a',
                    'finde'     => '#744210',
                    default     => '#1c3a5f'
                  };
                  $color_txt = match($dia['tipo_dia']) {
                    'festivo'   => '#fc8181',
                    'finde'     => '#f6ad55',
                    default     => '#63b3ed'
                  };
                  $title = $dia['label_festivo'] ? htmlspecialchars($dia['label_festivo']) : $dia['tipo_dia'];
                ?>
                <span title="<?= $title ?>"
                      style="background:<?= $color ?>;color:<?= $color_txt ?>;
                             padding:4px 10px;border-radius:6px;font-size:13px;font-weight:500">
                  <?= $dia_semana ?> <?= $fecha_fmt ?>
                  <?php if ($dia['label_festivo']): ?>
                    <span style="font-size:11px;opacity:.8">(<?= htmlspecialchars($dia['label_festivo']) ?>)</span>
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
    <div style="margin-top:16px;padding:12px 16px;background:#2d3748;border-radius:8px;border-left:3px solid #718096">
      <p style="margin:0;font-size:13px;color:#a0aec0">
        ℹ️ La app de nóminas (CT172) no está disponible — se muestran solo los registros de fichaje.
        <a href="http://192.168.1.14/nominas/" target="_blank" style="color:#63b3ed">Verificar app nóminas</a>
      </p>
    </div>
    <?php endif; ?>

  <?php endif; ?>

</main>

<script>
function toggleDetalle(id) {
    var row = document.getElementById(id);
    if (row) row.style.display = row.style.display === 'none' ? 'table-row' : 'none';
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
