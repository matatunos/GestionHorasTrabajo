<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('log_errors', 1);
error_reporting(E_ALL);
error_log('db.php: INICIO');
require_once __DIR__ . '/config.php';

function get_pdo(){
    static $pdo = null;
    if ($pdo) return $pdo;

    // Prefer environment variables for DB credentials
    $host = getenv('DB_HOST') ?: null;
    $name = getenv('DB_NAME') ?: null;
    $user = getenv('DB_USER') ?: null;
    $pass = getenv('DB_PASS') ?: null;
    $charset = getenv('DB_CHARSET') ?: null;

    // final fallbacks (defaults used when no env vars present)
    $host = $host ?: 'localhost';
    $name = $name ?: 'gestion_horas';
    $user = $user ?: 'gestion_user';
    $pass = $pass ?: 'gestion_secure_2024';
    $charset = $charset ?: 'utf8mb4';

    $dsn = "mysql:host={$host};dbname={$name};charset={$charset}";
    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        error_log("Database connection successful."); // Log success
        return $pdo;
    } catch (PDOException $e) {
        error_log("Database connection failed: " . $e->getMessage()); // Log failure
        return null;
    }
}
