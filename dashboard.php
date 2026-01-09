<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib.php';
require_login();

// Redirect to password change if needed
if (needs_password_change()) {
    header('Location: change_password.php');
    exit;
}

$user = current_user();
$pdo = get_pdo();

$year = intval($_GET['year'] ?? date('Y'));
$today = date('Y-m-d');
$currentYear = intval(date('Y'));
$currentMonth = intval(date('n'));
// Years selector (dynamic): ONLY years where this user has entries
$years = [];
try {
  $ystmt = $pdo->prepare('SELECT DISTINCT YEAR(date) AS y FROM entries WHERE user_id = ? AND date IS NOT NULL ORDER BY y DESC');
  $ystmt->execute([$user['id']]);
  foreach ($ystmt->fetchAll() as $r) { if (!empty($r['y'])) $years[] = intval($r['y']); }
} catch (Throwable $e) { /* ignore */ }
$years = array_values(array_unique(array_filter($years)));
rsort($years);

// Always allow viewing current year and upcoming years, even with no data
$currentYear = intval(date('Y'));
if (!in_array($currentYear, $years)) {
  $years[] = $currentYear;
  rsort($years);
}

// If requested year has no data AND it's not current year, fall back to most recent year with data
if (!empty($years) && !in_array($year, $years, true) && $year !== $currentYear) {
  $year = $years[0];
}

// If user has no data at all, allow viewing current year (empty)
if (empty($years)) {
  $years = [intval(date('Y'))];
}

$config = get_year_config($year, $user['id']);

// load entries for user for the year
$stmt = $pdo->prepare('SELECT * FROM entries WHERE user_id = ? AND date BETWEEN ? AND ? ORDER BY date ASC');
$stmt->execute([$user['id'], "$year-01-01", "$year-12-31"]);
$rows = $stmt->fetchAll();
$entries = [];
foreach ($rows as $r) $entries[$r['date']] = $r;

// load holidays for year (map annual to selected year)
$holidayMap = [];
try {
  $hstmt = $pdo->prepare('SELECT date,label,type,annual,user_id FROM holidays WHERE user_id IS NULL OR user_id = ?');
  $hstmt->execute([$user['id']]);
  foreach ($hstmt->fetchAll() as $h) {
    $keyDate = $h['date'];
    // Si es un festivo anual, reconstruir la fecha para el año seleccionado
    if (!empty($h['annual'])) {
      $hMonth = intval(substr($h['date'], 5, 2)); // MM
      $hDay = intval(substr($h['date'], 8, 2));   // DD
      $keyDate = sprintf('%04d-%02d-%02d', $year, $hMonth, $hDay);
    } else {
      // Si no es anual, solo incluir si coincide con el año seleccionado
      $hYear = intval(substr($h['date'], 0, 4)); // YYYY
      if ($hYear !== $year) {
        continue;
      }
    }
    $holidayMap[$keyDate] = ['label'=>$h['label'],'type'=>$h['type']];
  }
} catch (Throwable $e) { }

function load_year_maps(PDO $pdo, int $userId, int $year): array {
  // entries
  $entries = [];
  $stmt = $pdo->prepare('SELECT * FROM entries WHERE user_id = ? AND date BETWEEN ? AND ? ORDER BY date ASC');
  $stmt->execute([$userId, sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year)]);
  foreach ($stmt->fetchAll() as $r) { $entries[$r['date']] = $r; }

  // holidays (map annual)
  $holidayMap = [];
  try {
    $hstmt = $pdo->prepare('SELECT date,label,type,annual,user_id FROM holidays WHERE (YEAR(date) = ? OR annual = 1) AND (user_id IS NULL OR user_id = ?)');
    $hstmt->execute([$year, $userId]);
    foreach ($hstmt->fetchAll() as $h) {
      $keyDate = $h['date'];
      if (!empty($h['annual'])) $keyDate = sprintf('%04d-%s', $year, substr($h['date'],5));
      $holidayMap[$keyDate] = ['label'=>$h['label'], 'type'=>$h['type']];
    }
  } catch (Throwable $e) { /* ignore */ }

  $cfg = get_year_config($year, $userId);
  return [$entries, $holidayMap, $cfg];
}

