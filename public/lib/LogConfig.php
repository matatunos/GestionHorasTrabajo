<?php
/**
 * Logging Configuration
 * Configuración centralizada de logs para GestionHorasTrabajo
 * 
 * Uso:
 * require_once __DIR__ . '/LogConfig.php';
 * LogConfig::init();
 * error_log('[ACTION] mensaje');
 */

class LogConfig {
    
    // Directorio de logs
    const LOG_DIR = __DIR__ . '/logs';
    
    // Archivos de log
    const LOG_FILE = self::LOG_DIR . '/app.log';
    const AUTH_LOG_FILE = self::LOG_DIR . '/auth.log';
    const ERROR_LOG_FILE = self::LOG_DIR . '/error.log';
    const API_LOG_FILE = self::LOG_DIR . '/api.log';
    
    /**
     * Inicializar configuración de logging
     */
    public static function init() {
        // Crear directorio de logs si no existe
        if (!is_dir(self::LOG_DIR)) {
            mkdir(self::LOG_DIR, 0755, true);
        }
        
        // Configurar error_log para escribir en archivo específico
        ini_set('error_log', self::ERROR_LOG_FILE);
        
        // Crear archivos si no existen
        self::ensureLogFiles();
    }
    
    /**
     * Crear archivos de log
     */
    private static function ensureLogFiles() {
        $files = [
            self::LOG_FILE,
            self::AUTH_LOG_FILE,
            self::ERROR_LOG_FILE,
            self::API_LOG_FILE
        ];
        
        foreach ($files as $file) {
            if (!file_exists($file)) {
                touch($file);
                chmod($file, 0644);
            }
        }
    }
    
    /**
     * Log de autenticación
     * Registra intentos de login, tokens, etc.
     */
    public static function authLog($message, $level = 'INFO') {
        $timestamp = date('Y-m-d H:i:s');
        $log_entry = "[$timestamp] [$level] $message\n";
        file_put_contents(self::AUTH_LOG_FILE, $log_entry, FILE_APPEND);
    }
    
    /**
     * Log de API
     * Registra requests/responses de API
     */
    public static function apiLog($message, $level = 'INFO') {
        $timestamp = date('Y-m-d H:i:s');
        $log_entry = "[$timestamp] [$level] $message\n";
        file_put_contents(self::API_LOG_FILE, $log_entry, FILE_APPEND);
    }
    
    /**
     * Log general
     * Para mensajes generales de la aplicación
     */
    public static function appLog($message, $level = 'INFO') {
        $timestamp = date('Y-m-d H:i:s');
        $log_entry = "[$timestamp] [$level] $message\n";
        file_put_contents(self::LOG_FILE, $log_entry, FILE_APPEND);
    }
    
    /**
     * Log de error
     * Usa error_log() estándar de PHP
     */
    public static function errorLog($message, $level = 'ERROR') {
        $timestamp = date('Y-m-d H:i:s');
        $log_entry = "[$timestamp] [$level] $message";
        error_log($log_entry);
    }
    
    /**
     * Log estructurado (JSON)
     * Para mejor parsing y análisis
     */
    public static function jsonLog($file_type, $data) {
        $log_file = match($file_type) {
            'auth' => self::AUTH_LOG_FILE,
            'api' => self::API_LOG_FILE,
            'error' => self::ERROR_LOG_FILE,
            default => self::LOG_FILE
        };
        
        $entry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'unix_timestamp' => time(),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'CLI',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'N/A',
            'data' => $data
        ];
        
        $json_line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        file_put_contents($log_file, $json_line, FILE_APPEND);
    }
    
    /**
     * Obtener ruta de archivo de log
     */
    public static function getLogFile($type = 'app') {
        return match($type) {
            'auth' => self::AUTH_LOG_FILE,
            'api' => self::API_LOG_FILE,
            'error' => self::ERROR_LOG_FILE,
            default => self::LOG_FILE
        };
    }
    
    /**
     * Limpiar logs antiguos (más de N días)
     */
    public static function cleanup($days = 30) {
        $cutoff = time() - ($days * 24 * 60 * 60);
        
        foreach ([self::LOG_FILE, self::AUTH_LOG_FILE, self::ERROR_LOG_FILE, self::API_LOG_FILE] as $file) {
            if (file_exists($file) && filemtime($file) < $cutoff) {
                unlink($file);
            }
        }
    }
}
