<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib.php';

$user = current_user();
require_login();

header('Content-Type: application/json');

$pdo = get_pdo();
$user_id = $user['id'];
$today = date('Y-m-d');
$current_year = (int)date('Y');
$current_week_start = date('Y-m-d', strtotime('monday this week', strtotime($today)));
$current_week_end = date('Y-m-d', strtotime('sunday this week', strtotime($today)));

/**
 * Detects current week's shift pattern (split vs continuous)
 * Based on Monday's entry: if Monday has lunch break → split shift for all week (except Friday)
 * If Monday continuous → continuous shift for all week
 * Friday is ALWAYS continuous (no lunch break)
 */
function detect_weekly_shift_pattern($pdo, $user_id, $monday_date) {
    $stmt = $pdo->prepare(
        "SELECT lunch_out, lunch_in FROM entries 
         WHERE user_id = ? AND date = ? LIMIT 1"
    );
    $stmt->execute([$user_id, $monday_date]);
    $monday_entry = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Determine if Monday has lunch break
    $has_lunch = $monday_entry && !empty($monday_entry['lunch_out']) && !empty($monday_entry['lunch_in']);
    
    return [
        'is_split_shift' => $has_lunch,  // True = jornada partida, False = jornada continua
        'applies_to_week' => true
    ];
}

/**
 * Analyzes historical work patterns with weighted averages
 * Recent entries have more weight than older ones
 */
function analyze_patterns($pdo, $user_id, $lookback_days = 90) {
    $patterns = [];
    
    // Get all historical entries with detailed breakdown
    $stmt = $pdo->prepare(
        "SELECT * FROM entries 
         WHERE user_id = ? AND date >= DATE_SUB(NOW(), INTERVAL ? DAY) 
         ORDER BY date ASC"
    );
    $stmt->execute([$user_id, $lookback_days]);
    $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Organize by day of week with timing and duration info
    for ($day = 1; $day <= 5; $day++) { // Mon-Fri only
        $patterns[$day] = [
            'entries' => [],
            'starts' => [],
            'ends' => [],
            'hours' => [],
            'lunch_durations' => [],
            'coffee_durations' => [],
            'total_count' => 0,
        ];
    }
    
    $today_ts = strtotime($today);
    
    foreach ($entries as $entry) {
        // Skip incomplete entries or non-working days
        if (empty($entry['start']) || empty($entry['end'])) continue;
        if (!empty($entry['special_type'])) continue; // Skip vacation, personal, etc.
        
        $day_of_week = (int)date('N', strtotime($entry['date']));
        if ($day_of_week > 5) continue; // Skip weekends
        
        $entry_ts = strtotime($entry['date']);
        $time_diff_days = ($today_ts - $entry_ts) / 86400;
        
        // Calculate weight: recent entries (0-7 days ago) = 3.0x, medium (7-30 days) = 2.0x, older = 1.0x
        if ($time_diff_days <= 7) {
            $weight = 3.0;
        } elseif ($time_diff_days <= 30) {
            $weight = 2.0;
        } else {
            $weight = 1.0;
        }
        
        // Calculate worked minutes (excluding lunch, including coffee)
        $start_min = time_to_minutes($entry['start']);
        $end_min = time_to_minutes($entry['end']);
        $lunch_out = time_to_minutes($entry['lunch_out']);
        $lunch_in = time_to_minutes($entry['lunch_in']);
        
        if ($start_min !== null && $end_min !== null) {
            $worked_min = $end_min - $start_min;
            
            // Subtract lunch if taken
            if ($lunch_out !== null && $lunch_in !== null) {
                $lunch_duration = $lunch_in - $lunch_out;
                $worked_min -= $lunch_duration;
                $patterns[$day_of_week]['lunch_durations'][] = $lunch_duration;
            }
            
            // Handle coffee duration
            $coffee_out = time_to_minutes($entry['coffee_out']);
            $coffee_in = time_to_minutes($entry['coffee_in']);
            if ($coffee_out !== null && $coffee_in !== null) {
                $coffee_duration = $coffee_in - $coffee_out;
                $patterns[$day_of_week]['coffee_durations'][] = $coffee_duration;
            }
            
            // Store weighted entry
            $patterns[$day_of_week]['entries'][] = [
                'date' => $entry['date'],
                'start' => $entry['start'],
                'end' => $entry['end'],
                'minutes' => $worked_min,
                'weight' => $weight,
                'days_ago' => round($time_diff_days)
            ];
            
            $patterns[$day_of_week]['starts'][] = $entry['start'];
            $patterns[$day_of_week]['ends'][] = $entry['end'];
            $patterns[$day_of_week]['hours'][] = $worked_min / 60;
            $patterns[$day_of_week]['total_count']++;
        }
    }
    
    return $patterns;
}

