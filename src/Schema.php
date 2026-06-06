<?php
namespace App;

use PDO;
use Throwable;

/**
 * Fuente única del esquema de la base de datos.
 *
 * Centraliza los `CREATE TABLE IF NOT EXISTS` que antes estaban repetidos por el código
 * (app_settings aparecía en 7 sitios, year_configs en 4, etc.). Las definiciones reflejan
 * el esquema REAL de la base de datos de producción (verificado con SHOW CREATE TABLE),
 * no las versiones obsoletas que había embebidas en el código.
 *
 * `ensure()` se invoca una sola vez por petición desde get_pdo() (en db.php).
 * Cada sentencia va en su propio try/catch para que un fallo aislado no rompa el resto.
 */
final class Schema
{
    private static bool $done = false;

    /** Definiciones canónicas, en orden de dependencia (users/entries se asumen existentes). */
    private const TABLES = [
        'app_settings' => "CREATE TABLE IF NOT EXISTS app_settings (
            name VARCHAR(191) NOT NULL,
            value TEXT NOT NULL,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        'app_config' => "CREATE TABLE IF NOT EXISTS app_config (
            k VARCHAR(100) NOT NULL,
            v TEXT DEFAULT NULL,
            PRIMARY KEY (k)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        'year_configs' => "CREATE TABLE IF NOT EXISTS year_configs (
            year INT(11) NOT NULL,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            mon_thu DOUBLE DEFAULT NULL,
            friday DOUBLE DEFAULT NULL,
            summer_mon_thu DOUBLE DEFAULT NULL,
            summer_friday DOUBLE DEFAULT NULL,
            coffee_minutes INT(11) DEFAULT NULL,
            lunch_minutes INT(11) DEFAULT NULL,
            expected_daily_hours_winter DOUBLE DEFAULT NULL,
            expected_daily_hours_summer DOUBLE DEFAULT NULL,
            PRIMARY KEY (year)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        'holiday_types' => "CREATE TABLE IF NOT EXISTS holiday_types (
            id INT(11) NOT NULL AUTO_INCREMENT,
            code VARCHAR(50) NOT NULL,
            label VARCHAR(100) NOT NULL,
            color VARCHAR(7) DEFAULT '#0f172a',
            sort_order INT(11) DEFAULT 0,
            is_system TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY code (code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        'holidays' => "CREATE TABLE IF NOT EXISTS holidays (
            id INT(11) NOT NULL AUTO_INCREMENT,
            user_id INT(11) DEFAULT NULL,
            date DATE NOT NULL,
            label VARCHAR(255) DEFAULT NULL,
            type VARCHAR(20) DEFAULT 'holiday',
            annual TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_date_unique (user_id, date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        'incidents' => "CREATE TABLE IF NOT EXISTS incidents (
            id INT(11) NOT NULL AUTO_INCREMENT,
            user_id INT(11) NOT NULL,
            date DATE NOT NULL,
            incident_type ENUM('full_day','hours') NOT NULL DEFAULT 'hours',
            hours_lost INT(11) DEFAULT NULL COMMENT 'Minutes lost (only for hours type)',
            reason TEXT NOT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_date (user_id, date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        'login_attempts' => "CREATE TABLE IF NOT EXISTS login_attempts (
            id INT(11) NOT NULL AUTO_INCREMENT,
            ip VARCHAR(45) NOT NULL,
            username VARCHAR(191) DEFAULT NULL,
            success TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_ip_time (ip, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    ];

    /** Crea las tablas que falten. Idempotente y seguro de llamar en cada conexión. */
    public static function ensure(PDO $pdo): void
    {
        if (self::$done) return;
        self::$done = true;
        foreach (self::TABLES as $sql) {
            try { $pdo->exec($sql); }
            catch (Throwable $e) { error_log('Schema::ensure: ' . $e->getMessage()); }
        }
    }
}
