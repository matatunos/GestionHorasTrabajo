<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
error_log('db.php: INICIO');

// Cargar autoloader de Composer (vendor/ vive fuera del webroot, en la raíz de la app)
$__autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($__autoload)) require_once $__autoload;

// Autoloader PSR-4 para las clases de dominio App\ (src/ fuera del webroot).
// Independiente de Composer para no requerir `dump-autoload` en cada despliegue.
spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
    $rel = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = dirname(__DIR__) . '/src/' . $rel . '.php';
    if (is_file($file)) require $file;
});

// Load .env file if exists (el .env vive fuera del webroot, en la raíz de la app)
$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if (!getenv($key)) {
                putenv("$key=$value");
            }
        }
    }
}

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
        // Fuente única del esquema: garantiza que las tablas existan (una vez por petición).
        if (class_exists('\\App\\Schema')) {
            try { \App\Schema::ensure($pdo); } catch (\Throwable $e) { error_log('Schema ensure: '.$e->getMessage()); }
        }
        return $pdo;
    } catch (PDOException $e) {
        error_log("Database connection failed: " . $e->getMessage()); // Log failure
        return null;
    }
}
