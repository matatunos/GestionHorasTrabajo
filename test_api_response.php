<?php
/**
 * Test schedule_suggestions with actual DB data - Week Jan 5-9, 2026
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib.php';

$pdo = get_pdo();
$user_id = 1;
$today = '2026-01-07';
$current_year = 2026;
$week_start = '2026-01-05';
$week_end = '2026-01-11';

// Get year config
$year_config = get_year_config($current_year, $user_id);

// Load holidays - query ALL holidays to reconstruct annual ones
$holidays_this_week = [];
try {
    $q = 'SELECT date, annual FROM holidays WHERE (user_id = ? OR user_id IS NULL)';
    $s = $pdo->prepare($q);
    $s->execute([$user_id]);
    $holidays_raw = $s->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($holidays_raw as $hol) {
        $hDate = $hol['date'];
        // For annual holidays, reconstruct for current year
        if (!empty($hol['annual'])) {
            $hMonth = intval(substr($hDate, 5, 2));
            $hDay = intval(substr($hDate, 8, 2));
            $hDate = sprintf('%04d-%02d-%02d', $current_year, $hMonth, $hDay);
        }
        // Only include if within current week
        if ($hDate >= $week_start && $hDate <= $week_end) {
            $holidays_this_week[$hDate] = true;
        }
    }
} catch (Exception $e) {}

$holidays = array_keys($holidays_this_week);

// Target
$friday_worked = max(5.0, (($year_config['work_hours']['winter']['friday'] ?? 6.0) - 1.0));
$target = (($year_config['work_hours']['winter']['mon_thu'] ?? 8.0) * 4) + $friday_worked;

foreach ($holidays as $h_date) {
    $dow = (int)date('N', strtotime($h_date));
    if ($dow >= 1 && $dow <= 5) {
        $target -= ($dow === 5) ? $friday_worked : 8.0;
    }
}

// Worked
$q = 'SELECT * FROM entries WHERE user_id = ? AND date >= ? AND date <= ? ORDER BY date';
$s = $pdo->prepare($q);
$s->execute([$user_id, $week_start, $today]);
$entries = $s->fetchAll(PDO::FETCH_ASSOC);

$worked = 0;
foreach ($entries as $e) {
    if (empty($e['start']) || empty($e['end'])) continue;
    $calc = compute_day($e, $year_config);
    if ($calc['worked_minutes_for_display']) {
        $worked += $calc['worked_minutes_for_display'] / 60;
    }
}

// Remaining
$remaining = max(0, $target - $worked);
$today_dow = (int)date('N', strtotime($today));
$rem_days = [];

for ($i = $today_dow; $i <= 5; $i++) {
    $d = date('Y-m-d', strtotime($week_start . " +" . ($i - 1) . " days"));
    if (!in_array($d, $holidays)) {
        $rem_days[] = $i;
    }
}

// Has worked today?
$today_worked = 0;
foreach ($entries as $e) {
    if ($e['date'] === $today && !empty($e['start']) && !empty($e['end'])) {
        $calc = compute_day($e, $year_config);
        if ($calc['worked_minutes_for_display']) {
            $today_worked = $calc['worked_minutes_for_display'] / 60;
        }
    }
}

if ($today_worked > 0) {
    $rem_days = array_filter($rem_days, fn($d) => $d !== $today_dow);
}

// Result
$output = [
    'status' => 'success',
    'week' => '5-9 enero 2026',
    'today' => $today . ' (' . ['', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes'][$today_dow] . ')',
    'target_hours' => round($target, 2),
    'worked_hours' => round($worked, 2),
    'remaining_hours' => round($remaining, 2),
    'days_remaining' => count($rem_days),
    'hours_per_day' => count($rem_days) > 0 ? round($remaining / count($rem_days), 2) : 0,
    'message' => sprintf(
        'Usuario ha trabajado %.2fh de %.2fh. Pendiente: %.2fh en %d días (aprox %.2fh/día)',
        $worked,
        $target,
        $remaining,
        count($rem_days),
        count($rem_days) > 0 ? $remaining / count($rem_days) : 0
    )
];

echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>
