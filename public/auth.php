<?php
function log_auth_event($action, $username, $user_id = null, $reason = null) {
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) mkdir($logDir, 0777, true);
    $authLog = $logDir . '/auth.log';
    $entry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'unix_timestamp' => time(),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'data' => [
            'action' => $action,
            'username' => $username,
            'user_id' => $user_id,
            'reason' => $reason
        ]
    ];
    file_put_contents($authLog, json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);
error_reporting(E_ALL);
error_log('auth.php: INICIO');
require_once __DIR__ . '/db.php';

// --- Seguridad de sesión: cookie HttpOnly / Secure / SameSite ---
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    // Detrás de NPM la conexión externa es HTTPS (X-Forwarded-Proto)
    $__https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'secure'   => $__https,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// --- Cabeceras de seguridad para todas las páginas (sin salida previa) ---
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-XSS-Protection: 0');
}

function current_user(){
    if (empty($_SESSION['user_id'])) return null;
    $pdo = get_pdo();
    if (!$pdo) return null;
    $stmt = $pdo->prepare('SELECT id, username, is_admin FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch();
}

function require_login(){
    if (!current_user()){
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
            header('Content-Type: application/json'); http_response_code(401); echo json_encode(['ok'=>false,'login'=>true,'redirect'=>'/login.php']); exit;
        }
        header('Location: /login.php'); exit;
    }
    // Cambio de contraseña obligatorio: aplicado en TODA página autenticada (antes solo en dashboard),
    // salvo la propia página de cambio, el logout y las peticiones AJAX/API.
    if (needs_password_change()) {
        $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest';
        if (!$isAjax && !in_array($script, ['change_password.php', 'logout.php'], true)) {
            header('Location: /change_password.php'); exit;
        }
    }
}

// ===== CSRF (defensa en profundidad; SameSite=Lax ya mitiga el grueso) =====
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}
function csrf_check(): bool {
    $t = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return !empty($_SESSION['csrf_token']) && is_string($t) && $t !== '' && hash_equals($_SESSION['csrf_token'], $t);
}
/** Aborta con 419 si un POST no trae un token CSRF válido. Llamar al inicio de los handlers de formulario. */
function csrf_require(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !csrf_check()) {
        http_response_code(403); // 403 estándar (419 no lo reconoce Apache → 500)
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
            header('Content-Type: application/json'); echo json_encode(['ok' => false, 'error' => 'csrf', 'message' => 'Token de seguridad inválido']);
        } else {
            echo 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.';
        }
        exit;
    }
}

function require_admin(){
    $u = current_user();
    if (!$u) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
            header('Content-Type: application/json'); http_response_code(401); echo json_encode(['ok'=>false,'login'=>true,'redirect'=>'login.php']); exit;
        }
        header('Location: login.php'); exit;
    }
    if (!$u['is_admin']){
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
            header('Content-Type: application/json'); http_response_code(403); echo json_encode(['ok'=>false,'error'=>'forbidden']); exit;
        }
        header('HTTP/1.1 403 Forbidden'); echo 'Forbidden'; exit;
    }
}

// --- Rate limiting de login (anti fuerza bruta) ---
const LOGIN_MAX_FAILS  = 5;    // intentos fallidos permitidos por IP
const LOGIN_WINDOW_MIN = 15;   // ventana de tiempo en minutos

// (La tabla login_attempts la garantiza App\Schema::ensure() desde get_pdo().)

function login_client_ip(): string {
    $remote = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    // Detrás de NPM (192.168.1.24) la IP real del cliente viene en X-Forwarded-For.
    // Solo se confía en la cabecera cuando la petición procede del propio proxy.
    if ($remote === '192.168.1.24' && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
        if (!empty($parts[0])) return $parts[0];
    }
    return $remote;
}

/** ¿La IP ha superado el límite de intentos fallidos recientes? */
function login_is_rate_limited(?string $ip = null): bool {
    $ip = $ip ?? login_client_ip();
    $pdo = get_pdo(); if (!$pdo) return false;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) c FROM login_attempts
            WHERE ip = ? AND success = 0 AND created_at > (NOW() - INTERVAL ? MINUTE)");
        $stmt->execute([$ip, LOGIN_WINDOW_MIN]);
        return intval($stmt->fetch()['c'] ?? 0) >= LOGIN_MAX_FAILS;
    } catch (Throwable $e) {
        return false; // ante error de BD, no bloquear (evitar lockout total)
    }
}

function login_record_attempt(string $username, bool $success): void {
    $pdo = get_pdo(); if (!$pdo) return;
    $ip = login_client_ip();
    try {
        $pdo->prepare("INSERT INTO login_attempts (ip, username, success) VALUES (?,?,?)")
            ->execute([$ip, $username, $success ? 1 : 0]);
        if ($success) {
            // limpiar fallos previos de esa IP tras un acceso correcto
            $pdo->prepare("DELETE FROM login_attempts WHERE ip = ? AND success = 0")->execute([$ip]);
        }
    } catch (Throwable $e) { /* no romper el login por el registro */ }
}

function do_login($username, $password){
    $pdo = get_pdo(); if (!$pdo) return false;
    $stmt = $pdo->prepare('SELECT id, password, username FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $u = $stmt->fetch();
    if (!$u) {
        login_record_attempt($username, false);
        log_auth_event('LOGIN_FAILED', $username, null, 'user_not_found');
        return false;
    }
    if (password_verify($password, $u['password'])){
        // Regenerar el ID de sesión al autenticar (anti session fixation)
        if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
        $_SESSION['user_id'] = $u['id'];
        // Check if user 'admin' is using default password 'admin'
        if ($username === 'admin' && $password === 'admin') {
            $_SESSION['force_password_change'] = true;
        }
        login_record_attempt($username, true);
        log_auth_event('LOGIN_SUCCESS', $username, $u['id']);
        return true;
    } else {
        login_record_attempt($username, false);
        log_auth_event('LOGIN_FAILED', $username, $u['id'], 'invalid_password');
        return false;
    }
}

function do_logout() {
    $user = current_user();
    if ($user) {
        log_auth_event('LOGOUT', $user['username'], $user['id']);
    }
    session_destroy();
}

function needs_password_change(){
    return !empty($_SESSION['force_password_change']);
}

function clear_password_change_flag(){
    unset($_SESSION['force_password_change']);
}