/**
 * Calculate weighted average of times (start/end times)
 */
function weighted_average_time($times) {
    if (empty($times)) return null;
    
    $total_minutes = 0;
    $count = 0;
    foreach ($times as $time) {
        $min = time_to_minutes($time);
        if ($min !== null) {
            $total_minutes += $min;
            $count++;
        }
    }
    
    if ($count === 0) return null;
    
    $avg_min = round($total_minutes / $count);
    $hours = intdiv($avg_min, 60);
    $mins = $avg_min % 60;
    return sprintf('%02d:%02d', $hours, $mins);
}

/**
 * Convert decimal hours to HH:MM format
 * @param float $decimal_hours Hours as decimal (e.g., 7.5 = 7:30)
 * @return string Time in HH:MM format (e.g., "07:30")
 */
function hours_to_hhmm($decimal_hours) {
    $hours = intdiv((int)($decimal_hours * 60), 60);
    $minutes = ((int)($decimal_hours * 60)) % 60;
    return sprintf('%02d:%02d', $hours, $minutes);
}

/**
 * Calculate weighted average of hours with recent-day weighting
 */
function weighted_average_hours($entries) {
    if (empty($entries)) return null;
    
    $weighted_sum = 0;
    $weight_sum = 0;
    
    foreach ($entries as $entry) {
        $hours = $entry['minutes'] / 60;
        $weight = $entry['weight'];
        $weighted_sum += $hours * $weight;
        $weight_sum += $weight;
    }
    
    return $weight_sum > 0 ? $weighted_sum / $weight_sum : null;
}

/**
 * Distribute remaining hours across days respecting constraints:
 * - No more than 1 hour difference between any two days
 * - Respects weekly shift pattern (split vs continuous)
 * - Friday: Always continuous (no lunch break), exit at 14:00 (08:00 start + 6h)
 * - Consider day-specific patterns
 * - Flexible start time from 07:30
 */
