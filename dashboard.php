<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/lib/LogAnalytics.php';
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
  // "Tardes trabajadas" = días con saldo comida >= 0 min (como en resumen mensual de index.php)
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
    if ($lb !== null && intval($lb) >= 0) $count++;
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

// Year aggregates up to today (or full year if past), EXCLUDING weekends and any type of absence
$ytd_worked = 0; $ytd_expected = 0;
$dtStart = new DateTimeImmutable(sprintf('%04d-01-01', $year));
$dtEnd = ($year == $currentYear) ? new DateTimeImmutable(date('Y-m-d')) : new DateTimeImmutable(sprintf('%04d-12-31', $year));
for ($cur = $dtStart; $cur <= $dtEnd; $cur = $cur->modify('+1 day')) {
  $d = $cur->format('Y-m-d');
  $e = $entries[$d] ?? ['date' => $d];
  $dow = (int)$cur->format('N');
  if ($dow >= 6) continue; // Exclude weekends
  if (!empty($e['absence_type'])) continue; // Exclude absences
  if (isset($holidayMap[$d]) && in_array($holidayMap[$d]['type'], ['vacation','illness','permiso','personal','other'])) continue; // Exclude special absence holidays
  $calc = compute_day($e, $config);
  $expected = intval($calc['expected_minutes'] ?? 0);
  $worked = intval($calc['worked_minutes_for_display'] ?? 0);
  if ($expected <= 0) continue; // Only real workdays
  $ytd_expected += $expected;
  $ytd_worked += $worked;
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


// Distribution: avg end time and % split (lunch taken) over ALL workdays of the year
$endMinutes = [];
$splitCount = 0;
$distCount = 0;
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
  $distCount++;
  $endMin = time_to_minutes($e['end'] ?? null);
  if ($endMin !== null) $endMinutes[] = $endMin;
  if (!empty($calc['lunch_taken'])) $splitCount++;
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
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Dashboard</title><link rel="icon" type="image/svg+xml" href="images/favicon.svg"><link rel="stylesheet" href="styles.css"><link rel="stylesheet" href="css/dashboard-theme.css"></head><body class="page-dashboard">
<?php $hidePageHeader = true; include __DIR__ . '/header.php'; ?>
  <div class="container">
    <div class="dashboard-header-card">
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
        <a class="btn btn-primary" href="index.php?year=<?php echo urlencode($year); ?>&date=<?php echo urlencode($today); ?>&open_add=1">➕ Añadir fichaje</a>
        <a class="btn btn-secondary" href="index.php?year=<?php echo urlencode($year); ?>&date=<?php echo urlencode($today); ?>">📅 Ir a hoy</a>
      <?php else: ?>
        <a class="btn btn-secondary" href="index.php?year=<?php echo urlencode($year); ?>">📋 Ver registro</a>
      <?php endif; ?>
    </div>
    </div>

    <!-- Alertas -->
    <?php
      $alerts = [];
      
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
          <div class="dashboard-alert alert-<?php echo $alert['type']; ?>">
            <?php echo $alert['msg']; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="dashboard-cards">
      <?php
        // Calcular saldo semanal SIEMPRE relativo a hoy (semana actual y la anterior),
        // independientemente del año seleccionado. Esto puede cruzar de año; cargamos mapas por año.
        // Use selected year and reference date for weekly summary
        $refDate = isset($_GET['refdate']) ? $_GET['refdate'] : sprintf('%04d-01-15', $year); // Default: mid-Jan of selected year
        $refEnd = new DateTimeImmutable($refDate);
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

        $sum_week_expected = function(DateTimeImmutable $start) use ($getMapsForDate){
          $sum = 0;
          for ($i = 0; $i < 7; $i++){
            $d = $start->modify("+$i days")->format('Y-m-d');
            [$entriesY, $holidayMapY, $cfgY] = $getMapsForDate($d);
            $e = $entriesY[$d] ?? ['date' => $d];
            $weekday = date('N', strtotime($d));
            if ($weekday > 5) continue; // solo lunes a viernes
            if (isset($holidayMapY[$d])) { $e['is_holiday'] = true; $e['special_type'] = $holidayMapY[$d]['type'] ?? 'holiday'; }
            $calc = compute_day($e, $cfgY);
            $sum += intval($calc['expected_minutes'] ?? 0);
          }
          return $sum;
        };

        $prevWeekMinutes = $sum_week_balance($prevWeekStart);
        $curWeekMinutes = $sum_week_balance($curWeekStart);
        $prevWeekExpected = $sum_week_expected($prevWeekStart);
        $curWeekExpected = $sum_week_expected($curWeekStart);
      ?>
      <div class="admin-stat-card card--wide">
        <div class="admin-stat-icon">🗓️</div>
        <h4>Resumen semanal</h4>
        <div class="week-cards" style="overflow: hidden; max-height: 140px;">
          <?php $prevClass = $prevWeekMinutes >= 0 ? 'week-card positive' : 'week-card negative'; ?>
          <?php $curClass = $curWeekMinutes >= 0 ? 'week-card positive' : 'week-card negative'; ?>
          <div class="card dashboard-mini-card <?php echo $prevClass; ?>" style="font-size: 0.9rem;">
            <div style="margin-bottom:0.5rem;">📅 Anterior</div>
            <div class="muted" style="font-size:0.75rem;margin-bottom:0.25rem;"><?php echo htmlspecialchars(fmt_week_range($prevWeekStart)); ?></div>
            <strong style="color:var(--neutral-600);font-size:0.85rem;">T: <?php echo minutes_to_hours_formatted($prevWeekExpected); ?></strong>
            <strong style="color:var(--primary-color);font-size:0.9rem;">S: <?php echo minutes_to_hours_formatted($prevWeekMinutes); ?></strong>
          </div>
          <div class="card dashboard-mini-card <?php echo $curClass; ?>" style="font-size: 0.9rem;">
            <div style="margin-bottom:0.5rem;">📅 Actual</div>
            <div class="muted" style="font-size:0.75rem;margin-bottom:0.25rem;"><?php echo htmlspecialchars(fmt_week_range($curWeekStart)); ?></div>
            <strong style="color:var(--neutral-600);font-size:0.85rem;">T: <?php echo minutes_to_hours_formatted($curWeekExpected); ?></strong>
            <strong style="color:var(--primary-color);font-size:0.9rem;">S: <?php echo minutes_to_hours_formatted($curWeekMinutes); ?></strong>
          </div>
        </div>
      </div>


      <!-- Card Dietas: semana pasada y acumulado actual -->
      <?php
        // Suponiendo que hay una función get_dietas($start, $end) que devuelve el total de dietas entre dos fechas
        // Mostrar dietas para mes pasado y mes actual
        $today = new DateTimeImmutable();
        $curMonthStart = $today->modify('first day of this month');
        $curMonthEnd = $today->modify('last day of this month');
        $prevMonthStart = $curMonthStart->modify('-1 month')->modify('first day of this month');
        $prevMonthEnd = $curMonthStart->modify('-1 day');
        // Implementación real: contar días con lunch_balance >= 0 en el rango
        function get_dietas($startDate, $endDate) {
          global $pdo, $user;
          $start = new DateTimeImmutable($startDate);
          $end = new DateTimeImmutable($endDate);
          $count = 0;
          for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
            $dateStr = $d->format('Y-m-d');
            $stmt = $pdo->prepare('SELECT * FROM entries WHERE user_id = ? AND date = ?');
            $stmt->execute([$user['id'], $dateStr]);
            $entry = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$entry) continue;
            $entry['user_id'] = $user['id'];
            // Cargar config correcto para el año
            $cfg = get_year_config(intval(substr($dateStr, 0, 4)), $user['id']);
            $calc = compute_day($entry, $cfg);
            $lb = $calc['lunch_balance'] ?? null;
            if ($lb !== null && intval($lb) >= 0) $count++;
          }
          return $count;
        }
        $dietasPrev = get_dietas($prevMonthStart->format('Y-m-d'), $prevMonthEnd->format('Y-m-d'));
        $dietasCur = get_dietas($curMonthStart->format('Y-m-d'), $curMonthEnd->format('Y-m-d'));
      ?>
      <div class="admin-stat-card dietas-card">
        <div class="admin-stat-icon" style="font-size:2.5rem;line-height:1;">🍽️</div>
        <h4>Dietas</h4>
        <div style="display:flex;gap:3rem;align-items:center;justify-content:center;width:100%;">
          <div style="text-align:center;">
            <div class="muted" style="font-size:0.85rem;text-transform:uppercase;letter-spacing:0.5px;">Mes pasado</div>
            <div style="font-size:2.8rem;font-weight:700;color:var(--success-color);line-height:1.1;"><?php echo $dietasPrev; ?></div>
          </div>
          <div style="text-align:center;">
            <div class="muted" style="font-size:0.85rem;text-transform:uppercase;letter-spacing:0.5px;">Mes actual</div>
            <div style="font-size:2.8rem;font-weight:700;color:var(--primary-color);line-height:1.1;"><?php echo $dietasCur; ?></div>
          </div>
        </div>
      </div>

      <div class="admin-stat-card">
        <div class="admin-stat-icon">📊</div>
        <h4>Calidad de datos</h4>
        <div class="muted">
          <div>❌ Sin fichaje: <strong><?php echo intval($missingDays); ?></strong></div>
          <div>⚠️ Incompletos: <strong><?php echo intval($incompleteDays); ?></strong></div>
          <div>📉 Racha: <strong><?php echo intval($incompleteStreak); ?></strong></div>
        </div>
        <div class="mt-2"><a class="btn btn-secondary" href="index.php?year=<?php echo urlencode($year); ?>" style="display:inline-block;">Revisar →</a></div>
      </div>



      <div class="admin-stat-card">
        <div class="admin-stat-icon">📈</div>
        <h4>Distribución</h4>
        <div class="muted">
          <div>⏰ Hora media salida: <strong><?php echo htmlspecialchars(fmt_clock($avgEnd)); ?></strong></div>
          <div>🍽️ % Jornada partida: <strong><?php echo intval($splitPct); ?>%</strong></div>
        </div>
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
      <div class="admin-stat-card afternoons-card">
        <div class="admin-stat-icon">🌇</div>
        <h4>Tardes trabajadas</h4>
        <div class="afternoons-split">
          <div class="afternoons-item">
            <div class="afternoons-label">Mes actual</div>
            <strong class="afternoons-value afternoons-current"><?php echo intval($curAfternoons); ?></strong>
          </div>
          <div class="afternoons-item">
            <div class="afternoons-label">Mes anterior</div>
            <strong class="afternoons-value afternoons-previous"><?php echo intval($prevAfternoons); ?></strong>
          </div>
        </div>
        <div class="dashboard-note">📝 Saldo comida ≥ 1:00</div>
      </div>

      

      <div class="admin-stat-card">
        <div class="admin-stat-icon">☕</div>
        <h4>Acumulado año</h4>
        <div class="dashboard-value"><?php echo fmt($ytd_worked); ?></div>
        <div class="muted">Esperadas (YTD): <?php echo fmt($ytd_expected); ?></div>
      </div>

      <div class="admin-stat-card">
        <div class="admin-stat-icon">⚖️</div>
        <h4>Saldo acumulado</h4>
        <div class="dashboard-value" style="color:<?php echo ($ytd_worked - $ytd_expected) >= 0 ? 'var(--success-color)' : 'var(--danger-color)'; ?>;"><?php echo fmt($ytd_worked - $ytd_expected); ?></div>
        <div class="muted">Desde el 1 de <?php echo date('F', strtotime(sprintf('%04d-01-01', $year))); ?></div>
      </div>

      <div class="admin-stat-card">
        <div class="admin-stat-icon">⏳</div>
        <h4>Media horas/día</h4>
        <?php
          // Nueva lógica: solo días laborables CON ENTRADA (no fines de semana, no ausencias, no días sin registros)
          $days = 0; $totalWork = 0;
          $dtStart = new DateTimeImmutable(sprintf('%04d-01-01', $year));
          $dtEnd = ($year == $currentYear) ? new DateTimeImmutable(date('Y-m-d')) : new DateTimeImmutable(sprintf('%04d-12-31', $year));
          for ($cur = $dtStart; $cur <= $dtEnd; $cur = $cur->modify('+1 day')) {
            $d = $cur->format('Y-m-d');
            $e = $entries[$d] ?? ['date' => $d];
            $dow = (int)$cur->format('N');
            if ($dow >= 6) continue; // Excluir sábados y domingos
            if (!isset($entries[$d])) continue; // SOLO días con entrada real
            if (!empty($e['absence_type'])) continue; // Excluir ausencias
            if (isset($holidayMap[$d]) && in_array($holidayMap[$d]['type'], ['vacation','illness','permiso','personal','other'])) continue;
            $calc = compute_day($e, $config);
            $expected = intval($calc['expected_minutes'] ?? 0);
            if ($expected <= 0) continue;
            $worked = intval($calc['worked_minutes_for_display'] ?? 0);
            $days++;
            $totalWork += $worked;
          }
          $avg = $days>0 ? intval(round($totalWork / $days)) : 0;
        ?>
        <div class="dashboard-value dashboard-value--sm"><?php echo fmt($avg); ?></div>
        <div class="muted"><?php echo $days; ?> días laborables</div>
      </div>
    </div>

    <!-- SECURITY ANALYTICS SECTION -->
    <?php
      // Get log statistics - only for admin/current user view
      $logStats = LogAnalytics::getLoginStats(30); // Last 30 days
      $securityStats = LogAnalytics::getSecurityStats(30);
      $recentActivity = LogAnalytics::getRecentActivity(5);
    ?>
    <h3 class="dashboard-section-title">🔐 Análisis de Seguridad</h3>
    <div class="dashboard-cards">
      <!-- Login Statistics Card -->
      <div class="admin-stat-card">
        <div class="admin-stat-icon">📊</div>
        <h4>Intentos de login (30 días)</h4>
        <div class="dashboard-value"><?php echo $logStats['total']; ?></div>
        <div class="muted">
          <div>✅ Exitosos: <strong><?php echo $logStats['success']; ?></strong></div>
          <div>❌ Fallidos: <strong><?php echo $logStats['failed']; ?></strong></div>
          <div>Tasa éxito: <strong><?php echo $logStats['success_rate']; ?>%</strong></div>
        </div>
      </div>

      <!-- Failed Login Reasons Card -->
      <div class="card">
        <h4>Razones de fallos</h4>
        <?php if (!empty($logStats['failed_reasons'])): ?>
          <div class="muted">
            <?php foreach ($logStats['failed_reasons'] as $reason => $count): ?>
              <div>
                <?php 
                  $reasonLabel = $reason === 'user_not_found' ? '👤 Usuario no encontrado' :
                                 ($reason === 'invalid_password' ? '🔑 Contraseña inválida' : 
                                  htmlspecialchars($reason));
                ?>
                <?php echo $reasonLabel; ?>: <strong><?php echo $count; ?></strong>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="muted">Sin intentos fallidos 🎉</div>
        <?php endif; ?>
      </div>

      <!-- Top IPs with Failed Attempts Card -->
      <div class="card">
        <h4>IPs con fallos</h4>
        <?php if (!empty($securityStats['suspicious_ips']) || !empty($logStats['top_ips'])): ?>
          <div class="muted">
            <?php 
              // Show suspicious IPs first, then all IPs
              if (!empty($securityStats['suspicious_ips'])): 
                foreach ($securityStats['suspicious_ips'] as $ip => $count):
            ?>
              <div style="padding: 0.5rem; background: rgba(220, 38, 38, 0.1); border-radius: 4px; margin-bottom: 0.25rem; border-left: 3px solid #dc2626;">
                🚨 <?php echo htmlspecialchars($ip); ?><br>
                <small><?php echo $count; ?> intentos fallidos</small>
              </div>
            <?php 
                endforeach;
              endif;
              
              // Show all IPs
              if (!empty($logStats['top_ips'])):
                foreach ($logStats['top_ips'] as $ip => $count):
            ?>
              <div>
                📍 <?php echo htmlspecialchars($ip); ?>: <strong><?php echo $count; ?></strong>
              </div>
            <?php 
                endforeach;
              endif;
            ?>
          </div>
        <?php else: ?>
          <div class="muted">Sin intentos registrados</div>
        <?php endif; ?>
      </div>

      <!-- Security Alerts Card -->
      <div class="card">
        <h4>Alertas de seguridad</h4>
        <?php if (!empty($securityStats['alerts'])): ?>
          <div>
            <?php foreach ($securityStats['alerts'] as $alert): ?>
              <div style="padding: 0.75rem; background: rgba(217, 119, 6, 0.1); border-left: 3px solid #d97706; border-radius: 4px; margin-bottom: 0.5rem;">
                ⚠️ <?php echo htmlspecialchars($alert); ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="muted">✅ Sin alertas - Sistema seguro</div>
        <?php endif; ?>
      </div>

      <!-- Recent Activity Card -->
      <div class="card card--wide">
        <h4>Actividad reciente de login</h4>
        <?php if (!empty($recentActivity)): ?>
          <div style="max-height: 250px; overflow-y: auto;">
            <table class="sheet compact" style="font-size: 0.85rem;">
              <thead>
                <tr>
                  <th>Hora</th>
                  <th>Usuario</th>
                  <th>Acción</th>
                  <th>IP</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($recentActivity as $activity): ?>
                  <tr>
                    <td>
                      <small><?php echo htmlspecialchars(substr($activity['timestamp'], 11, 8) ?? '—'); ?></small>
                    </td>
                    <td>
                      <small><?php echo htmlspecialchars($activity['username']); ?></small>
                    </td>
                    <td>
                      <small>
                        <?php 
                          if ($activity['action'] === 'LOGIN_SUCCESS') {
                            echo '✅ Éxito';
                          } elseif ($activity['action'] === 'LOGIN_FAILED') {
                            $reasonLabel = $activity['reason'] === 'user_not_found' ? '(usuario no existe)' :
                                          ($activity['reason'] === 'invalid_password' ? '(contraseña inválida)' :
                                           '(' . htmlspecialchars($activity['reason']) . ')');
                            echo '❌ Falló ' . $reasonLabel;
                          } else {
                            echo htmlspecialchars($activity['action']);
                          }
                        ?>
                      </small>
                    </td>
                    <td>
                      <small><?php echo htmlspecialchars($activity['ip']); ?></small>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <div class="muted">Sin actividad registrada</div>
        <?php endif; ?>
      </div>
    </div>
    <!-- END SECURITY ANALYTICS SECTION -->

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
            <td><?php
              $fmt = new IntlDateFormatter('es_ES', IntlDateFormatter::NONE, IntlDateFormatter::NONE, null, null, 'LLLL');
              $dateObj = DateTime::createFromFormat('!m', $mm);
              echo ucfirst($fmt->format($dateObj));
            ?></td>
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
  </div> <!-- .container -->
<?php include __DIR__ . '/footer.php'; ?>
</body></html>
