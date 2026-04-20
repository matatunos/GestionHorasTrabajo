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

// Load entries for user for the year
$stmt = $pdo->prepare('SELECT * FROM entries WHERE user_id = ? AND date BETWEEN ? AND ? ORDER BY date ASC');
$stmt->execute([$user['id'], "$year-01-01", "$year-12-31"]);
$rows = $stmt->fetchAll();
$entries = [];
foreach ($rows as $r) $entries[$r['date']] = $r;

// Load holidays for year (map annual to selected year)
$holidayMap = [];
try {
  $hstmt = $pdo->prepare('SELECT date,label,type,annual,user_id FROM holidays WHERE user_id IS NULL OR user_id = ?');
  $hstmt->execute([$user['id']]);
  foreach ($hstmt->fetchAll() as $h) {
    $keyDate = $h['date'];
    if (!empty($h['annual'])) {
      $hMonth = intval(substr($h['date'], 5, 2));
      $hDay = intval(substr($h['date'], 8, 2));
      $keyDate = sprintf('%04d-%02d-%02d', $year, $hMonth, $hDay);
    } else {
      $hYear = intval(substr($h['date'], 0, 4));
      if ($hYear !== $year) {
        continue;
      }
    }
    $holidayMap[$keyDate] = ['label'=>$h['label'],'type'=>$h['type']];
  }
} catch (Throwable $e) { }

function load_year_maps(PDO $pdo, int $userId, int $year): array {
  $entries = [];
  $stmt = $pdo->prepare('SELECT * FROM entries WHERE user_id = ? AND date BETWEEN ? AND ? ORDER BY date ASC');
  $stmt->execute([$userId, sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year)]);
  foreach ($stmt->fetchAll() as $r) { $entries[$r['date']] = $r; }

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

$todayInYear = ($year === $currentYear);

// Monthly aggregation for the year
$months = [];
for ($mm=1;$mm<=12;$mm++) {
  if ($year == $currentYear && $mm > $currentMonth) break;
  $months[$mm] = ['worked' => 0, 'expected' => 0, 'ytd_worked' => 0, 'ytd_expected' => 0];
}

// YTD calculation
$ytd_worked = 0;
$ytd_expected = 0;
$month_values = [0]; // For sparklines

$dtStart = new DateTimeImmutable(sprintf('%04d-01-01', $year));
$limitEnd = ($year === $currentYear) ? $today : sprintf('%04d-12-31', $year);
$dtEnd = new DateTimeImmutable($limitEnd);

// First pass: calculate YTD and partial month data (up to today)
for ($cur = $dtStart; $cur <= $dtEnd; $cur = $cur->modify('+1 day')) {
  $d = $cur->format('Y-m-d');
  $mm = intval($cur->format('n'));
  
  $e = $entries[$d] ?? ['date' => $d];
  if (isset($holidayMap[$d])) {
    $e['is_holiday'] = true;
    $e['special_type'] = $holidayMap[$d]['type'] ?? 'holiday';
  }
  
  $calc = compute_day($e, $config);
  $w = intval($calc['worked_minutes'] ?? 0);
  $exp = intval($calc['expected_empresa_minutes'] ?? 0);
  
  if (isset($months[$mm])) {
    $months[$mm]['ytd_worked'] += $w;
    $months[$mm]['ytd_expected'] += $exp;
  }
  
  $ytd_worked += $w;
  $ytd_expected += $exp;
  
  if ($mm < count($month_values) || $mm === count($month_values)) {
    while ($mm > count($month_values)) {
      $month_values[] = 0;
    }
    $month_values[$mm] += $w - $exp;
  }
}