function distribute_hours($target_hours, $remaining_days, $patterns, $year_config, $today_dow, $is_split_shift = true, $force_start_time = null) {
    $suggestions = [];
    $current_week_start = date('Y-m-d', strtotime('monday this week'));
    
    // Start with equal distribution
    $base_per_day = $target_hours / count($remaining_days);
    
    // Apply day-specific adjustments based on historical patterns
    $day_adjustments = [];
    $avg_variation = 0;
    
    foreach ($remaining_days as $dow) {
        $pattern = $patterns[$dow];
        
        if ($pattern['total_count'] > 0) {
            // User has historical data for this day
            $historical_avg = weighted_average_hours($pattern['entries']);
            
            // Don't deviate more than 30 minutes from historical average
            if ($historical_avg !== null) {
                // For Friday: respect the 14:00 exit constraint
                if ($dow === 5) {
                    // Friday exit at 14:00 is fixed, don't deviate much
                    $deviation = max(-0.25, min(0.25, $historical_avg - $base_per_day));
                } else {
                    $deviation = max(-0.5, min(0.5, $historical_avg - $base_per_day));
                }
                $day_adjustments[$dow] = $deviation;
                $avg_variation += abs($deviation);
            } else {
                $day_adjustments[$dow] = 0;
            }
        } else {
            // No historical data, use day defaults from config
            $day_adjustments[$dow] = 0;
        }
    }
    
    // Normalize adjustments to keep variance <= 1 hour
    if ($avg_variation > 0) {
        $scale = min(1.0, 1.0 / $avg_variation);
        foreach ($day_adjustments as &$adj) {
            $adj *= $scale;
        }
    }
    
    // Calculate final hours per day with adjustments
    $final_hours = [];
    $total_adjusted = 0;
    
    foreach ($remaining_days as $dow) {
        $suggested = $base_per_day + ($day_adjustments[$dow] ?? 0);
        
        // Ensure minimum 5.5 hours but respect Friday constraint
        if ($dow === 5) {
            // Friday: maximum ~6 hours (exit at 14:00)
            // Typical: 08:00-14:00 = 6 hours (minus 1h lunch = 5h work)
            $min_hours = 5.0;
            $max_hours = 6.0;
            $final_hours[$dow] = max($min_hours, min($max_hours, $suggested));
        } else {
            $min_hours = max(5.5, $suggested - 1.0);
            $final_hours[$dow] = max($min_hours, $suggested);
        }
        $total_adjusted += $final_hours[$dow];
    }
    
    // Rebalance to hit exact target
    if ($total_adjusted > 0 && abs($total_adjusted - $target_hours) > 0.01) {
        $correction = ($target_hours - $total_adjusted) / count($remaining_days);
        foreach ($remaining_days as $dow) {
            $final_hours[$dow] += $correction;
        }
    }
    
    // CONSTRAINT: Friday cannot exit before 13:45 (no lunch break)
    // If start time + hours < 13:45, recalculate and distribute excess to other days
    if (in_array(5, $remaining_days)) {
        // Get Friday's start time
        $friday_pattern = $patterns[5];
        $friday_start = weighted_average_time($friday_pattern['starts']) ?? '09:00';
        if ($force_start_time) {
            $friday_start = $force_start_time;
        }
        
        $friday_start_min = time_to_minutes($friday_start);
        $min_exit_min = time_to_minutes('13:45');
        
        // Calculate minimum hours needed to reach 13:45 exit
        $min_hours_needed = ($min_exit_min - $friday_start_min) / 60;
        
        // If Friday's current hours would result in early exit, adjust
        if ($final_hours[5] < $min_hours_needed) {
            $excess_hours = $min_hours_needed - $final_hours[5];
            $final_hours[5] = $min_hours_needed; // Set Friday to minimum
            
            // Redistribute excess hours to Monday-Thursday (excluding Friday)
            $non_friday_days = array_filter($remaining_days, fn($d) => $d !== 5);
            if (!empty($non_friday_days)) {
                $excess_per_day = $excess_hours / count($non_friday_days);
                foreach ($non_friday_days as $dow) {
                    $final_hours[$dow] += $excess_per_day;
                }
            }
        }
    }
    
    // Build suggestions
    foreach ($remaining_days as $dow) {
        $day_date = date('Y-m-d', strtotime($current_week_start . " +$dow days"));
        $pattern = $patterns[$dow];
        
        $suggested_hours = round($final_hours[$dow], 2);
        // Use forced start time if provided, otherwise use historical average
        $suggested_start = $force_start_time ? $force_start_time : (weighted_average_time($pattern['starts']) ?? ($dow === 5 ? '09:00' : '08:00'));
        
        // SPECIAL HANDLING FOR FRIDAY: Always jornada continua (no lunch break)
        // CONSTRAINT: Cannot exit before 13:45 (business requirement - handled before this point)
        if ($dow === 5) {
            // Friday: Continuous shift (jornada continua), minimum exit at 13:45
            $start_min = time_to_minutes($suggested_start);
            $end_min = $start_min + ($suggested_hours * 60); // Calculate based on hours
            
            // Handle day overflow
            if ($end_min >= 1440) {
                $end_min -= 1440;
            }
            
            // At this point, $suggested_hours is already adjusted to meet 13:45 constraint
            // so $end_min should not be before 13:45
            
            $end_hours = intdiv($end_min, 60);
            $end_mins = $end_min % 60;
            $suggested_end = sprintf('%02d:%02d', $end_hours, $end_mins);
        } else {
            // For Mon-Thu: Respect weekly shift pattern
            $start_min = time_to_minutes($suggested_start);
            
            if ($is_split_shift) {
                // Jornada partida: include lunch break
                $avg_lunch = !empty($pattern['lunch_durations']) 
                    ? array_sum($pattern['lunch_durations']) / count($pattern['lunch_durations']) 
                    : 60; // Default 1 hour lunch
                
                $end_min = $start_min + ($suggested_hours * 60) + $avg_lunch;
            } else {
                // Jornada continua: no lunch break
                $end_min = $start_min + ($suggested_hours * 60);
            }
            
            // Handle day overflow
            if ($end_min >= 1440) {
                $end_min -= 1440;
            }
            
            $end_hours = intdiv($end_min, 60);
            $end_mins = $end_min % 60;
            $suggested_end = sprintf('%02d:%02d', $end_hours, $end_mins);
        }
        
        // Calculate confidence score
        $confidence = 'alta';
        if ($pattern['total_count'] < 3) {
            $confidence = 'media'; // Few historical entries
        }
        if ($pattern['total_count'] === 0) {
            $confidence = 'baja'; // No historical data
        }
        
        $day_name_map = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes'];
        
        // Build reasoning with shift pattern notes
        $reasoning = '';
        if ($pattern['total_count'] > 0) {
            $reasoning = sprintf('Basado en %d registros históricos', $pattern['total_count']);
        } else {
            $reasoning = 'Distribución inteligente para completar objetivo semanal';
        }
        
        // Add Friday-specific note (always continuous, minimum exit 13:45)
        if ($dow === 5) {
            $reasoning .= ' | Viernes: Jornada continua, salida mín. 13:45 (sin pausa comida, restricción operativa)';
        } else if ($is_split_shift) {
            // Note about split shift for Mon-Thu
            $reasoning .= ' | Jornada partida';
        }
        
        // Determine shift type and meal break info for this specific day
        $shift_type = ($dow === 5) ? 'continua' : ($is_split_shift ? 'partida' : 'continua');
        $has_lunch_break = ($shift_type === 'partida');
        $lunch_duration = $has_lunch_break ? ($year_config['lunch_minutes'] ?? 60) : 0;
        
        $suggestions[] = [
            'date' => $day_date,
            'day_name' => $day_name_map[$dow] ?? 'Unknown',
            'day_of_week' => $dow,
            'start' => $suggested_start,
            'end' => $suggested_end,
            'hours' => hours_to_hhmm($suggested_hours),
            'confidence' => $confidence,
            'pattern_count' => $pattern['total_count'],
            'reasoning' => $reasoning,
            'is_friday_split_shift' => ($dow === 5),
            'shift_type' => $shift_type,
            'shift_label' => ($shift_type === 'partida') ? 'Jornada Partida' : 'Jornada Continua',
            'has_lunch_break' => $has_lunch_break,
            'lunch_duration_minutes' => $lunch_duration,
            'lunch_note' => $has_lunch_break 
                ? sprintf('Pausa comida: ~%d min (aprox. 13:45-14:45)', $lunch_duration)
                : 'Sin pausa comida'
        ];
    }
    
    return $suggestions;
}