function count_afternoons_worked_in_month(int $year, int $month, array $entries, array $holidayMap, array $cfg, bool $limitToToday = false): int {
  // "Tardes trabajadas" = días con saldo comida >= 1 hora (>= 60 min)
  // saldo comida = lunch_balance (actual - configurado) en compute_day()
  $count = 0;
  $start = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
  $end = $start->modify('last day of this month');
  if ($limitToToday) {
    $today = new DateTimeImmutable('today');
    if ($today < $end) $end = $today;
  }
  for ($cur = $start; $cur <= $end; $cur = $cur->modify('+1 day')) {
    $d = $cur->format('Y-m-d');
    $e = $entries[$d] ?? ['date' => $d];
    if (isset($holidayMap[$d])) {
      $e['is_holiday'] = true;
      $e['special_type'] = $holidayMap[$d]['type'] ?? 'holiday';
    }
    $calc = compute_day($e, $cfg);
    $lb = $calc['lunch_balance'];
    if ($lb !== null && intval($lb) >= 60) $count++;
  }
  return $count;
}

// Prepare per-month aggregates
$months = [];
for ($m=1;$m<=12;$m++) {
  $months[$m] = ['worked' => 0, 'expected' => 0, 'days_counted' => 0];
}

// Nota: todos los cálculos se hacen sobre la marcha al cargar la página (sin caché)

// iterate days and sum (compute dynamically on each page load)
$startTs = strtotime(sprintf('%04d-01-01', $year));
// Only count up to today for current year; for future years, count nothing.
if ($year < $currentYear) {
  $endTs = strtotime(sprintf('%04d-12-31', $year));
} elseif ($year === $currentYear) {
  $endTs = strtotime($today);
} else {
  $endTs = $startTs - 86400;
}
$month_values = array_fill(1,12,0);
for ($ts = $startTs; $ts <= $endTs; $ts += 86400) {
  $d = date('Y-m-d', $ts);
  $m = intval(date('n', $ts));
  $e = $entries[$d] ?? ['date' => $d];
  if (isset($holidayMap[$d])) {
    $e['is_holiday'] = true;
    $e['special_type'] = $holidayMap[$d]['type'] ?? 'holiday';
  }
  $calc = compute_day($e, $config);
  // compute_day returns worked_minutes_for_display (which excludes excess coffee and lunch)
  $worked = $calc['worked_minutes_for_display'] ?? 0;
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
  if ($year === $currentYear && $m > $currentMonth) break;
  $ytd_worked += $months[$m]['worked'];
  $ytd_expected += $months[$m]['expected'];
}

// Extra dashboard KPIs (computed on the fly)
if ($year < $currentYear) {
  $limitEnd = sprintf('%04d-12-31', $year);
} elseif ($year === $currentYear) {
  $limitEnd = $today;
} else {
  // Future year: don't count any days yet.
  $limitEnd = sprintf('%04d-01-01', $year);
}
$todayInYear = (substr($today, 0, 4) === sprintf('%04d', $year));

function has_any_time_fields(array $entry): bool {
  foreach (['start','coffee_out','coffee_in','lunch_out','lunch_in','end'] as $k) {
    if (!empty($entry[$k])) return true;
  }
  return false;
}

function fmt_clock(?int $minutesOfDay): string {
  if ($minutesOfDay === null) return '—';
  $m = max(0, min(23*60+59, $minutesOfDay));
  $hh = intdiv($m, 60);
  $mm = $m % 60;
  return sprintf('%02d:%02d', $hh, $mm);
}

// Today card (only when viewing current year)
$todayCalc = null;
if ($todayInYear) {
  $eToday = $entries[$today] ?? ['date' => $today];
  if (isset($holidayMap[$today])) {
    $eToday['is_holiday'] = true;
    $eToday['special_type'] = $holidayMap[$today]['type'] ?? 'holiday';
  }
  $todayCalc = compute_day($eToday, $config);
}

// Data quality (workdays only): missing entries and incomplete days
$missingDays = 0;
$incompleteDays = 0;
$incompleteStreak = 0;

$dtStart = new DateTimeImmutable(sprintf('%04d-01-01', $year));
$dtEnd = new DateTimeImmutable($limitEnd);
// If we're viewing a future year, force an empty range.
if ($year > $currentYear) {
  $dtEnd = $dtStart->modify('-1 day');
}
for ($cur = $dtStart; $cur <= $dtEnd; $cur = $cur->modify('+1 day')) {
  $d = $cur->format('Y-m-d');
  $e = $entries[$d] ?? ['date' => $d];
  if (isset($holidayMap[$d])) {
    $e['is_holiday'] = true;
    $e['special_type'] = $holidayMap[$d]['type'] ?? 'holiday';
  }
  $calc = compute_day($e, $config);
  $expected = intval($calc['expected_minutes'] ?? 0);
  if ($expected <= 0) continue;

  $hasAny = has_any_time_fields($e);
  $start = !empty($e['start']);
  $end = !empty($e['end']);
  if (!$hasAny) {
    $missingDays++;
  } else if (!$start || !$end) {
    $incompleteDays++;
  }
}

