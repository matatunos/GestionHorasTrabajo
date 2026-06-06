<?php
/**
 * JWT Security Helper
 * Wrapper para operaciones de JWT con mejores prácticas
 * 
 * Uso:
 * $token = JWTHelper::create($user_id, $username);
 * $payload = JWTHelper::verify($token);
 */

class JWTHelper {
    private static $algorithm = 'HS256';
    private static $expiration = 2592000; // 30 días en segundos
    
    /**
     * Obtener la clave secreta desde environment
     */
    private static function getSecretKey() {
        $secret = getenv('JWT_SECRET_KEY');
        
        // Si no está configurada, usar fallback seguro
        if (!$secret || strlen($secret) < 32) {
            // Generar una clave determinística basada en el servidor
            // NOTA: Idealmente debe venir de .env en producción
            $secret = hash('sha256', php_uname() . __FILE__);
            
            // Log si no está configurada (advertencia en desarrollo)
            if (getenv('APP_ENV') !== 'production') {
                error_log('[JWT_WARNING] JWT_SECRET_KEY no configurada, usando fallback');
            }
        }
        
        return $secret;
    }
    
    /**
     * Crear un token JWT
     * 
     * @param int $user_id ID del usuario
     * @param string $username Nombre de usuario
     * @param array $extra Datos adicionales opcionales
     * @return string Token JWT
     */
    public static function create($user_id, $username, $extra = []) {
        $secret_key = self::getSecretKey();
        
        // Header
        $header = base64_encode(json_encode([
            'typ' => 'JWT',
            'alg' => self::$algorithm
        ]));
        
        // Payload
        $payload = [
            'user_id' => $user_id,
            'username' => $username,
            'iat' => time(),
            'exp' => time() + self::$expiration
        ];
        
        // Agregar datos extra si existen
        if (!empty($extra)) {
            $payload = array_merge($payload, $extra);
        }
        
        $payload_encoded = base64_encode(json_encode($payload));
        
        // Signature
        $signature = base64_encode(
            hash_hmac('sha256', "$header.$payload_encoded", $secret_key, true)
        );
        
        return "$header.$payload_encoded.$signature";
    }
    
    /**
     * Verificar y decodificar un token JWT
     * 
     * @param string $token Token JWT a verificar
     * @return array|false Payload decodificado o false si es inválido
     */
    public static function verify($token) {
        $secret_key = self::getSecretKey();
        
        // Dividir token en partes
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            error_log('[JWT_VERIFY_FAILED] Formato de token inválido');
            return false;
        }
        
        // Decodificar y validar header
        $header = json_decode(base64_decode($parts[0]), true);
        if (empty($header) || $header['alg'] !== self::$algorithm) {
            error_log('[JWT_VERIFY_FAILED] Header inválido o algoritmo no soportado');
            return false;
        }
        
        // Verificar firma con timing-attack safe comparison
        $expected_signature = base64_encode(
            hash_hmac('sha256', "{$parts[0]}.{$parts[1]}", $secret_key, true)
        );
        
        if (!hash_equals($parts[2], $expected_signature)) {
            error_log('[JWT_VERIFY_FAILED] Firma inválida');
            return false;
        }
        
        // Decodificar payload
        $payload = json_decode(base64_decode($parts[1]), true);
        if (empty($payload)) {
            error_log('[JWT_VERIFY_FAILED] Payload no decodificable');
            return false;
        }
        
        // Validar expiración
        if (!empty($payload['exp']) && $payload['exp'] < time()) {
            error_log('[JWT_VERIFY_FAILED] Token expirado (exp: ' . $payload['exp'] . ')');
            return false;
        }
        
        // Token válido
        return $payload;
    }
    
    /**
     * Decodificar sin validar (solo para inspeccionar)
     * USO SOLO INTERNO - No usar en validaciones
     */
    public static function decode($token) {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return false;
        }
        
        return json_decode(base64_decode($parts[1]), true);
    }
}
