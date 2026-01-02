<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib.php';
require_login();
$user = current_user();
$pdo = get_pdo();

$year = intval($_GET['year'] ?? date('Y'));
$today = date('Y-m-d');
$currentYear = date('Y');
$currentMonth = intval(date('n'));
$config = get_year_config($year, $user['id']);

// Date range filter for custom charts
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
if (!$start_date || !$end_date) {
  // default last 30 days
  $end_date = $today;
  $start_date = date('Y-m-d', strtotime($end_date . ' -29 days'));
}

// allow user to force recompute their cached summary for this year
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'force_recalc') {
  // compute monthly totals for this user and store in app_settings
  $months_calc = [];
  for ($m=1;$m<=12;$m++) $months_calc[$m] = ['worked'=>0,'expected'=>0];
  $startTs = strtotime("$year-01-01"); $endTs = strtotime("$year-12-31");
  $cfg = get_year_config($year, $user['id']);
  for ($ts = $startTs; $ts <= $endTs; $ts += 86400) {
    $d = date('Y-m-d', $ts); $mm = intval(date('n', $ts));
    // stop at today for current month
    if ($year == $currentYear && $d > $today) break;
    $est = $pdo->prepare('SELECT * FROM entries WHERE user_id = ? AND date = ? LIMIT 1');
    $est->execute([$user['id'],$d]);
    $e = $est->fetch() ?: ['date'=>$d];
    $hstmt = $pdo->prepare('SELECT date,label,type,annual,user_id FROM holidays WHERE (YEAR(date)=? OR annual=1) AND (user_id IS NULL OR user_id = ?)');
    $hstmt->execute([$year,$user['id']]);
    $hols = [];
    foreach ($hstmt->fetchAll() as $hh) { $kd = $hh['date']; if (!empty($hh['annual'])) $kd = sprintf('%04d-%s', $year, substr($hh['date'],5)); $hols[$kd] = $hh; }
    if (isset($hols[$d])) { $e['is_holiday']=true; $e['special_type']=$hols[$d]['type']; }
    $calc = compute_day($e, $cfg);
    $months_calc[$mm]['worked'] += $calc['worked_minutes'] ?? 0;
    $months_calc[$mm]['expected'] += $calc['expected_minutes'] ?? 0;
  }
  $key = 'summary_' . $user['id'] . '_' . $year;
  $pdo->exec("CREATE TABLE IF NOT EXISTS app_settings (
    name VARCHAR(191) PRIMARY KEY,
    value TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $stmt = $pdo->prepare('REPLACE INTO app_settings (name,value) VALUES (?,?)');
  $stmt->execute([$key, json_encode($months_calc)]);
  header('Location: dashboard.php?year=' . urlencode($year) . '&msg=' . urlencode('Recalculo completado')); exit;
}

// load entries for user for the year
$stmt = $pdo->prepare('SELECT * FROM entries WHERE user_id = ? AND date BETWEEN ? AND ? ORDER BY date ASC');
$stmt->execute([$user['id'], "$year-01-01", "$year-12-31"]);
$rows = $stmt->fetchAll();
$entries = [];
foreach ($rows as $r) $entries[$r['date']] = $r;

// load holidays for year (map annual to selected year)
$holidayMap = [];
try {
  $hstmt = $pdo->prepare('SELECT date,label,type,annual,user_id FROM holidays WHERE (YEAR(date) = ? OR annual = 1) AND (user_id IS NULL OR user_id = ?)');
  $hstmt->execute([$year, $user['id']]);
  foreach ($hstmt->fetchAll() as $h) {
    $keyDate = $h['date'];
    if (!empty($h['annual'])) $keyDate = sprintf('%04d-%s', $year, substr($h['date'],5));
    $holidayMap[$keyDate] = ['label'=>$h['label'],'type'=>$h['type']];
  }
} catch (Throwable $e) { }

// Prepare per-month aggregates
$months = [];
for ($m=1;$m<=12;$m++) {
  $months[$m] = ['worked' => 0, 'expected' => 0, 'days_counted' => 0];
}

// Try to use cached summary from app_settings if available
try {
  $cacheKey = 'summary_' . $user['id'] . '_' . $year;
  $cstmt = $pdo->prepare('SELECT value FROM app_settings WHERE name = ? LIMIT 1');
  $cstmt->execute([$cacheKey]);
  $crow = $cstmt->fetch();
  if ($crow && !empty($crow['value'])) {
    $cached = json_decode($crow['value'], true);
    if (is_array($cached)) {
      // normalize into months (minutes)
      for ($m=1;$m<=12;$m++) {
        if (isset($cached[$m])) {
          $months[$m]['worked'] = $cached[$m]['worked'] ?? 0;
          $months[$m]['expected'] = $cached[$m]['expected'] ?? 0;
          $months[$m]['days_counted'] = 0;
        }
      }
      // mark that we used cache
      $used_cache = true;
    }
  }
} catch (Throwable $e) { /* ignore cache errors */ }

// iterate days and sum (compute dynamically on each page load)
$startTs = strtotime("$year-01-01");
$endTs = strtotime("$year-12-31");
$month_values = array_fill(1,12,0);
for ($ts = $startTs; $ts <= $endTs; $ts += 86400) {
  $d = date('Y-m-d', $ts);
  $m = intval(date('n', $ts));
  // if month is current and year is current, stop at today
  if ($year == $currentYear && $m == $currentMonth && $d > $today) break;
  $e = $entries[$d] ?? ['date' => $d];
  if (isset($holidayMap[$d])) {
    $e['is_holiday'] = true;
    $e['special_type'] = $holidayMap[$d]['type'] ?? 'holiday';
  }
  $calc = compute_day($e, $config);
  // compute_day returns worked_minutes and expected_minutes
  $worked = $calc['worked_minutes'] ?? 0;
  $expected = $calc['expected_minutes'] ?? 0;
  // Exclude weekends without any recorded times: when there is no entry and compute_day produced blank display
  $is_real_entry = isset($entries[$d]);
  $blankWeekend = ($calc['worked_hours_formatted'] === '' && $expected === 0 && !$is_real_entry);
  if ($blankWeekend) {
    continue; // skip counting this day
  }
  $months[$m]['worked'] += $worked;
  $months[$m]['expected'] += $expected;
  $months[$m]['days_counted']++;
  $month_values[$m] += $worked;
}

// Year aggregates up to today (or full year if past)
$ytd_worked = 0; $ytd_expected = 0;
for ($m=1;$m<=12;$m++) {
  // if current year, only include months up to currentMonth
  if ($year == $currentYear && $m > $currentMonth) break;
  $ytd_worked += $months[$m]['worked'];
  $ytd_expected += $months[$m]['expected'];
}

function fmt($min){ return minutes_to_hours_formatted(intval($min)); }

function svg_sparkline(array $values, $w=120, $h=28){
  $vals = array_values($values);
  $max = max($vals) ?: 1;
  $min = min($vals);
  $count = count($vals);
  $points = [];
  for ($i=0;$i<$count;$i++){
    $x = ($i/ max(1, $count-1)) * ($w-2) + 1;
    $y = $h - ( ($vals[$i]-$min) / max(1, $max-$min) ) * ($h-4) - 1;
    $points[] = round($x,2) . ',' . round($y,2);
  }
  $poly = implode(' ', $points);
  $svg = '<svg width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '" xmlns="http://www.w3.org/2000/svg">';
  $svg .= '<polyline fill="none" stroke="#0ea5e9" stroke-width="2" points="' . $poly . '" />';
  $svg .= '</svg>';
  return $svg;
}

function svg_compare_chart(array $dates, array $worked, array $expected, $w=700, $h=220){
  // dates: array of Y-m-d labels; worked & expected: minutes per day
  $valsW = array_values($worked);
  $valsE = array_values($expected);
  $max = max(max($valsW) ?: 1, max($valsE) ?: 1);
  $min = 0;
  $count = count($dates) ?: 1;
  $pad = 40; // leave space for Y labels
  $plotW = $w - $pad*2;
  $plotH = $h - $pad*2;
  $polyW = [];
  $polyE = [];
  for ($i=0;$i<$count;$i++){
    $x = $pad + ($i / max(1, $count-1)) * $plotW;
    $yW = $pad + ($plotH - (($valsW[$i]-$min)/max(1,$max-$min))*$plotH);
    $yE = $pad + ($plotH - (($valsE[$i]-$min)/max(1,$max-$min))*$plotH);
    $polyW[] = round($x,2) . ',' . round($yW,2);
    $polyE[] = round($x,2) . ',' . round($yE,2);
  }
  $polyW = implode(' ', $polyW);
  $polyE = implode(' ', $polyE);
  $svg = '<svg width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '" xmlns="http://www.w3.org/2000/svg">';
  // background / plot area
  $svg .= '<rect x="' . $pad . '" y="' . $pad . '" width="' . $plotW . '" height="' . $plotH . '" fill="#fff" stroke="#eee" stroke-width="1"/>';
  // y-axis ticks and labels (4 ticks)
  $ticks = 4;
  for ($t=0;$t<=$ticks;$t++){
    $val = intval(round($min + ($t/$ticks) * ($max-$min)));
    $py = $pad + ($plotH - ($t/$ticks)*$plotH);
    $svg .= '<line x1="' . $pad . '" y1="' . $py . '" x2="' . ($pad+$plotW) . '" y2="' . $py . '" stroke="#f3f4f6" stroke-width="1" />';
    $svg .= '<text x="' . ($pad-8) . '" y="' . ($py+4) . '" font-size="11" text-anchor="end" fill="#374151">' . htmlspecialchars(fmt($val)) . '</text>';
  }
  // expected line (dashed)
  $svg .= '<polyline fill="none" stroke="#e11d48" stroke-width="2" stroke-dasharray="6 4" points="' . $polyE . '" />';
  // worked line
  $svg .= '<polyline fill="none" stroke="#06b6d4" stroke-width="2" points="' . $polyW . '" />';
  // x-axis labels (sparse)
  $maxLabels = 8;
  $step = max(1, intval(floor($count / $maxLabels)));
  for ($i=0;$i<$count;$i += $step){
    $x = $pad + ($i / max(1, $count-1)) * $plotW;
    $label = date('d/m', strtotime($dates[$i]));
    $svg .= '<text x="' . $x . '" y="' . ($pad + $plotH + 16) . '" font-size="11" text-anchor="middle" fill="#475569">' . htmlspecialchars($label) . '</text>';
  }
  // legend
  $svg .= '<g transform="translate(' . ($w-180) . ',10)"><rect width="160" height="36" rx="6" fill="#ffffff" stroke="#eee"/></g>';
  $svg .= '<text x="' . ($w-170) . '" y="28" font-size="12" fill="#e11d48">— Esperadas</text>';
  $svg .= '<text x="' . ($w-170) . '" y="44" font-size="12" fill="#06b6d4">— Realizadas</text>';
  $svg .= '</svg>';
  return $svg;
}

?>
<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Dashboard</title><link rel="stylesheet" href="styles.css">
<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head><body>
<?php include __DIR__ . '/header.php'; ?>
<div class="container">
  <div class="card">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
      <h1>Dashboard — <?php echo $year; ?></h1>
      <div style="display:flex;gap:8px;align-items:center;">
        <form method="get" style="display:flex;gap:8px;align-items:center;margin:0;"> 
          <input type="hidden" name="year" value="<?php echo htmlspecialchars($year); ?>">
          <label class="small">Desde</label>
          <input class="form-control" type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
          <label class="small">Hasta</label>
          <input class="form-control" type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
          <button class="btn" type="submit">Filtrar</button>
        </form>
        <form method="post" style="margin:0;">
          <input type="hidden" name="action" value="force_recalc">
          <button class="btn" type="submit">Recalcular ahora</button>
        </form>
      </div>
    </div>
    <div class="dashboard-cards" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-top:12px;">
      <?php
        // This month summary
        $m = ($year == $currentYear) ? $currentMonth : min($currentMonth,12);
        $monthWorked = $months[$m]['worked'];
        $monthExpected = $months[$m]['expected'];
        $monthBalance = $monthWorked - $monthExpected;
      ?>
      <div class="card">
        <a class="card-link" href="index.php?year=<?php echo $year;?>#<?php echo sprintf('%04d-%02d',$year,$m); ?>">
          <h4>Horas trabajadas (mes <?php echo $m;?>)</h4>
          <div style="display:flex;align-items:center;gap:12px;">
            <div class="value"><?php echo fmt($monthWorked); ?></div>
            <div class="sparkline"><?php echo svg_sparkline(array_slice($month_values, max(1,$m-5), min(6, $m))); ?></div>
          </div>
          <div class="muted">Esperadas: <?php echo fmt($monthExpected); ?></div>
        </a>
      </div>

      <div class="card">
        <h4>Saldo mes</h4>
        <div style="font-size:1.4rem;font-weight:700"><?php echo fmt($monthBalance); ?></div>
        <div class="muted">Exceso/Deficit del mes</div>
      </div>

      <div class="card">
        <h4>Exceso horas (mes)</h4>
        <div style="font-size:1.4rem;font-weight:700"><?php echo $monthBalance>0 ? fmt($monthBalance) : '0:00'; ?></div>
        <div class="muted">Solo exceso positivo</div>
      </div>

      <div class="card">
        <h4>Acumulado año</h4>
        <div style="font-size:1.4rem;font-weight:700"><?php echo fmt($ytd_worked); ?></div>
        <div class="muted">Esperadas (YTD): <?php echo fmt($ytd_expected); ?></div>
      </div>

      <div class="card">
        <h4>Saldo acumulado año</h4>
        <div style="font-size:1.4rem;font-weight:700"><?php echo fmt($ytd_worked - $ytd_expected); ?></div>
        <div class="muted">Incluye meses hasta la fecha</div>
      </div>

      <div class="card">
        <h4>Media horas por día laboral</h4>
        <?php
          $days = 0; $totalWork = 0;
          for ($m2=1;$m2<=12;$m2++){
            if ($year == $currentYear && $m2 > $currentMonth) break;
            // approximate working days counted: derive from expected > 0 days
            $days += $months[$m2]['days_counted'];
            $totalWork += $months[$m2]['worked'];
          }
          $avg = $days>0 ? intval(round($totalWork / $days)) : 0;
        ?>
        <div style="font-size:1.2rem;font-weight:700"><?php echo fmt($avg); ?></div>
        <div class="muted">Basado en días procesados (incluye fines de semana filtrados)</div>
      </div>
    </div>

    <h3 style="margin-top:18px;">Resumen mensual</h3>
    <div class="table-responsive">
      <table class="sheet compact">
        <thead><tr><th>Mes</th><th>Trabajadas</th><th>Esperadas</th><th>Saldo</th><th>Exceso</th><th>Tendencia</th></tr></thead>
        <tbody>
        <?php for ($mm=1;$mm<=12;$mm++):
            if ($year == $currentYear && $mm > $currentMonth) break;
            $w = $months[$mm]['worked']; $eexp = $months[$mm]['expected']; $bal = $w - $eexp; $ex = $bal>0 ? $bal : 0;
        ?>
          <tr>
            <td><?php echo strftime('%B', mktime(0,0,0,$mm,1,$year)); ?></td>
            <td><?php echo fmt($w); ?></td>
            <td><?php echo fmt($eexp); ?></td>
            <td><?php echo fmt($bal); ?></td>
            <td><?php echo fmt($ex); ?></td>
            <td><div class="sparkline"><?php echo svg_sparkline(array_slice($month_values, max(1,$mm-5), min(6, $mm)),160,32); ?></div></td>
          </tr>
        <?php endfor; ?>
        </tbody>
      </table>
    </div>
  </div>
    
    <h3 style="margin-top:18px;">Comparativa diaria (Esperadas vs Realizadas)</h3>
    <?php
      // Build daily series for the selected range
      $ds = strtotime($start_date); $de = strtotime($end_date);
      $dayLabels = [];
      $dailyWorked = [];
      $dailyExpected = [];
      for ($t = $ds; $t <= $de; $t += 86400) {
        $d = date('Y-m-d', $t);
        $dayLabels[] = $d;
        $e = $entries[$d] ?? ['date'=>$d];
        if (isset($holidayMap[$d])) { $e['is_holiday'] = true; $e['special_type'] = $holidayMap[$d]['type'] ?? 'holiday'; }
        $calc = compute_day($e, get_year_config(intval(date('Y', $t)), $user['id']));
        $dailyWorked[] = intval($calc['worked_minutes'] ?? 0);
        $dailyExpected[] = intval($calc['expected_minutes'] ?? 0);
      }
      // weekly aggregates
      $weeks = [];
      foreach ($dayLabels as $idx => $d) {
        $ts = strtotime($d);
        $weekKey = date('oW', $ts); // ISO week-year + week
        if (!isset($weeks[$weekKey])) $weeks[$weekKey] = ['worked'=>0,'expected'=>0,'days'=>0,'label'=> date('o', $ts) . ' W' . date('W', $ts)];
        $weeks[$weekKey]['worked'] += $dailyWorked[$idx];
        $weeks[$weekKey]['expected'] += $dailyExpected[$idx];
        $weeks[$weekKey]['days']++;
      }
    ?>
    <div class="card" style="margin-top:8px;">
      <div style="padding:12px;">
        <div class="muted">Rango: <?php echo htmlspecialchars($start_date); ?> — <?php echo htmlspecialchars($end_date); ?></div>
        <div style="margin-top:12px;">
          <canvas id="compareChart" width="900" height="260"></canvas>
          <script>
            (function(){
              const labels = <?php echo json_encode($dayLabels); ?>;
              const dataWorked = <?php echo json_encode($dailyWorked); ?>; // minutes
              const dataExpected = <?php echo json_encode($dailyExpected); ?>; // minutes
              function fmtMinToHMS(mins){ const h = Math.floor(mins/60); const m = Math.abs(mins % 60); return h + ':' + String(m).padStart(2,'0'); }
              const ctx = document.getElementById('compareChart').getContext('2d');
              const chart = new Chart(ctx, {
                type: 'line',
                data: {
                  labels: labels.map(d => { const dt = new Date(d); return (dt.getDate()<10? '0'+dt.getDate():dt.getDate()) + '/' + (dt.getMonth()+1<10? '0'+(dt.getMonth()+1):dt.getMonth()+1); }),
                  datasets: [
                    { label: 'Esperadas', data: dataExpected, borderColor: '#e11d48', backgroundColor: 'rgba(225,29,72,0.05)', borderDash: [6,4], tension: 0.2 },
                    { label: 'Realizadas', data: dataWorked, borderColor: '#06b6d4', backgroundColor: 'rgba(6,182,212,0.06)', tension: 0.2 }
                  ]
                },
                options: {
                  maintainAspectRatio: false,
                  scales: {
                    y: {
                      beginAtZero: true,
                      ticks: {
                        callback: function(value){ return fmtMinToHMS(value); }
                      }
                    },
                    x: {
                      ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 12 }
                    }
                  },
                  plugins: {
                    tooltip: {
                      callbacks: {
                        label: function(ctx){ return ctx.dataset.label + ': ' + fmtMinToHMS(ctx.parsed.y); }
                      }
                    },
                    legend: { position: 'top' }
                  }
                }
              });
            })();
          </script>
        </div>
        <h4 style="margin-top:12px;">Exceso por semana</h4>
        <div class="table-responsive">
          <table class="sheet compact">
            <thead><tr><th>Semana</th><th>Trabajadas</th><th>Esperadas</th><th>Saldo</th></tr></thead>
            <tbody>
            <?php foreach ($weeks as $wk => $vals): $bal = $vals['worked'] - $vals['expected']; ?>
              <tr>
                <td><?php echo htmlspecialchars($vals['label']); ?></td>
                <td><?php echo fmt($vals['worked']); ?></td>
                <td><?php echo fmt($vals['expected']); ?></td>
                <td><?php echo fmt($bal); ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
</div>
<?php include __DIR__ . '/footer.php'; ?>
</body></html>