// Incomplete streak: count consecutive workdays (from end backwards) that are incomplete
for ($cur = $dtEnd; $cur >= $dtStart; $cur = $cur->modify('-1 day')) {
  $d = $cur->format('Y-m-d');
  $e = $entries[$d] ?? ['date' => $d];
  if (isset($holidayMap[$d])) {
    $e['is_holiday'] = true;
    $e['special_type'] = $holidayMap[$d]['type'] ?? 'holiday';
  }
  $calc = compute_day($e, $config);
  $expected = intval($calc['expected_minutes'] ?? 0);
  if ($expected <= 0) continue;

  $hasAny = has_any_time_fields($e);
  $start = !empty($e['start']);
  $end = !empty($e['end']);
  $isIncomplete = ($hasAny && (!$start || !$end));
  if ($isIncomplete) {
    $incompleteStreak++;
  } else {
    break;
  }
}

// Trends: last N workdays with a computable day_balance
function last_workday_balances(int $year, string $endDate, array $entries, array $holidayMap, array $cfg, int $n = 30): array {
  $vals = [];
  $dtEnd = new DateTimeImmutable($endDate);
  $dtStart = new DateTimeImmutable(sprintf('%04d-01-01', $year));
  for ($cur = $dtEnd; $cur >= $dtStart; $cur = $cur->modify('-1 day')) {
    $d = $cur->format('Y-m-d');
    $e = $entries[$d] ?? ['date' => $d];
    if (isset($holidayMap[$d])) {
      $e['is_holiday'] = true;
      $e['special_type'] = $holidayMap[$d]['type'] ?? 'holiday';
    }
    $calc = compute_day($e, $cfg);
    $expected = intval($calc['expected_minutes'] ?? 0);
    if ($expected <= 0) continue;
    if ($calc['day_balance'] === null) continue;
    $vals[] = intval($calc['day_balance']);
    if (count($vals) >= $n) break;
  }
  return array_reverse($vals);
}

$dailyBalances = last_workday_balances($year, $limitEnd, $entries, $holidayMap, $config, 30);
$cumulativeBalances = [];
$run = 0;
foreach ($dailyBalances as $v) { $run += $v; $cumulativeBalances[] = $run; }

// Distribution: avg end time and % split (lunch taken) over last 20 workdays
$endMinutes = [];
$splitCount = 0;
$distCount = 0;
for ($cur = $dtEnd; $cur >= $dtStart; $cur = $cur->modify('-1 day')) {
  $d = $cur->format('Y-m-d');
  $e = $entries[$d] ?? ['date' => $d];
  if (isset($holidayMap[$d])) {
    $e['is_holiday'] = true;
    $e['special_type'] = $holidayMap[$d]['type'] ?? 'holiday';
  }
  $calc = compute_day($e, $config);
  $expected = intval($calc['expected_minutes'] ?? 0);
  if ($expected <= 0) continue;
  $distCount++;
  $endMin = time_to_minutes($e['end'] ?? null);
  if ($endMin !== null) $endMinutes[] = $endMin;
  if (!empty($calc['lunch_taken'])) $splitCount++;
  if ($distCount >= 20) break;
}
$avgEnd = null;
if (!empty($endMinutes)) {
  $avgEnd = intval(round(array_sum($endMinutes) / count($endMinutes)));
}
$splitPct = ($distCount > 0) ? intval(round(($splitCount / $distCount) * 100)) : 0;

$yearBalance = $ytd_worked - $ytd_expected;
$alertLowBalance = ($yearBalance <= -600);
$alertStreak = ($incompleteStreak >= 3);

function fmt($min){ return minutes_to_hours_formatted(intval($min)); }

function fmt_week_range(DateTimeImmutable $start): string {
  $end = $start->modify('+6 days');
  // Keep it compact and unambiguous.
  if ($start->format('Y') !== $end->format('Y')) {
    return $start->format('d/m/Y') . '–' . $end->format('d/m/Y');
  }
  return $start->format('d/m') . '–' . $end->format('d/m');
}

