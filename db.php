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
    // IMPORTANT: Set these in .env file, never commit real credentials
    $host = getenv('DB_HOST') ?: 'localhost';
    $name = getenv('DB_NAME') ?: 'gestion_horas';
    $user = getenv('DB_USER') ?: 'app_user';
    $pass = getenv('DB_PASS') ?: '';  // Must be set in .env
    $charset = getenv('DB_CHARSET') ?: 'utf8mb4';

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