// Second pass: calculate full month data (all days in each month)
for ($mm = 1; $mm <= 12; $mm++) {
  if ($year == $currentYear && $mm > $currentMonth) break;
  
  $dtMonthStart = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $mm));
  $dtMonthEnd = $dtMonthStart->modify('last day of this month');
  
  for ($cur = $dtMonthStart; $cur <= $dtMonthEnd; $cur = $cur->modify('+1 day')) {
    $d = $cur->format('Y-m-d');
    
    $e = $entries[$d] ?? ['date' => $d];
    if (isset($holidayMap[$d])) {
      $e['is_holiday'] = true;
      $e['special_type'] = $holidayMap[$d]['type'] ?? 'holiday';
    }
    
    $calc = compute_day($e, $config);
    $w = intval($calc['worked_minutes'] ?? 0);
    $exp = intval($calc['expected_empresa_minutes'] ?? 0);
    
    $months[$mm]['worked'] += $w;
    $months[$mm]['expected'] += $exp;
  }
}

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

// Today info
$todayCalc = null;
if ($todayInYear) {
  $eToday = $entries[$today] ?? ['date' => $today];
  if (isset($holidayMap[$today])) {
    $eToday['is_holiday'] = true;
    $eToday['special_type'] = $holidayMap[$today]['type'] ?? 'holiday';
  }
  $todayCalc = compute_day($eToday, $config);
}

// Data quality
$missingDays = 0;
$incompleteDays = 0;
$incompleteStreak = 0;

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

// Distribution stats
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

function fmt($min){ return minutes_to_hours_formatted(intval($min)); }

function fmt_week_range(DateTimeImmutable $start): string {
  $end = $start->modify('+6 days');
  $startDay = $start->format('d');
  $startMonth = $start->format('n');
  $endDay = $end->format('d');
  $endMonth = $end->format('n');
  
  if ($startMonth === $endMonth) {
    return "$startDay - $endDay de " . strftime('%B', $start->getTimestamp());
  } else {
    return "$startDay " . strftime('%b', $start->getTimestamp()) . " - $endDay " . strftime('%b', $end->getTimestamp());
  }
}

// Weekly summary
$refDate = isset($_GET['refdate']) ? $_GET['refdate'] : date('Y-m-d');
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
    if ($weekday > 5) continue;
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