function svg_sparkline(array $values, $w=120, $h=28){
  $vals = array_values($values);
  if (empty($vals)) {
    $w = max(1, intval($w));
    $h = max(1, intval($h));
    return '<svg class="sparkline-svg" width="100%" height="100%" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg"></svg>';
  }
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
  $svg = '<svg class="sparkline-svg" width="100%" height="100%" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg">';
  $svg .= '<polyline fill="none" stroke="currentColor" stroke-width="2" points="' . $poly . '" />';
  $svg .= '</svg>';
  return $svg;
}

?>
<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Dashboard</title><link rel="icon" type="image/svg+xml" href="images/favicon.svg"><link rel="stylesheet" href="styles.css"></head><body>
<?php include __DIR__ . '/header.php'; ?>
<div class="container">
  <div class="card">
    <div class="dashboard-header">
      <h1>Dashboard</h1>
      <form method="get" action="dashboard.php" class="row-form">
        <label class="form-label small">Año
          <select class="form-control" name="year" onchange="this.form.submit()">
            <?php foreach($years as $y): ?>
              <option value="<?php echo $y; ?>" <?php if ($y === intval($year)) echo 'selected'; ?>><?php echo $y; ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </form>
    </div>

    <div class="dashboard-actions mt-2">
      <?php if ($todayInYear): ?>
        <a class="btn btn-secondary" href="index.php?year=<?php echo urlencode($year); ?>&date=<?php echo urlencode($today); ?>">Ir a hoy</a>
        <a class="btn btn-primary" href="index.php?year=<?php echo urlencode($year); ?>&date=<?php echo urlencode($today); ?>&open_add=1">Añadir hoy</a>
      <?php else: ?>
        <a class="btn btn-secondary" href="index.php?year=<?php echo urlencode($year); ?>">Ver registro</a>
      <?php endif; ?>
      <a class="btn btn-secondary" href="import.php">Importar fichajes</a>
    </div>

    <!-- Alertas -->
    <?php
      $alerts = [];
      
      // Debug
      // echo "<!-- DEBUG: todayInYear=$todayInYear, year=$year, today=$today, entryExists=" . (isset($entries[$today]) ? 'yes' : 'no') . ", inHolidayMap=" . (isset($holidayMap[$today]) ? 'yes' : 'no') . " -->";
      
      // Check if today's entry is missing (but only on working days)
      if ($todayInYear && empty($entries[$today])) {
        $eToday = $entries[$today] ?? ['date' => $today];
        if (isset($holidayMap[$today])) {
          $eToday['is_holiday'] = true;
        }
        $dayOfWeek = date('N', strtotime($today)); // 1=Mon, 6=Sat, 7=Sun
        $isWorkingDay = $dayOfWeek < 6 && empty($eToday['is_holiday']);
        
        if ($isWorkingDay) {
          $alerts[] = ['type' => 'warning', 'msg' => '⏰ No has fichado hoy'];
        }
      }
      
      // Check if entry is incomplete (missing end time)
      if ($todayInYear && !empty($entries[$today]) && empty($entries[$today]['end'])) {
        $alerts[] = ['type' => 'warning', 'msg' => '⏰ Entrada de hoy incompleta (falta hora de salida)'];
      }
      
      // Show alerts
      if (!empty($alerts)):
    ?>
      <div style="margin-top: 1rem;">
        <?php foreach ($alerts as $alert): ?>
          <div style="padding: 0.75rem 1rem; background: <?php echo $alert['type'] === 'warning' ? 'rgba(217, 119, 6, 0.12)' : 'rgba(220, 38, 38, 0.12)'; ?>; border-left: 4px solid <?php echo $alert['type'] === 'warning' ? '#d97706' : '#dc2626'; ?>; border-radius: 6px; margin-bottom: 0.5rem; border: 1px solid <?php echo $alert['type'] === 'warning' ? 'rgba(217, 119, 6, 0.25)' : 'rgba(220, 38, 38, 0.25)'; ?>;">
            <?php echo $alert['msg']; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="dashboard-cards">
      <?php
        // Calcular saldo semanal SIEMPRE relativo a hoy (semana actual y la anterior),
        // independientemente del año seleccionado. Esto puede cruzar de año; cargamos mapas por año.
        $refEnd = new DateTimeImmutable('today');
        $curWeekStart = $refEnd->modify('Monday this week');
        $prevWeekStart = $curWeekStart->modify('-7 days');

        $yearMapsCache = [];
        $getMapsForDate = function(string $isoDate) use (&$yearMapsCache, $pdo, $user) {
          $y = intval(substr($isoDate, 0, 4));
          if (!isset($yearMapsCache[$y])) {
            $yearMapsCache[$y] = load_year_maps($pdo, intval($user['id']), $y);
          }
          return $yearMapsCache[$y];
        };

        $sum_week_balance = function(DateTimeImmutable $start) use ($getMapsForDate){
          $sum = 0;
          for ($i = 0; $i < 7; $i++){
            $d = $start->modify("+$i days")->format('Y-m-d');
            [$entriesY, $holidayMapY, $cfgY] = $getMapsForDate($d);
            $e = $entriesY[$d] ?? ['date' => $d];
            if (isset($holidayMapY[$d])) { $e['is_holiday'] = true; $e['special_type'] = $holidayMapY[$d]['type'] ?? 'holiday'; }
            $calc = compute_day($e, $cfgY);
            $sum += intval($calc['day_balance'] ?? 0);
          }
          return $sum;
        };

        $prevWeekMinutes = $sum_week_balance($prevWeekStart);
        $curWeekMinutes = $sum_week_balance($curWeekStart);
      ?>
      <div class="card card--wide">
        <h4>Exceso/Defecto de horas</h4>
        <div class="week-cards">
          <?php $prevClass = $prevWeekMinutes >= 0 ? 'week-card positive' : 'week-card negative'; ?>
          <?php $curClass = $curWeekMinutes >= 0 ? 'week-card positive' : 'week-card negative'; ?>
          <div class="card dashboard-mini-card <?php echo $prevClass; ?>">Semana anterior<br><span class="muted"><?php echo htmlspecialchars(fmt_week_range($prevWeekStart)); ?></span><br><strong><?php echo minutes_to_hours_formatted($prevWeekMinutes); ?></strong></div>
          <div class="card dashboard-mini-card <?php echo $curClass; ?>">Semana actual<br><span class="muted"><?php echo htmlspecialchars(fmt_week_range($curWeekStart)); ?></span><br><strong><?php echo minutes_to_hours_formatted($curWeekMinutes); ?></strong></div>
        </div>
      </div>

      <?php if ($todayInYear && $todayCalc): ?>
      <div class="card">
        <h4>Hoy</h4>
        <div class="dashboard-value dashboard-value--sm"><?php echo htmlspecialchars($today); ?></div>
        <div class="muted">Trabajadas: <strong><?php echo $todayCalc['worked_hours_formatted'] !== '' ? htmlspecialchars($todayCalc['worked_hours_formatted']) : '—'; ?></strong></div>
        <div class="muted">Saldo: <strong><?php echo $todayCalc['day_balance_formatted'] !== '' ? htmlspecialchars($todayCalc['day_balance_formatted']) : '—'; ?></strong></div>
        <div class="muted">Café: <?php echo !empty($todayCalc['coffee_taken']) ? 'ok' : 'missing'; ?> · Comida: <?php echo !empty($todayCalc['lunch_taken']) ? 'ok' : 'missing'; ?></div>
      </div>
      <?php endif; ?>

      <div class="card">
        <h4>Calidad de datos</h4>
        <div class="muted">Laborables sin fichaje: <strong><?php echo intval($missingDays); ?></strong></div>
        <div class="muted">Días incompletos: <strong><?php echo intval($incompleteDays); ?></strong></div>
        <div class="muted">Racha incompletos: <strong><?php echo intval($incompleteStreak); ?></strong></div>
        <div class="mt-2"><a class="btn btn-secondary" href="index.php?year=<?php echo urlencode($year); ?>">Revisar</a></div>
      </div>

      <div class="card">
        <h4>Tendencia (30 laborables)</h4>
        <div class="muted">Saldo diario</div>
        <div class="sparkline"><?php echo svg_sparkline($dailyBalances, 220, 34); ?></div>
        <div class="muted dashboard-note">Saldo acumulado</div>
        <div class="sparkline"><?php echo svg_sparkline($cumulativeBalances, 220, 34); ?></div>
      </div>

      <div class="card">
        <h4>Distribución</h4>
        <div class="muted">Hora media salida (20 laborables): <strong><?php echo htmlspecialchars(fmt_clock($avgEnd)); ?></strong></div>
        <div class="muted">% jornada partida (20 laborables): <strong><?php echo intval($splitPct); ?>%</strong></div>
      </div>

      <div class="card">
        <h4>Alertas</h4>
        <?php if ($alertLowBalance): ?>
          <div class="muted">Saldo anual bajo: <strong><?php echo fmt($yearBalance); ?></strong></div>
        <?php endif; ?>
        <?php if ($alertStreak): ?>
          <div class="muted">Racha de días incompletos: <strong><?php echo intval($incompleteStreak); ?></strong></div>
        <?php endif; ?>
        <?php if (!$alertLowBalance && !$alertStreak): ?>
          <div class="muted">Sin alertas</div>
        <?php endif; ?>
      </div>

      <?php
        // Tardes trabajadas: para el año actual, mes actual/anterior; para años pasados, diciembre/noviembre.
        $mCur = ($year === $currentYear) ? intval(date('n')) : 12;
        $yCur = intval($year);
        $mPrev = $mCur - 1;
        $yPrev = $yCur;
        if ($mPrev < 1) { $mPrev = 12; $yPrev = $yCur - 1; }

        // limit current month to today only when viewing current year
        $limitCur = (intval($year) === intval(date('Y')));

        $curAfternoons = count_afternoons_worked_in_month($yCur, $mCur, $entries, $holidayMap, $config, $limitCur);

        if ($yPrev === $yCur) {
          $prevEntries = $entries;
          $prevHolidayMap = $holidayMap;
          $prevCfg = $config;
        } else {
          [$prevEntries, $prevHolidayMap, $prevCfg] = load_year_maps($pdo, intval($user['id']), $yPrev);
        }
        $prevAfternoons = count_afternoons_worked_in_month($yPrev, $mPrev, $prevEntries, $prevHolidayMap, $prevCfg, false);
      ?>
      <div class="card">
        <h4>Tardes trabajadas</h4>
        <div class="dashboard-split-cards">
          <div class="card dashboard-mini-card dashboard-mini-card--half">
            Mes actual<br><strong><?php echo intval($curAfternoons); ?></strong>
          </div>
          <div class="card dashboard-mini-card dashboard-mini-card--half">
            Mes anterior<br><strong><?php echo intval($prevAfternoons); ?></strong>
          </div>
        </div>
        <div class="muted dashboard-note">Saldo comida ≥ 1:00</div>
      </div>

      

      <div class="card">
        <h4>Acumulado año</h4>
        <div class="dashboard-value"><?php echo fmt($ytd_worked); ?></div>
        <div class="muted">Esperadas (YTD): <?php echo fmt($ytd_expected); ?></div>
      </div>

      <div class="card">
        <h4>Saldo acumulado año</h4>
        <div class="dashboard-value"><?php echo fmt($ytd_worked - $ytd_expected); ?></div>
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
        <div class="dashboard-value dashboard-value--sm"><?php echo fmt($avg); ?></div>
        <div class="muted">Basado en días procesados (incluye fines de semana filtrados)</div>
      </div>
    </div>

    <h3 class="dashboard-section-title">Resumen mensual</h3>
    <div class="table-responsive">
      <table class="sheet compact">
        <thead><tr><th>Mes</th><th>Trabajadas</th><th>Esperadas</th><th>Saldo</th><th>Exceso</th><th>Defecto</th><th>Tendencia</th></tr></thead>
        <tbody>
        <?php for ($mm=1;$mm<=12;$mm++):
            if ($year == $currentYear && $mm > $currentMonth) break;
            $w = $months[$mm]['worked']; $eexp = $months[$mm]['expected']; $bal = $w - $eexp; $ex = $bal>0 ? $bal : 0; $def = $bal<0 ? -$bal : 0;
        ?>
          <tr>
            <td><?php echo strftime('%B', mktime(0,0,0,$mm,1,$year)); ?></td>
            <td><?php echo fmt($w); ?></td>
            <td><?php echo fmt($eexp); ?></td>
            <td><?php echo fmt($bal); ?></td>
            <td><span class="badge-value" style="background: rgba(76, 175, 80, 0.15); color: #2e7d32;"><?php echo fmt($ex); ?></span></td>
            <td><span class="badge-value" style="background: rgba(244, 67, 54, 0.15); color: #c62828;"><?php echo fmt($def); ?></span></td>
            <td><div class="sparkline"><?php echo svg_sparkline(array_slice($month_values, max(1,$mm-5), min(6, $mm)),160,32); ?></div></td>
          </tr>
        <?php endfor; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php include __DIR__ . '/footer.php'; ?>
</body></html>
