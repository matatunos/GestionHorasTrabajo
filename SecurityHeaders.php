<?php
/**
 * Security Headers Configuration
 * Archivo de configuración centralizado para headers de seguridad
 * 
 * Incluir al inicio de los archivos PHP que expongan contenido web
 */

/**
 * Aplicar headers de seguridad
 * 
 * @param array $options Opciones personalizadas
 *   - 'https_only': boolean (default: detectado automáticamente)
 *   - 'csp': string custom CSP (default: content-security-policy)
 *   - 'frame_options': string (default: 'DENY')
 */
function apply_security_headers($options = []) {
    // Detectar HTTPS
    $is_https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $https_only = $options['https_only'] ?? $is_https;
    
    // X-Content-Type-Options
    header('X-Content-Type-Options: nosniff');
    
    // X-Frame-Options (Previene clickjacking)
    $frame_option = $options['frame_options'] ?? 'DENY';
    header("X-Frame-Options: $frame_option");
    
    // X-XSS-Protection
    header('X-XSS-Protection: 1; mode=block');
    
    // Content-Security-Policy
    $csp = $options['csp'] ?? "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:;";
    header("Content-Security-Policy: $csp");
    
    // Referrer-Policy
    header('Referrer-Policy: strict-origin-when-cross-origin');
    
    // Permissions-Policy (antes Feature-Policy)
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()');
    
    // HSTS (solo si HTTPS)
    if ($https_only) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
    }
}

/**
 * Aplicar headers específicos para API
 */
function apply_api_security_headers($options = []) {
    // Headers API
    header('Content-Type: application/json; charset=utf-8');
    
    // Aplicar headers generales
    $api_csp = $options['csp'] ?? "default-src 'none'; connect-src 'self';";
    apply_security_headers(array_merge($options, [
        'frame_options' => 'DENY',
        'csp' => $api_csp
    ]));
}

/**
 * Aplicar headers para contenido HTML
 */
function apply_html_security_headers($options = []) {
    header('Content-Type: text/html; charset=utf-8');
    
    // CSP menos restrictivo para HTML/web
    $html_csp = $options['csp'] ?? "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:;";
    apply_security_headers(array_merge($options, [
        'frame_options' => 'SAMEORIGIN',
        'csp' => $html_csp
    ]));
}