// Get log stats
$logStats = LogAnalytics::getLoginStats(30);
$securityStats = LogAnalytics::getSecurityStats(30);
$recentActivity = LogAnalytics::getRecentActivity(5);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard - Gestión de Horas</title>
  <style>
    * {
      background: none;
    }
    html, body {
      background: #ffffff !important;
      margin: 0;
      padding: 0;
    }
  </style>
  <link rel="stylesheet" href="styles.css">
  <style>
    :root {
      --card-shadow: 0 1px 3px rgba(0,0,0,0.08);
      --card-shadow-hover: 0 4px 12px rgba(0,0,0,0.12);
    }

    .dashboard-container {
      max-width: 1400px;
      margin: 0 auto;
      padding: 2rem;
      background: #ffffff;
    }

    .dashboard-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 2.5rem;
      padding-bottom: 1.5rem;
      border-bottom: 2px solid var(--card-border);
    }

    .dashboard-header h1 {
      margin: 0;
      font-size: 2rem;
      font-weight: 700;
      color: var(--text-primary);
    }

    .year-selector {
      display: flex;
      gap: 0.75rem;
      align-items: center;
    }

    .year-selector label {
      font-weight: 600;
      color: var(--text-secondary);
    }

    .year-selector select {
      padding: 0.6rem 0.75rem;
      border-radius: 6px;
      border: 1px solid var(--card-border);
      background: var(--card-bg);
      color: var(--text-primary);
      font-weight: 500;
      cursor: pointer;
      transition: all 0.2s ease;
    }

    .year-selector select:hover {
      border-color: var(--primary-color);
      box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }

    .alerts-section {
      margin-bottom: 2rem;
      display: flex;
      flex-direction: column;
      gap: 0.75rem;
    }

    .alert-item {
      padding: 1rem;
      border-radius: 8px;
      border-left: 4px solid;
      display: flex;
      align-items: center;
      gap: 0.75rem;
      font-weight: 500;
      animation: slideIn 0.3s ease;
    }

    .alert-warning {
      background: rgba(245, 158, 11, 0.06);
      border-left-color: #f59e0b;
      color: #78350f;
    }

    .alert-danger {
      background: rgba(239, 68, 68, 0.06);
      border-left-color: #ef4444;
      color: #7f1d1d;
    }

    @keyframes slideIn {
      from {
        opacity: 0;
        transform: translateY(-10px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    .section-header {
      font-size: 1.3rem;
      font-weight: 700;
      margin: 2rem 0 1.5rem 0;
      color: var(--text-primary);
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .section-header::before {
      content: '';
      width: 4px;
      height: 24px;
      background: var(--primary-color);
      border-radius: 2px;
    }

    .cards-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
      gap: 1.5rem;
      margin-bottom: 2rem;
    }

    .cards-grid-2 {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 1.5rem;
      margin-bottom: 2rem;
    }

    .card-wide {
      grid-column: 1 / -1;
    }

    .stat-card {
      background: var(--card-bg);
      border: 1px solid var(--card-border);
      border-radius: 10px;
      padding: 1.5rem;
      transition: all 0.3s ease;
      box-shadow: var(--card-shadow);
    }

    .stat-card:hover {
      border-color: var(--primary-color);
      box-shadow: var(--card-shadow-hover);
      transform: translateY(-2px);
    }

    .stat-card.gradient {
      color: #1f2937;
      border: none;
      background: linear-gradient(135deg, #e0e7ff 0%, #f0f4ff 100%);
    }

    .stat-card.gradient.blue {
      background: linear-gradient(135deg, #dbeafe 0%, #e0e7ff 100%);
      color: #1e40af;
    }

    .stat-card.gradient.green {
      background: linear-gradient(135deg, #dcfce7 0%, #f0fdf4 100%);
      color: #15803d;
    }

    .stat-card.gradient.purple {
      background: linear-gradient(135deg, #ede9fe 0%, #f5f3ff 100%);
      color: #6b21a8;
    }

    .stat-card.gradient.orange {
      background: linear-gradient(135deg, #fed7aa 0%, #fef3c7 100%);
      color: #92400e;
    }

    .stat-card.gradient.red {
      background: linear-gradient(135deg, #fee2e2 0%, #fef2f2 100%);
      color: #991b1b;
    }

    .stat-card.gradient .stat-label,
    .stat-card.gradient .stat-subtext {
      opacity: 0.8;
    }

    .stat-items {
      display: flex;
      flex-direction: column;
      gap: 0.6rem;
      margin-top: 1rem;
    }

    .stat-item {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 0.5rem 0;
      font-size: 0.95rem;
      border-bottom: 1px solid rgba(0,0,0,0.05);
    }

    .stat-item-label {
      opacity: 0.7;
    }

    .stat-item-value {
      font-weight: 600;
    }

    .week-cards {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 1rem;
      margin-top: 1rem;
    }

    .week-card {
      background: rgba(255, 255, 255, 0.08);
      border: 1px solid rgba(255, 255, 255, 0.15);
      border-radius: 8px;
      padding: 1rem;
      text-align: center;
      color: white;
    }

    .week-card.positive {
      border-color: rgba(76, 175, 80, 0.4);
      background: rgba(76, 175, 80, 0.05);
    }

    .week-card.negative {
      border-color: rgba(239, 68, 68, 0.4);
      background: rgba(239, 68, 68, 0.05);
    }

    .week-period {
      font-size: 0.75rem;
      opacity: 0.7;
      margin-bottom: 0.5rem;
    }

    .week-expected {
      font-size: 0.8rem;
      opacity: 0.8;
      margin-bottom: 0.25rem;
    }

    .week-value {
      font-size: 1.8rem;
      font-weight: 700;
      line-height: 1.2;
    }

    .table-responsive {
      background: var(--card-bg);
      border-radius: 10px;
      overflow: hidden;
      border: 1px solid var(--card-border);
      box-shadow: var(--card-shadow);
      margin-bottom: 2rem;
    }

    .sheet {
      width: 100%;
      border-collapse: collapse;
      font-size: 0.9rem;
    }

    .sheet thead {
      background: rgba(59, 130, 246, 0.08);
      color: var(--text-primary);
      font-weight: 600;
    }

    .sheet th {
      padding: 1rem;
      text-align: left;
      border-bottom: 2px solid var(--card-border);
    }

    .sheet td {
      padding: 0.9rem 1rem;
      border-bottom: 1px solid var(--card-border);
    }

    .sheet tbody tr:hover {
      background: rgba(59, 130, 246, 0.03);
    }

    .badge {
      display: inline-block;
      padding: 0.4rem 0.8rem;
      border-radius: 6px;
      font-weight: 600;
      font-size: 0.85rem;
    }

    .badge-positive {
      background: rgba(34, 197, 94, 0.08);
      color: #15803d;
    }

    .badge-negative {
      background: rgba(239, 68, 68, 0.08);
      color: #991b1b;
    }

    @media (max-width: 768px) {
      .dashboard-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 1rem;
      }

      .cards-grid-2 {
        grid-template-columns: 1fr;
      }

      .week-cards {
        grid-template-columns: 1fr;
      }

      .card-wide {
        grid-column: auto;
      }

      .sheet {
        font-size: 0.8rem;
      }

      .sheet th,
      .sheet td {
        padding: 0.6rem;
      }
    }
  </style>
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>
<div class="dashboard-container">

  <!-- Header con selector de año -->
  <div class="dashboard-header">
    <h1>📊 Dashboard</h1>
    <div class="year-selector">
      <label for="year-select">Año:</label>
      <select id="year-select" onchange="window.location='dashboard.php?year=' + this.value">
        <?php foreach ($years as $y): ?>
          <option value="<?php echo $y; ?>" <?php echo ($y === $year) ? 'selected' : ''; ?>><?php echo $y; ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <!-- Alertas -->
  <?php
    $alerts = [];
    
    if ($todayInYear && empty($entries[$today])) {
      $eToday = $entries[$today] ?? ['date' => $today];
      if (isset($holidayMap[$today])) {
        $eToday['is_holiday'] = true;
      }
      $dayOfWeek = date('N', strtotime($today));
      $isWorkingDay = $dayOfWeek < 6 && empty($eToday['is_holiday']);
      
      if ($isWorkingDay) {
        $alerts[] = ['type' => 'warning', 'msg' => '⏰ No has fichado hoy'];
      }
    }
    
    if ($todayInYear && !empty($entries[$today]) && empty($entries[$today]['end'])) {
      $alerts[] = ['type' => 'warning', 'msg' => '⚠️ Entrada de hoy incompleta (falta hora de salida)'];
    }
    
    if (!empty($alerts)):
  ?>
    <div class="alerts-section">
      <?php foreach ($alerts as $alert): ?>
        <div class="alert-item alert-<?php echo $alert['type']; ?>">
          <?php echo $alert['msg']; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <!-- Resumen rápido - Semanal y hoy -->
  <div class="section-header">📅 Resumen Semanal</div>
  <div class="cards-grid-2">
    <?php $prevClass = $prevWeekMinutes >= 0 ? 'positive' : 'negative'; ?>
    <?php $curClass = $curWeekMinutes >= 0 ? 'positive' : 'negative'; ?>
    <div class="stat-card gradient blue">
      <div class="stat-icon">📊</div>
      <div class="stat-label">Semana Anterior</div>
      <div class="week-period"><?php echo htmlspecialchars(fmt_week_range($prevWeekStart)); ?></div>
      <div style="margin-top: 0.75rem;">
        <div class="week-expected">Esperadas: <strong><?php echo minutes_to_hours_formatted($prevWeekExpected); ?></strong></div>
        <div class="week-value" style="color: <?php echo $prevWeekMinutes >= 0 ? '#4ade80' : '#fca5a5'; ?>">
          <?php echo minutes_to_hours_formatted($prevWeekMinutes); ?>
        </div>
      </div>
    </div>

    <div class="stat-card gradient green">
      <div class="stat-icon">📅</div>
      <div class="stat-label">Semana Actual</div>
      <div class="week-period"><?php echo htmlspecialchars(fmt_week_range($curWeekStart)); ?></div>
      <div style="margin-top: 0.75rem;">
        <div class="week-expected">Esperadas: <strong><?php echo minutes_to_hours_formatted($curWeekExpected); ?></strong></div>
        <div class="week-value" style="color: <?php echo $curWeekMinutes >= 0 ? '#4ade80' : '#fca5a5'; ?>">
          <?php echo minutes_to_hours_formatted($curWeekMinutes); ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Estadísticas clave del año -->
  <div class="section-header">📈 Estadísticas del Año</div>
  <div class="cards-grid">
    <div class="stat-card gradient">
      <div class="stat-icon">⏱️</div>
      <div class="stat-label">Trabajadas</div>
      <div class="stat-value"><?php echo fmt($ytd_worked); ?></div>
      <div class="stat-subtext">Año actual (YTD)</div>
    </div>

    <div class="stat-card gradient purple">
      <div class="stat-icon">📋</div>
      <div class="stat-label">Esperadas</div>
      <div class="stat-value"><?php echo fmt($ytd_expected); ?></div>
      <div class="stat-subtext">Según configuración</div>
    </div>

    <div class="stat-card gradient" style="background: linear-gradient(135deg, <?php echo ($ytd_worked - $ytd_expected) >= 0 ? '#dcfce7' : '#fee2e2'; ?> 0%, <?php echo ($ytd_worked - $ytd_expected) >= 0 ? '#f0fdf4' : '#fef2f2'; ?> 100%); color: <?php echo ($ytd_worked - $ytd_expected) >= 0 ? '#15803d' : '#991b1b'; ?>;">
      <div class="stat-icon">⚖️</div>
      <div class="stat-label">Saldo Acumulado</div>
      <div class="stat-value"><?php echo fmt($ytd_worked - $ytd_expected); ?></div>
      <div class="stat-subtext">Diferencia desde el 1 de <?php echo strftime('%B', strtotime(sprintf('%04d-01-01', $year))); ?></div>
    </div>

    <div class="stat-card gradient orange">
      <div class="stat-icon">⏳</div>
      <div class="stat-label">Media por Día</div>
      <?php
        $days = 0; $totalWork = 0;
        for ($cur = $dtStart; $cur <= $dtEnd; $cur = $cur->modify('+1 day')) {
          $d = $cur->format('Y-m-d');
          $e = $entries[$d] ?? ['date' => $d];
          $dow = (int)$cur->format('N');
          if ($dow >= 6) continue;
          if (!isset($entries[$d])) continue;
          if (!empty($e['absence_type'])) continue;
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
      <div class="stat-value"><?php echo fmt($avg); ?></div>
      <div class="stat-subtext"><?php echo $days; ?> días laborales registrados</div>
    </div>
  </div>

  <!-- Calidad de datos -->
  <div class="section-header">📊 Calidad de Datos</div>
  <div class="cards-grid">
    <div class="stat-card">
      <div class="stat-icon">❌</div>
      <div class="stat-label">Sin Fichaje</div>
      <div class="stat-value" style="color: var(--danger-color);"><?php echo intval($missingDays); ?></div>
      <div class="stat-subtext">Días sin registros</div>
      <div class="stat-items">
        <a class="btn btn-secondary" href="index.php?year=<?php echo urlencode($year); ?>" style="text-align: center; margin-top: 0.5rem;">Revisar →</a>
      </div>
    </div>

    <div class="stat-card">
      <div class="stat-icon">⚠️</div>
      <div class="stat-label">Incompletos</div>
      <div class="stat-value" style="color: var(--warning-color);"><?php echo intval($incompleteDays); ?></div>
      <div class="stat-subtext">Faltan horas inicio/fin</div>
    </div>

    <div class="stat-card">
      <div class="stat-icon">📉</div>
      <div class="stat-label">Racha Incompleta</div>
      <div class="stat-value" style="color: var(--info-color);"><?php echo intval($incompleteStreak); ?></div>
      <div class="stat-subtext">Días consecutivos</div>
    </div>
  </div>

  <!-- Resumen mensual (tabla) -->
  <div class="section-header">📆 Resumen Mensual</div>
  <div class="table-responsive">
    <table class="sheet">
      <thead>
        <tr>
          <th>Mes</th>
          <th>Trabajadas</th>
          <th>Esperadas</th>
          <th>Saldo</th>
          <th>Exceso</th>
          <th>Defecto</th>
          <th>Tendencia</th>
        </tr>
      </thead>
      <tbody>
        <?php for ($mm=1;$mm<=12;$mm++):
            if ($year == $currentYear && $mm > $currentMonth) break;
            $w = $months[$mm]['worked'] ?? 0;
            $eexp = $months[$mm]['expected'] ?? 0;
            $bal = $w - $eexp;
            $ex = $bal>0 ? $bal : 0;
            $def = $bal<0 ? -$bal : 0;
            $barColor = $bal > 0 ? '#10b981' : ($bal < 0 ? '#ef4444' : '#6b7280');
        ?>
          <tr>
            <td style="font-weight: 600;">
              <?php
                $fmt = new IntlDateFormatter('es_ES', IntlDateFormatter::NONE, IntlDateFormatter::NONE, null, null, 'LLLL');
                $dateObj = DateTime::createFromFormat('!m', $mm);
                echo ucfirst($fmt->format($dateObj));
              ?>
            </td>
            <td><?php echo fmt($w); ?></td>
            <td><?php echo fmt($eexp); ?></td>
            <td><strong style="color: <?php echo $bal >= 0 ? 'var(--success-color)' : 'var(--danger-color)'; ?>"><?php echo fmt($bal); ?></strong></td>
            <td><span class="badge badge-positive"><?php echo fmt($ex); ?></span></td>
            <td><span class="badge badge-negative"><?php echo fmt($def); ?></span></td>
            <td><div style="width:100%;height:24px;background:<?php echo $barColor; ?>;opacity:0.6;border-radius:4px;"></div></td>
          </tr>
        <?php endfor; ?>
      </tbody>
    </table>
  </div>

  <!-- Seguridad -->
  <div class="section-header">🔐 Análisis de Seguridad</div>
  <div class="cards-grid">
    <div class="stat-card">
      <div class="stat-icon">📊</div>
      <div class="stat-label">Intentos de Login (30 días)</div>
      <div class="stat-value"><?php echo $logStats['total']; ?></div>
      <div class="stat-items">
        <div class="stat-item">
          <span class="stat-item-label">✅ Exitosos</span>
          <span class="stat-item-value"><?php echo $logStats['success']; ?></span>
        </div>
        <div class="stat-item">
          <span class="stat-item-label">❌ Fallidos</span>
          <span class="stat-item-value"><?php echo $logStats['failed']; ?></span>
        </div>
        <div class="stat-item">
          <span class="stat-item-label">Tasa éxito</span>
          <span class="stat-item-value"><?php echo $logStats['success_rate']; ?>%</span>
        </div>
      </div>
    </div>

    <?php if (!empty($securityStats['suspicious_ips'])): ?>
    <div class="stat-card" style="border-left: 4px solid var(--danger-color);">
      <div class="stat-icon">🚨</div>
      <div class="stat-label">IPs Sospechosas</div>
      <div class="stat-items">
        <?php foreach ($securityStats['suspicious_ips'] as $ip => $count): ?>
          <div class="stat-item">
            <span class="stat-item-label"><?php echo htmlspecialchars($ip); ?></span>
            <span class="stat-item-value" style="color: var(--danger-color);"><?php echo $count; ?> intentos</span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

</div>
<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
