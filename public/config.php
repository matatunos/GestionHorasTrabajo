<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
error_log('config.php: INICIO');
function get_config(){
    $defaults = [
        // site display name
        'site_name' => 'GestionHoras',
        // month-day for summer period
        'summer_start' => '06-15',
        'summer_end' => '09-30',
        // work hours per day (hours) for winter and summer
        'work_hours' => [
            'winter' => ['mon_thu' => 8.0, 'friday' => 6.0],
            'summer' => ['mon_thu' => 7.5, 'friday' => 6.0],
        ],
        // Expected daily hours for empresa calculations (flat rate per workday)
        'expected_daily_hours_winter' => 7.65,
        'expected_daily_hours_summer' => 7.0,
        // configured break durations in minutes
        'coffee_minutes' => 15, // nominal coffee time (counts as work)
        'lunch_minutes' => 30,  // nominal lunch time (not counted as work)
        // database defaults (can be overridden by env vars)
        // WARNING: These are FALLBACK defaults. Use .env for production credentials
        'db' => [
            'host' => getenv('DB_HOST') ?: 'localhost',
            'name' => getenv('DB_NAME') ?: 'gestion_horas',
            'user' => getenv('DB_USER') ?: 'app_user',
            'pass' => getenv('DB_PASS') ?: 'CHANGE_ME_IN_ENV',
            'charset' => 'utf8mb4',
        ],
        // Application URL used for building absolute links and CORS defaults
        'app_url' => 'http://localhost',
    ];

    // Try to read configuration from DB (single JSON blob stored under 'site_config').
    // Avoid breaking when DB is not reachable: fall back to defaults.
    try {
        if (function_exists('get_pdo')) {
            $pdo = get_pdo();
            if ($pdo) {
                // La tabla app_settings la garantiza App\Schema::ensure() desde get_pdo().
                $stmt = $pdo->prepare('SELECT value FROM app_settings WHERE name = ? LIMIT 1');
                $stmt->execute(['site_config']);
                $row = $stmt->fetch();
                if ($row && !empty($row['value'])) {
                    $json = json_decode($row['value'], true);
                    if (is_array($json)) {
                        $defaults = array_replace_recursive($defaults, $json);
                    }
                }
            }
        }
    } catch (Throwable $e) {
        // ignore DB errors and keep defaults
    }

    return $defaults;
}

/**
 * Return the configured application URL.
 * Falls back to the current request's host/protocol when running in web context.
 */
function get_app_url(): string {
    $conf = [];
    if (!empty($conf['app_url'])) return rtrim($conf['app_url'], '/');

    // Try to derive from server variables
    if (!empty($_SERVER['HTTP_HOST'])) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . $_SERVER['HTTP_HOST'];
    }

    return 'http://localhost';
}

/**
 * Get configuration for a specific year. Falls back to global config and
 * applies DB overrides from `year_configs` table when present.
 */
function get_year_config(int $year, ?int $user_id = null){
    $conf = get_config();
    try {
        $pdo = null;
        if (function_exists('get_pdo')) {
            $pdo = get_pdo();
        }
        if ($pdo) {
            // La tabla year_configs la garantiza App\Schema::ensure() desde get_pdo()
            // (con el esquema REAL: PK year, sin user_id — el CREATE inline anterior era obsoleto).
            $hasUserId = false;
            try {
                $cst = $pdo->query("SHOW COLUMNS FROM year_configs LIKE 'user_id'");
                $hasUserId = (bool)($cst && $cst->fetch());
            } catch (Throwable $e) {
                $hasUserId = false;
            }

            if ($hasUserId) {
                if ($user_id !== null) {
                    $stmt = $pdo->prepare('SELECT * FROM year_configs WHERE year = ? AND user_id = ? LIMIT 1');
                    $stmt->execute([$year, $user_id]);
                    $row = $stmt->fetch();
                } else {
                    $row = false;
                }
                if (!$row) {
                    $stmt = $pdo->prepare('SELECT * FROM year_configs WHERE year = ? AND user_id IS NULL LIMIT 1');
                    $stmt->execute([$year]);
                    $row = $stmt->fetch();
                }
            } else {
                $stmt = $pdo->prepare('SELECT * FROM year_configs WHERE year = ? LIMIT 1');
                $stmt->execute([$year]);
                $row = $stmt->fetch();
            }
            
            // Si no hay configuración para este año, buscar el último año con configuración
            if (!$row) {
                $searchYear = $year - 1;
                $maxAttempts = 10; // Buscar hasta 10 años atrás
                $attempts = 0;
                
                while (!$row && $attempts < $maxAttempts) {
                    if ($hasUserId) {
                        if ($user_id !== null) {
                            $stmt = $pdo->prepare('SELECT * FROM year_configs WHERE year = ? AND user_id = ? LIMIT 1');
                            $stmt->execute([$searchYear, $user_id]);
                            $row = $stmt->fetch();
                        }
                        if (!$row) {
                            $stmt = $pdo->prepare('SELECT * FROM year_configs WHERE year = ? AND user_id IS NULL LIMIT 1');
                            $stmt->execute([$searchYear]);
                            $row = $stmt->fetch();
                        }
                    } else {
                        $stmt = $pdo->prepare('SELECT * FROM year_configs WHERE year = ? LIMIT 1');
                        $stmt->execute([$searchYear]);
                        $row = $stmt->fetch();
                    }
                    
                    $searchYear--;
                    $attempts++;
                }
            }
            
            if ($row) {
                if ($row['mon_thu'] !== null) $conf['work_hours']['winter']['mon_thu'] = floatval($row['mon_thu']);
                if ($row['friday'] !== null) $conf['work_hours']['winter']['friday'] = floatval($row['friday']);
                if (array_key_exists('summer_mon_thu', $row) && $row['summer_mon_thu'] !== null) {
                    $conf['work_hours']['summer']['mon_thu'] = floatval($row['summer_mon_thu']);
                }
                if (array_key_exists('summer_friday', $row) && $row['summer_friday'] !== null) {
                    $conf['work_hours']['summer']['friday'] = floatval($row['summer_friday']);
                }
                if (array_key_exists('expected_daily_hours_winter', $row) && $row['expected_daily_hours_winter'] !== null) {
                    $conf['expected_daily_hours_winter'] = floatval($row['expected_daily_hours_winter']);
                }
                if (array_key_exists('expected_daily_hours_summer', $row) && $row['expected_daily_hours_summer'] !== null) {
                    $conf['expected_daily_hours_summer'] = floatval($row['expected_daily_hours_summer']);
                }
                if ($row['coffee_minutes'] !== null) $conf['coffee_minutes'] = intval($row['coffee_minutes']);
                if ($row['lunch_minutes'] !== null) $conf['lunch_minutes'] = intval($row['lunch_minutes']);
            }
        }
    } catch (Throwable $e) {
        // ignore DB errors and return defaults
    }
    $defaults = get_config();
    if (empty($conf['summer_start']) && !empty($defaults['summer_start'])) {
        $conf['summer_start'] = $defaults['summer_start'];
    }
    if (empty($conf['summer_end']) && !empty($defaults['summer_end'])) {
        $conf['summer_end'] = $defaults['summer_end'];
    }
    return $conf;
}

// NOTA DE SEGURIDAD: se eliminó el bloque de "vista rápida" que hacía print_r(get_config())
// al acceder directamente a config.php — filtraba la contraseña real de la BD por HTTP.
