<?php
require_once __DIR__ . '/config.php';

/**
 * Parse a boolean-like checkbox value from POST and return 1 or 0.
 */
function post_flag(string $name): int {
    return !empty($_POST[$name]) ? 1 : 0;
}

/**
 * Render a checkbox input with label. Returns HTML string.
 */
function render_checkbox(string $name, $checked = null, string $label = 'Repite anualmente', array $attrs = []): string {
    // $checked can be: null (auto-detect), bool, int, or string
    if ($checked === null) {
        // prefer POST value if available
        $checked = !empty($_POST[$name]);
    } else {
        $checked = (bool)$checked;
    }
    $atts = '';
    foreach ($attrs as $k => $v) {
        $atts .= ' ' . htmlspecialchars($k) . '="' . htmlspecialchars($v) . '"';
    }
    $html = '<label class="form-check form-label">';
    $html .= '<input type="checkbox" name="' . htmlspecialchars($name) . '"' . ($checked ? ' checked' : '') . $atts . '>';
    $html .= '<span>' . htmlspecialchars($label) . '</span>';
    $html .= '</label>';
    return $html;
}

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
        // try to use current user if available
        $user_id = null;
        if (function_exists('current_user')) { $cu = current_user(); if ($cu) $user_id = $cu['id']; }
        $config = get_year_config(intval($year), $user_id);
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

    $coffee_taken = ($coffee_out !== null && $coffee_in !== null);
    $coffee_duration = $coffee_taken ? ($coffee_in - $coffee_out) : null;

    $lunch_taken = ($lunch_out !== null && $lunch_in !== null);
    $lunch_duration = $lunch_taken ? ($lunch_in - $lunch_out) : null;

    $worked_minutes = null;
    if ($start !== null && $end !== null) {
        // coffee counts as work. Lunch does NOT count as work.
        // Subtract actual lunch duration when recorded; otherwise assume no lunch.
        $worked_minutes = ($end - $start) - intval($lunch_duration ?? 0);
        
        // Subtract incident hours if any (only for 'hours' type incidents)
        try {
            $incidents_lost = get_incidents_minutes($entry['user_id'] ?? 0, $entry['date']);
            if ($incidents_lost > 0) {
                $worked_minutes = max(0, $worked_minutes - $incidents_lost);
            }
        } catch (Throwable $e) {
            // If incidents table doesn't exist or other error, just skip
        }
    }

    $day_balance = ($worked_minutes === null) ? null : ($worked_minutes - $expected_minutes);

    // balances compared to configured minutes (positive means longer than configured)
    $coffee_balance = $coffee_taken ? ($coffee_duration - intval($config['coffee_minutes'])) : null;
    $lunch_balance = $lunch_taken ? ($lunch_duration - intval($config['lunch_minutes'])) : null;

    // For weekend days with no times recorded, show blank balances to avoid confusing negatives
    $hasAnyTime = ($start !== null || $coffee_out !== null || $coffee_in !== null || $lunch_out !== null || $lunch_in !== null || $end !== null);
    if ($weekday >= 6 && !$hasAnyTime) {
        $worked_minutes = null;
        $day_balance = null;
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
        'coffee_taken' => $coffee_taken,
        'coffee_duration' => $coffee_duration,
        'coffee_balance' => $coffee_balance,
        'lunch_taken' => $lunch_taken,
        'lunch_duration' => $lunch_duration,
        'lunch_balance' => $lunch_balance,
        'worked_hours_formatted' => $blankWeekendDisplay ? '' : minutes_to_hours_formatted($worked_minutes),
        'day_balance_formatted' => $blankWeekendDisplay ? '' : minutes_to_hours_formatted($day_balance),
        'coffee_balance_formatted' => minutes_to_hours_formatted($coffee_balance),
        'lunch_balance_formatted' => minutes_to_hours_formatted($lunch_balance),
    ];
}

/**
 * Validate time entry for logical consistency
 * Returns array with 'valid' bool and 'errors' array of error messages
 */
function validate_time_entry(array $entry): array {
    $errors = [];
    
    $start = $entry['start'] ?? null;
    $coffee_out = $entry['coffee_out'] ?? null;
    $coffee_in = $entry['coffee_in'] ?? null;
    $lunch_out = $entry['lunch_out'] ?? null;
    $lunch_in = $entry['lunch_in'] ?? null;
    $end = $entry['end'] ?? null;
    
    // Convert to minutes for comparison
    $s = time_to_minutes($start);
    $co = time_to_minutes($coffee_out);
    $ci = time_to_minutes($coffee_in);
    $lo = time_to_minutes($lunch_out);
    $li = time_to_minutes($lunch_in);
    $e = time_to_minutes($end);
    
    // Basic chronological checks
    if ($s !== null && $e !== null && $s >= $e) {
        $errors[] = 'Hora entrada debe ser anterior a hora salida';
    }
    
    if ($co !== null && $ci !== null && $co >= $ci) {
        $errors[] = 'Salida café debe ser anterior a entrada café';
    }
    
    if ($lo !== null && $li !== null && $lo >= $li) {
        $errors[] = 'Salida comida debe ser anterior a entrada comida';
    }
    
    // Check if breaks are too long (max 2 hours reasonable)
    if ($co !== null && $ci !== null) {
        $coffeeDuration = $ci - $co;
        if ($coffeeDuration > 120) {
            $errors[] = 'Pausa café demasiado larga (máx 2 horas)';
        }
    }
    
    if ($lo !== null && $li !== null) {
        $lunchDuration = $li - $lo;
        if ($lunchDuration > 120) {
            $errors[] = 'Pausa comida demasiada larga (máx 2 horas)';
        }
    }
    
    // Check logical flow: coffee breaks should be within work hours
    if ($s !== null && $co !== null && $s >= $co) {
        $errors[] = 'Salida café debe ser después de entrada';
    }
    
    if ($e !== null && $ci !== null && $e <= $ci) {
        $errors[] = 'Entrada café debe ser antes de salida';
    }
    
    if ($s !== null && $lo !== null && $s >= $lo) {
        $errors[] = 'Salida comida debe ser después de entrada';
    }
    
    if ($e !== null && $li !== null && $e <= $li) {
        $errors[] = 'Entrada comida debe ser antes de salida';
    }
    
    return [
        'valid' => count($errors) === 0,
        'errors' => $errors,
    ];
}

