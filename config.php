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

    // allow runtime overrides from data/config.json
    $cfg_file = __DIR__ . '/data/config.json';
    if (file_exists($cfg_file)) {
        $json = json_decode(file_get_contents($cfg_file), true);
        if (is_array($json)) {
            // merge overrides recursively
            $defaults = array_replace_recursive($defaults, $json);
        }
    }

    return $defaults;
}

/**
 * Get configuration for a specific year. Falls back to global config and
 * applies DB overrides from `year_configs` table when present.
 */
function get_year_config(int $year){
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
                year INT PRIMARY KEY,
                mon_thu DOUBLE DEFAULT NULL,
                friday DOUBLE DEFAULT NULL,
                -- summer overrides
                summer_mon_thu DOUBLE DEFAULT NULL,
                summer_friday DOUBLE DEFAULT NULL,
                coffee_minutes INT DEFAULT NULL,
                lunch_minutes INT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $stmt = $pdo->prepare('SELECT * FROM year_configs WHERE year = ? LIMIT 1');
            $stmt->execute([$year]);
            $row = $stmt->fetch();
            if ($row) {
                // merge numeric overrides for winter
                if ($row['mon_thu'] !== null) $conf['work_hours']['winter']['mon_thu'] = floatval($row['mon_thu']);
                if ($row['friday'] !== null) $conf['work_hours']['winter']['friday'] = floatval($row['friday']);
                // merge numeric overrides for summer (if provided)
                if (array_key_exists('summer_mon_thu', $row) && $row['summer_mon_thu'] !== null) {
                    $conf['work_hours']['summer']['mon_thu'] = floatval($row['summer_mon_thu']);
                }
                if (array_key_exists('summer_friday', $row) && $row['summer_friday'] !== null) {
                    $conf['work_hours']['summer']['friday'] = floatval($row['summer_friday']);
                }
                if ($row['coffee_minutes'] !== null) $conf['coffee_minutes'] = intval($row['coffee_minutes']);
                if ($row['lunch_minutes'] !== null) $conf['lunch_minutes'] = intval($row['lunch_minutes']);
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
