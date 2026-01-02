<?php
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
        // configured break durations in minutes
        'coffee_minutes' => 15, // nominal coffee time (counts as work)
        'lunch_minutes' => 30,  // nominal lunch time (not counted as work)
        // database defaults (can be overridden by env vars)
        'db' => [
            'host' => 'localhost',
            'name' => 'gestion_horas',
            'user' => getenv('DB_USER') ?: 'app_user',
            'pass' => getenv('DB_PASS') ?: 'app_pass',
            'charset' => 'utf8mb4',
        ],
    ];

    // Try to read configuration from DB (single JSON blob stored under 'site_config').
    // Avoid breaking when DB is not reachable: fall back to defaults.
    try {
        if (function_exists('get_pdo')) {
            $pdo = get_pdo();
            if ($pdo) {
                $pdo->exec("CREATE TABLE IF NOT EXISTS app_settings (
                    name VARCHAR(191) PRIMARY KEY,
                    value TEXT NOT NULL,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

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
 * Get configuration for a specific year. Falls back to global config and
 * applies DB overrides from `year_configs` table when present.
 */
function get_year_config(int $year, int $user_id = null){
    $conf = get_config();
    // try reading from DB if available
    try {
        $pdo = null;
        if (function_exists('get_pdo')) {
            $pdo = get_pdo();
        }
        if ($pdo) {
            // ensure table exists
            $pdo->exec("CREATE TABLE IF NOT EXISTS year_configs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                year INT NOT NULL,
                user_id INT DEFAULT NULL,
                mon_thu DOUBLE DEFAULT NULL,
                friday DOUBLE DEFAULT NULL,
                -- summer overrides
                summer_mon_thu DOUBLE DEFAULT NULL,
                summer_friday DOUBLE DEFAULT NULL,
                coffee_minutes INT DEFAULT NULL,
                lunch_minutes INT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY user_year (user_id, year)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // Read global (user_id IS NULL) first, then overlay user-specific if present.
            $row = false;
            $globalRow = false;
            $stmt = $pdo->prepare('SELECT * FROM year_configs WHERE year = ? AND user_id IS NULL LIMIT 1');
            $stmt->execute([$year]);
            $globalRow = $stmt->fetch();
            if ($user_id !== null) {
                $stmt = $pdo->prepare('SELECT * FROM year_configs WHERE year = ? AND user_id = ? LIMIT 1');
                $stmt->execute([$year, $user_id]);
                $row = $stmt->fetch();
            }
            // Merge: start from globalRow if exists, then overlay user-specific fields
            $merged = $globalRow ?: false;
            if ($row) {
                // overlay fields from user-specific onto merged
                if (!$merged) $merged = $row; else {
                    foreach ($row as $k => $v) { $merged[$k] = $v; }
                }
            }
            if ($merged) {
                // merge numeric overrides for winter
                if ($merged['mon_thu'] !== null) $conf['work_hours']['winter']['mon_thu'] = floatval($merged['mon_thu']);
                if ($merged['friday'] !== null) $conf['work_hours']['winter']['friday'] = floatval($merged['friday']);
                // merge numeric overrides for summer (if provided)
                if (array_key_exists('summer_mon_thu', $merged) && $merged['summer_mon_thu'] !== null) {
                    $conf['work_hours']['summer']['mon_thu'] = floatval($merged['summer_mon_thu']);
                }
                if (array_key_exists('summer_friday', $merged) && $merged['summer_friday'] !== null) {
                    $conf['work_hours']['summer']['friday'] = floatval($merged['summer_friday']);
                }
                if ($merged['coffee_minutes'] !== null) $conf['coffee_minutes'] = intval($merged['coffee_minutes']);
                if ($merged['lunch_minutes'] !== null) $conf['lunch_minutes'] = intval($merged['lunch_minutes']);
            }
        }
    } catch (Throwable $e) {
        // ignore DB errors and return defaults
    }

    return $conf;
}

// For quick view in browser
if (php_sapi_name() !== 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])){
    header('Content-Type: text/plain; charset=utf-8');
    print_r(get_config());
}