try {
    // Get year configuration
    $year_config = get_year_config($current_year, $user_id);
    
    // Calculate target weekly hours from config
    // IMPORTANT: Friday has SPLIT SHIFT (jornada partida)
    // 08:00-14:00 = 6 hours, but 1 hour minimum lunch = 5 hours WORKED
    $friday_config_hours = $year_config['work_hours']['winter']['friday'] ?? 6.0;
    // If Friday is split shift with 1-hour lunch: actual work = 5 hours
    // We'll account for this by using (friday_hours - 1) for calculation
    $friday_worked_hours = max(5.0, $friday_config_hours - 1.0);
    
    $target_weekly_hours = (
        ($year_config['work_hours']['winter']['mon_thu'] ?? 8.0) * 4 + 
        $friday_worked_hours
    ) / 5 * 5; // Average per full week
    
    // Get current week entries for calculation
    $stmt = $pdo->prepare(
        "SELECT * FROM entries 
         WHERE user_id = ? AND date >= ? AND date <= ? 
         ORDER BY date ASC"
    );
    $stmt->execute([$user_id, $current_week_start, $today]);
    $week_entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate hours worked this week
    $worked_hours_this_week = 0;
    $week_data = [];
    
    for ($i = 1; $i <= 7; $i++) {
        $date = date('Y-m-d', strtotime($current_week_start . " +$i days"));
        $week_data[$i] = ['date' => $date, 'hours' => 0, 'start' => null, 'end' => null];
    }
    
    foreach ($week_entries as $entry) {
        if (empty($entry['start']) || empty($entry['end'])) continue;
        
        $entry_calc = compute_day($entry, $year_config);
        
        if ($entry_calc['worked_minutes'] !== null) {
            $hours = $entry_calc['worked_minutes'] / 60;
            $worked_hours_this_week += $hours;
            
            $dow = (int)date('N', strtotime($entry['date']));
            if (isset($week_data[$dow])) {
                $week_data[$dow]['hours'] = round($hours, 2);
                $week_data[$dow]['start'] = $entry['start'];
                $week_data[$dow]['end'] = $entry['end'];
            }
        }
    }
    
    // Determine remaining days and hours needed
    $today_dow = (int)date('N');
    $remaining_days = [];
    $remaining_hours = max(0, $target_weekly_hours - $worked_hours_this_week);
    
    for ($i = $today_dow; $i <= 5; $i++) {
        if ($i >= $today_dow || ($i === $today_dow && date('H:i') < '17:00')) {
            $remaining_days[] = $i;
        }
    }
    
    // Remove current day if it's already registered
    if (isset($week_data[$today_dow]) && $week_data[$today_dow]['hours'] > 0) {
        $remaining_days = array_filter($remaining_days, fn($d) => $d !== $today_dow);
    }
    
    // Check for forced start time parameter
    $force_start_time = $_GET['force_start_time'] ?? false;
    if ($force_start_time && !empty($force_start_time)) {
        // Validate format HH:MM
        if (preg_match('/^\d{2}:\d{2}$/', $force_start_time)) {
            $force_start_time = $force_start_time;
        } else {
            $force_start_time = false;
        }
    }
    
    // Analyze historical patterns
    $patterns = analyze_patterns($pdo, $user_id, 90);
    
    // Detect shift pattern from Monday entry (if exists)
    // If Monday has lunch break → jornada partida for entire week (except Friday)
    // If Monday has NO lunch break → jornada continua for entire week
    $is_split_shift = true; // default
    $monday_date = date('Y-m-d', strtotime($current_week_start . ' +1 days'));
    $shift_detection = detect_weekly_shift_pattern($pdo, $user_id, $monday_date);
    if ($shift_detection) {
        $is_split_shift = $shift_detection['is_split_shift'];
    }
    
    // Generate smart suggestions
    $suggestions = [];
    if (!empty($remaining_days) && $remaining_hours > 0.5) {
        $suggestions = distribute_hours($remaining_hours, $remaining_days, $patterns, $year_config, $today_dow, $is_split_shift, $force_start_time);
    }
    
    echo json_encode([
        'success' => true,
        'worked_this_week' => round($worked_hours_this_week, 2),
        'target_weekly_hours' => round($target_weekly_hours, 2),
        'remaining_hours' => round($remaining_hours, 2),
        'week_data' => $week_data,
        'suggestions' => $suggestions,
        'shift_pattern' => [
            'type' => $is_split_shift ? 'jornada_partida' : 'jornada_continua',
            'label' => $is_split_shift ? 'Jornada Partida (con pausa comida)' : 'Jornada Continua (sin pausa)',
            'applies_to' => 'Lunes a Jueves (Viernes siempre es continua)',
            'detected_from' => 'Entrada del lunes de la semana actual'
        ],
        'analysis' => [
            'lookback_days' => 90,
            'patterns_analyzed' => true,
            'days_remaining' => count($remaining_days),
            'forced_start_time' => $force_start_time ? $force_start_time : null
        ],
        'message' => count($suggestions) > 0
            ? sprintf('Se sugieren horarios inteligentes para %d días basado en patrones históricos', count($suggestions))
            : ($remaining_hours < 0.5 ? 'Objetivo semanal completado ✓' : 'Sin días disponibles para completar la semana')
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'trace' => getenv('APP_DEBUG') === 'true' ? $e->getTraceAsString() : null
    ]);
}