/**
 * Get visual time range display with start and end times plus total hours
 * Format: "07:32→14:16 (6h 44m)" or "— " if missing
 */
function get_hours_display(?string $start, ?string $end, ?int $worked_minutes): string {
    if ($start === null || $end === null || $worked_minutes === null) {
        return '—';
    }
    
    $hours_text = minutes_to_hours_formatted($worked_minutes);
    return htmlspecialchars($start) . '→' . htmlspecialchars($end) . ' (' . $hours_text . ')';
}

/**
 * Get total minutes lost due to incidents for a given date and user
 * Only counts 'hours' type incidents (full_day incidents are handled separately)
 */
function get_incidents_minutes(int $user_id, string $date, ?PDO $pdo = null): int {
    if (!$pdo) {
        try { $pdo = get_pdo(); } catch (Throwable $e) { return 0; }
    }
    
    try {
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(hours_lost), 0) as total FROM incidents WHERE user_id = ? AND date = ? AND incident_type = ?');
        $stmt->execute([$user_id, $date, 'hours']);
        $row = $stmt->fetch();
        return intval($row['total'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Check if there's a full-day incident for a given date and user
 */
function has_fullday_incident(int $user_id, string $date, ?PDO $pdo = null): bool {
    if (!$pdo) {
        try { $pdo = get_pdo(); } catch (Throwable $e) { return false; }
    }
    
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) as cnt FROM incidents WHERE user_id = ? AND date = ? AND incident_type = ?');
        $stmt->execute([$user_id, $date, 'full_day']);
        $row = $stmt->fetch();
        return intval($row['cnt'] ?? 0) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Get all incidents for a given date and user
 */
function get_incidents_for_date(int $user_id, string $date, ?PDO $pdo = null): array {
    if (!$pdo) {
        try { $pdo = get_pdo(); } catch (Throwable $e) { return []; }
    }
    
    try {
        $stmt = $pdo->prepare('SELECT id, incident_type, hours_lost, reason, created_at FROM incidents WHERE user_id = ? AND date = ? ORDER BY created_at DESC');
        $stmt->execute([$user_id, $date]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Generar token seguro para extensión
 * @return string Token aleatorio de 64 caracteres
 */
function generate_extension_token(): string {
    return bin2hex(random_bytes(32));
}

/**
 * Crear nuevo token de extensión para el usuario actual
 * @param int $user_id ID del usuario
 * @param string $name Nombre descriptivo del token
 * @param int $days_valid Días que el token es válido (default: 7)
 * @return array ['token' => '...', 'expires_at' => '...'] o null si falla
 */
function create_extension_token(int $user_id, string $name = 'Extension Token', int $days_valid = 7): ?array {
    global $pdo;
    
    try {
        $token = generate_extension_token();
        $expires_at = date('Y-m-d H:i:s', strtotime("+$days_valid days"));
        
        $stmt = $pdo->prepare(
            'INSERT INTO extension_tokens (user_id, token, name, expires_at) 
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$user_id, $token, $name, $expires_at]);
        
        return [
            'token' => $token,
            'expires_at' => $expires_at,
            'name' => $name
        ];
    } catch (Throwable $e) {
        error_log('Error creating extension token: ' . $e->getMessage());
        return null;
    }
}

/**
 * Validar token de extensión
 * @param string $token Token a validar
 * @return int|null user_id si válido, null si inválido/expirado
 */
function validate_extension_token(string $token): ?int {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare(
            'SELECT user_id FROM extension_tokens 
             WHERE token = ? 
             AND expires_at > NOW() 
             AND revoked_at IS NULL 
             LIMIT 1'
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        
        if ($row) {
            // Actualizar last_used_at
            $stmt = $pdo->prepare(
                'UPDATE extension_tokens SET last_used_at = NOW() WHERE token = ?'
            );
            $stmt->execute([$token]);
            
            return $row['user_id'];
        }
    } catch (Throwable $e) {
        error_log('Error validating extension token: ' . $e->getMessage());
    }
    
    return null;
}

/**
 * Obtener todos los tokens activos del usuario
 */
function get_user_extension_tokens(int $user_id): array {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare(
            'SELECT id, name, created_at, expires_at, last_used_at, 
                    (expires_at > NOW() AND revoked_at IS NULL) as is_active 
             FROM extension_tokens 
             WHERE user_id = ? 
             ORDER BY created_at DESC'
        );
        $stmt->execute([$user_id]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('Error getting extension tokens: ' . $e->getMessage());
        return [];
    }
}

/**
 * Revocar token de extensión
 */
function revoke_extension_token(int $token_id, int $user_id, string $reason = 'User revoked'): bool {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare(
            'UPDATE extension_tokens 
             SET revoked_at = NOW(), revoke_reason = ? 
             WHERE id = ? AND user_id = ?'
        );
        return $stmt->execute([$reason, $token_id, $user_id]);
    } catch (Throwable $e) {
        error_log('Error revoking extension token: ' . $e->getMessage());
        return false;
    }
}
