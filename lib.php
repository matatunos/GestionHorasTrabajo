<?php
require_once __DIR__ . '/config.php';

function is_summer_date(string $date, array $config): bool {
    $y = date('Y', strtotime($date));
    $start = strtotime("$y-" . $config['summer_start']);
    $end = strtotime("$y-" . $config['summer_end']);
    $t = strtotime($date);
    return ($t >= $start && $t <= $end);
}

function time_to_minutes(?string $time): ?int {
    if (!$time) return null;
    $parts = explode(':', $time);
    if (count($parts) < 2) return null;
    return intval($parts[0]) * 60 + intval($parts[1]);
}

function minutes_to_hours_formatted(?int $min): string {
    if ($min === null) return '';
    $sign = $min < 0 ? '-' : '';
    $m = abs($min);
    $h = intdiv($m, 60);
    $r = $m % 60;
    return $sign . sprintf('%d:%02d', $h, $r);
}

function compute_day(array $entry, array $config = null): array {
    // expected minutes
    // if no config provided, fetch by year
    if ($config === null) {
        $year = date('Y', strtotime($entry['date']));
        $config = get_year_config(intval($year));
    }
    $isSummer = is_summer_date($entry['date'], $config);
    $weekday = date('N', strtotime($entry['date'])); // 1-7
    $season = $isSummer ? 'summer' : 'winter';
    // weekends or explicit holidays are non-working days by default
    $isHolidayFlag = !empty($entry['is_holiday']);
    // also consider vacation or personal leave
    $isVacation = isset($entry['special_type']) && $entry['special_type'] === 'vacation';
    $isPersonal = isset($entry['special_type']) && $entry['special_type'] === 'personal';
    if ($weekday >= 6 || $isHolidayFlag || $isVacation || $isPersonal) {
        $expected_hours = 0.0;
    } else {
        $expected_hours = ($weekday === 5) ? $config['work_hours'][$season]['friday'] : $config['work_hours'][$season]['mon_thu'];
    }
    $expected_minutes = intval(round($expected_hours * 60));

    $start = time_to_minutes($entry['start'] ?? null);
    $coffee_out = time_to_minutes($entry['coffee_out'] ?? null);
    $coffee_in = time_to_minutes($entry['coffee_in'] ?? null);
    $lunch_out = time_to_minutes($entry['lunch_out'] ?? null);
    $lunch_in = time_to_minutes($entry['lunch_in'] ?? null);
    $end = time_to_minutes($entry['end'] ?? null);

    $coffee_duration = null;
    if ($coffee_out !== null && $coffee_in !== null) $coffee_duration = $coffee_in - $coffee_out;
    if ($coffee_duration === null) $coffee_duration = 0;

    $lunch_duration = null;
    if ($lunch_out !== null && $lunch_in !== null) $lunch_duration = $lunch_in - $lunch_out;
    if ($lunch_duration === null) $lunch_duration = 0;

    $worked_minutes = null;
    if ($start !== null && $end !== null) {
        // coffee counts as work, lunch does not
        $worked_minutes = ($end - $start) - $lunch_duration;
    } else {
        $worked_minutes = 0;
    }

    $day_balance = $worked_minutes - $expected_minutes;

    // coffee balance compared to configured coffee minutes
    $coffee_balance = $coffee_duration - intval($config['coffee_minutes']);
    // lunch balance: actual minus configured (positive means longer than configured)
    $lunch_balance = $lunch_duration - intval($config['lunch_minutes']);

    // For weekend days with no times recorded, show blank balances to avoid confusing negatives
    $hasAnyTime = ($start !== null || $coffee_out !== null || $coffee_in !== null || $lunch_out !== null || $lunch_in !== null || $end !== null);
    if ($weekday >= 6 && !$hasAnyTime) {
        $worked_minutes = 0;
        $day_balance = 0;
        $coffee_balance = null;
        $lunch_balance = null;
        $blankWeekendDisplay = true;
    } else {
        $blankWeekendDisplay = false;
    }

    return [
        'season' => $season,
        'expected_minutes' => $expected_minutes,
        'worked_minutes' => $worked_minutes,
        'day_balance' => $day_balance,
        'coffee_duration' => $coffee_duration,
        'coffee_balance' => $coffee_balance,
        'lunch_duration' => $lunch_duration,
        'lunch_balance' => $lunch_balance,
        'worked_hours_formatted' => $blankWeekendDisplay ? '' : minutes_to_hours_formatted($worked_minutes),
        'day_balance_formatted' => $blankWeekendDisplay ? '' : minutes_to_hours_formatted($day_balance),
        'coffee_balance_formatted' => minutes_to_hours_formatted($coffee_balance),
        'lunch_balance_formatted' => minutes_to_hours_formatted($lunch_balance),
    ];
}
