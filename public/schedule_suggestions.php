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
 * - Friday: Always continuous (no lunch break), exit at 14:10 (08:00 start + 6h max)
 * - Consider day-specific patterns
 * - Flexible start time from 07:30
 * - If forced early start: recalculates to allow earlier finish times
 */
function distribute_hours($target_hours, $remaining_days, $patterns, $year_config, $today_dow, $is_split_shift = true, $force_start_time = null) {
    $suggestions = [];
    $current_week_start = date('Y-m-d', strtotime('monday this week'));
    
    // If force_start_time is earlier than normal (08:00), 
    // recalculate target to allow earlier finish times
    $adjusted_target = $target_hours;
    $early_start_minutes_saved = 0;
    if ($force_start_time) {
        $force_start_min = time_to_minutes($force_start_time);
        $normal_start_min = time_to_minutes('08:00');
        
        if ($force_start_min < $normal_start_min) {
            // User is starting early (e.g., 7:30 instead of 8:00 = 30 min early)
            // They can finish earlier each day AND work less total
            // Early start advantage: 30 min early × 0.5 factor = 15 min less work needed per day
            // This accounts for natural rhythm/efficiency and commute time saved
            $early_minutes = $normal_start_min - $force_start_min;
            $early_start_minutes_saved = $early_minutes * 0.5 * count($remaining_days);  // 50% efficiency gain
            
            if ($early_start_minutes_saved > 10) {
                // Reduce target hours based on accumulated savings
                // Example: 30 min early × 0.5 × 5 days = 75 min (1.25h) saved
                $adjusted_target = $target_hours - ($early_start_minutes_saved / 60);
                // But cap the reduction to not go below 95% (safety margin)
                $adjusted_target = max($adjusted_target, $target_hours * 0.95);
            }
        }
    }
    
    // Start with equal distribution
    $base_per_day = $adjusted_target / count($remaining_days);
    
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
            // Friday: maximum 6 hours (exit at 14:00)
            // Typical: 08:00-14:00 = 6 hours (no lunch break)
            $min_hours = 5.0;
            $max_hours = 6.0;  // Cannot exceed 14:00 exit
            $final_hours[$dow] = max($min_hours, min($max_hours, $suggested));
        } else {
            $min_hours = max(5.5, $suggested - 1.0);
            $final_hours[$dow] = max($min_hours, $suggested);
        }
        $total_adjusted += $final_hours[$dow];
    }
    
    // Rebalance to hit exact target (protect Friday from excess)
    if ($total_adjusted > 0 && abs($total_adjusted - $adjusted_target) > 0.01) {
        // Only rebalance non-Friday days to protect Friday's 6-hour maximum
        $non_friday_days = array_filter($remaining_days, fn($d) => $d !== 5);
        
        if (!empty($non_friday_days)) {
            $friday_hours = $final_hours[5] ?? 0;
            $non_friday_hours = array_sum(array_map(fn($d) => $final_hours[$d], $non_friday_days));
            $remaining_target = $adjusted_target - $friday_hours;
            $correction = ($remaining_target - $non_friday_hours) / count($non_friday_days);
            
            foreach ($non_friday_days as $dow) {
                $final_hours[$dow] += $correction;
            }
        } else {
            // If only Friday remains, distribute all remaining hours (but this shouldn't happen)
            $correction = ($adjusted_target - $total_adjusted) / count($remaining_days);
            foreach ($remaining_days as $dow) {
                $final_hours[$dow] += $correction;
            }
        }
    }
    
    // ENFORCE: Friday maximum 6 hours (exit at 14:00 in winter, no lunch break)
    // This should not be needed now but kept as safety check
    if (in_array(5, $remaining_days) && isset($final_hours[5]) && $final_hours[5] > 6.0) {
        $excess = $final_hours[5] - 6.0;
        $final_hours[5] = 6.0;  // Cap at 6 hours
        
        // Redistribute excess hours to other days (Monday-Thursday)
        $non_friday_days = array_filter($remaining_days, fn($d) => $d !== 5);
        if (!empty($non_friday_days)) {
            $excess_per_day = $excess / count($non_friday_days);
            foreach ($non_friday_days as $dow) {
                $final_hours[$dow] += $excess_per_day;
            }
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
    
    // Add info about early start adjustment
    $early_start_info = null;
    if ($force_start_time && $adjusted_target < $target_hours) {
        $time_saved_min = ($target_hours - $adjusted_target) * 60;
        $early_start_info = sprintf('Entrada temprana a %s: Ahorra ~%.0f min en jornada', $force_start_time, $time_saved_min);
    }
    
    // Build suggestions
    foreach ($remaining_days as $dow) {
        $day_date = date('Y-m-d', strtotime($current_week_start . " +$dow days"));
        $pattern = $patterns[$dow];
        
        $suggested_hours = round($final_hours[$dow], 2);
        // Use forced start time if provided, otherwise use historical average
        $suggested_start = $force_start_time ? $force_start_time : (weighted_average_time($pattern['starts']) ?? ($dow === 5 ? '09:00' : '08:00'));
        
        // SPECIAL HANDLING FOR FRIDAY: Always jornada continua (no lunch break)
        // CONSTRAINT: Cannot exit before 13:45 and cannot exit after 14:10 (with margin for exceptions)
        if ($dow === 5) {
            // Friday: Continuous shift (jornada continua), exit between 13:45-14:10 (margin allowed)
            $start_min = time_to_minutes($suggested_start);
            $end_min = $start_min + ($suggested_hours * 60); // Calculate based on hours
            
            // Enforce maximum exit time: 14:10 (normal max 14:00, but margin allowed for exceptions)
            $max_exit_min = time_to_minutes('14:10');
            if ($end_min > $max_exit_min) {
                $end_min = $max_exit_min;
            }
            
            // Handle day overflow
            if ($end_min >= 1440) {
                $end_min -= 1440;
            }
            
            // At this point, $suggested_hours is already adjusted to meet 13:45 constraint
            // and we've capped it at 14:00
            
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
                
                // If exit > 16:00, enforce lunch break constraints:
                // - Lunch > 60 min, cannot start before 13:45, need >60 min work after
                $calculated_exit = intval($end_min / 60);
                if ($calculated_exit > 16) {
                    // Recalculate with lunch break starting at 13:45 (earliest)
                    $lunch_start_min = time_to_minutes('13:45');
                    $min_lunch = 61;  // > 60 minutes
                    $work_before_lunch = max(0, $lunch_start_min - $start_min);
                    $total_work_needed = $suggested_hours * 60;  // in minutes
                    
                    if ($total_work_needed > $work_before_lunch) {
                        // Has work after lunch
                        $work_after_lunch = $total_work_needed - $work_before_lunch;
                        if ($work_after_lunch >= 61) {
                            // Can fit >60 min work after lunch
                            $lunch_duration = max($min_lunch, intval($avg_lunch));
                            $lunch_end_min = $lunch_start_min + $lunch_duration;
                            $end_min = $lunch_end_min + $work_after_lunch;
                        } else {
                            // Can't fit enough work after lunch - keep current lunch timing
                            $end_min = $start_min + ($suggested_hours * 60) + intval($avg_lunch);
                        }
                    } else {
                        // No work before 13:45 is possible, adjust
                        $end_min = $lunch_start_min + $min_lunch + 61;  // Min work after
                    }
                }
            } else {
                // Jornada continua: no lunch break initially
                $end_min = $start_min + ($suggested_hours * 60);
                
                // If exit > 16:00, must have lunch break (switch to split shift)
                $calculated_exit = intval($end_min / 60);
                if ($calculated_exit > 16) {
                    // Force lunch break: lunch at 13:45, > 60 min, > 60 min work after
                    $lunch_start_min = time_to_minutes('13:45');
                    $min_lunch = 61;  // > 60 minutes
                    $min_work_after = 61;  // > 60 minutes after lunch
                    $lunch_duration = max($min_lunch, 60);
                    $lunch_end_min = $lunch_start_min + $lunch_duration;
                    
                    $work_before_lunch = max(0, $lunch_start_min - $start_min);
                    $remaining_work = ($suggested_hours * 60) - $work_before_lunch;
                    
                    if ($remaining_work < $min_work_after) {
                        // Can't fit required work after lunch with current hours
                        $remaining_work = $min_work_after;
                    }
                    
                    $end_min = $lunch_end_min + $remaining_work;
                }
            }
            
            // Enforce maximum exit time: 18:10 (normal max 18:00, but margin allowed for exceptions)
            $max_exit_min_weekday = time_to_minutes('18:10');
            if ($end_min > $max_exit_min_weekday) {
                $end_min = $max_exit_min_weekday;
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
        
        // Add Friday-specific note (always continuous, minimum exit 13:45, maximum 14:10 with margin)
        if ($dow === 5) {
            $reasoning .= ' | Viernes: Jornada continua, salida 13:45-14:10 (sin pausa comida, restricción operativa)';
        } else if ($is_split_shift) {
            // Note about split shift for Mon-Thu
            $reasoning .= ' | Jornada partida';
        }
        
        // Determine shift type and meal break info for this specific day
        $shift_type = ($dow === 5) ? 'continua' : ($is_split_shift ? 'partida' : 'continua');
        $has_lunch_break = ($shift_type === 'partida');
        $lunch_duration = $has_lunch_break ? ($year_config['lunch_minutes'] ?? 60) : 0;
        
        // Calculate lunch break times if applicable
        $lunch_start = null;
        $lunch_end = null;
        if ($has_lunch_break) {
            $lunch_start = '13:45';  // Standard lunch start time
            $lunch_end_min = time_to_minutes($lunch_start) + $lunch_duration;
            if ($lunch_end_min >= 1440) {
                $lunch_end_min -= 1440;
            }
            $lunch_end = sprintf('%02d:%02d', intval($lunch_end_min / 60), $lunch_end_min % 60);
        }
        
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
            'lunch_start' => $lunch_start,
            'lunch_end' => $lunch_end,
            'lunch_note' => $has_lunch_break 
                ? sprintf('Pausa comida: %d min (%s-%s)', $lunch_duration, $lunch_start, $lunch_end)
                : 'Sin pausa comida'
        ];
    }
    
    return $suggestions;
}

try {
    // Get year configuration
    $year_config = get_year_config($current_year, $user_id);
    
    // Load holidays/absences for current week
    $holidays_this_week = [];
    try {
        // First query ALL holidays (not just those in date range) so we can reconstruct annual holidays
        $holQuery = 'SELECT date, type, label, annual FROM holidays 
                     WHERE (user_id = ? OR user_id IS NULL)';
        $holStmt = $pdo->prepare($holQuery);
        $holStmt->execute([$user_id]);
        $holidays_raw = $holStmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($holidays_raw as $hol) {
            $hDate = $hol['date'];
            // For annual holidays, reconstruct for current year
            if (!empty($hol['annual'])) {
                $hMonth = intval(substr($hDate, 5, 2));
                $hDay = intval(substr($hDate, 8, 2));
                $hDate = sprintf('%04d-%02d-%02d', $current_year, $hMonth, $hDay);
            }
            // Only include if within current week
            if ($hDate >= $current_week_start && $hDate <= $current_week_end) {
                $holidays_this_week[$hDate] = $hol;
            }
        }
    } catch (Exception $e) {
        // Holidays table might not exist, continue without it
    }
    
    // Calculate target weekly hours from config, ADJUSTED FOR HOLIDAYS
    // IMPORTANT: Friday has SPLIT SHIFT (jornada partida)
    // 08:00-14:00 = 6 hours, but 1 hour minimum lunch = 5 hours WORKED
    $friday_config_hours = $year_config['work_hours']['winter']['friday'] ?? 6.0;
    // If Friday is split shift with 1-hour lunch: actual work = 5 hours
    // We'll account for this by using (friday_hours - 1) for calculation
    $friday_worked_hours = max(5.0, $friday_config_hours - 1.0);
    
    // Count working days in the week, excluding holidays
    $base_target_hours = (
        ($year_config['work_hours']['winter']['mon_thu'] ?? 8.0) * 4 + 
        $friday_worked_hours
    );
    
    // Subtract hours for holidays that fall on working days (Mon-Fri)
    $base_target_weekly_hours = $base_target_hours;
    foreach ($holidays_this_week as $hDate => $holiday) {
        $dow = (int)date('N', strtotime($hDate));
        // Only count if it's a working day (Mon-Fri)
        if ($dow >= 1 && $dow <= 5) {
            if ($dow === 5) {
                // Friday holiday
                $base_target_weekly_hours -= $friday_worked_hours;
            } else {
                // Mon-Thu holiday
                $base_target_weekly_hours -= ($year_config['work_hours']['winter']['mon_thu'] ?? 8.0);
            }
        }
    }
    
    $target_weekly_hours = $base_target_weekly_hours;
    
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
        
        if ($entry_calc['worked_minutes_for_display'] !== null) {
            $hours = $entry_calc['worked_minutes_for_display'] / 60;
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
        $check_date = date('Y-m-d', strtotime($current_week_start . " +" . ($i - 1) . " days"));
        
        // Skip if it's a holiday
        if (isset($holidays_this_week[$check_date])) {
            continue; // Skip holidays - they're not working days
        }
        
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
    
    // Calculate early start adjustment for response
    $adjusted_target_for_response = $remaining_hours;
    if ($force_start_time && count($remaining_days) > 0) {
        $force_start_min = time_to_minutes($force_start_time);
        $normal_start_min = time_to_minutes('08:00');
        
        if ($force_start_min < $normal_start_min) {
            $early_minutes = $normal_start_min - $force_start_min;
            $total_saved_minutes = $early_minutes * 0.5 * count($remaining_days);
            if ($total_saved_minutes > 10) {
                // Show adjusted target in response
                $adjusted_target_for_response = round($remaining_hours - ($total_saved_minutes / 60), 2);
            }
        }
    }
    
    // Prepare early start info if applicable
    $early_start_message = null;
    if ($force_start_time && count($remaining_days) > 0) {
        $force_start_min = time_to_minutes($force_start_time);
        $normal_start_min = time_to_minutes('08:00');
        
        if ($force_start_min < $normal_start_min) {
            $early_minutes = $normal_start_min - $force_start_min;
            $total_saved_minutes = $early_minutes * 0.5 * count($remaining_days);
            if ($total_saved_minutes > 10) {
                $early_start_message = sprintf('Entrada temprana a %s: Ahorra ~%.0f min en jornada', $force_start_time, $total_saved_minutes);
            }
        }
    }
    
    // MEJORA 1: Calcular alertas de límites
    $alerts = calculate_limit_alerts($pdo, $user_id, $today, $today_dow, $remaining_hours, $year_config, $is_split_shift);
    
    // MEJORA 2: Predicción de finalización
    $week_projection = predict_week_completion($pdo, $user_id, $current_week_start, $today, $remaining_hours, $current_year, $year_config);
    
    // MEJORA 3: Análisis de consistencia
    $consistency = analyze_consistency($pdo, $user_id);
    
    // MEJORA 4: Recomendaciones adaptativas
    $adaptive_recs = calculate_adaptive_recommendations($worked_hours_this_week, $target_weekly_hours, $remaining_hours, count($remaining_days));
    
    // MEJORA 5: Tendencias y patrones históricos
    $trends = calculate_trends($pdo, $user_id);
    
    echo json_encode([
        'success' => true,
        'worked_this_week' => round($worked_hours_this_week, 2),
        'target_weekly_hours' => round($target_weekly_hours, 2),
        'remaining_hours' => round($remaining_hours, 2),
        'remaining_hours_with_early_start' => $adjusted_target_for_response,
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
            'forced_start_time' => $force_start_time ? $force_start_time : null,
            'early_start_adjustment' => $early_start_message
        ],
        // MEJORA 1: Alertas
        'alerts' => $alerts,
        // MEJORA 2: Proyección de finalización
        'week_projection' => $week_projection,
        // MEJORA 3: Análisis de consistencia
        'consistency' => $consistency,
        // MEJORA 4: Recomendaciones adaptativas
        'adaptive_recommendations' => $adaptive_recs,
        // MEJORA 5: Tendencias
        'trends' => $trends,
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
