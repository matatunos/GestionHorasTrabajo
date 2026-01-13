<?php
/**
 * Log Analytics Helper
 * Analiza los logs para generar estadísticas y métricas
 */

class LogAnalytics {
    
    private static $logDir = __DIR__ . '/logs';
    
    /**
     * Obtener estadísticas de login
     */
    public static function getLoginStats($days = 7) {
        $authLog = self::$logDir . '/auth.log';
        
        if (!file_exists($authLog)) {
            return [
                'total' => 0,
                'success' => 0,
                'failed' => 0,
                'success_rate' => 0,
                'top_ips' => [],
                'top_users' => [],
                'failed_reasons' => []
            ];
        }
        
        $lines = file($authLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $cutoff = time() - ($days * 24 * 60 * 60);
        
        $stats = [
            'total' => 0,
            'success' => 0,
            'failed' => 0,
            'success_rate' => 0,
            'top_ips' => [],
            'top_users' => [],
            'failed_reasons' => []
        ];
        
        $ips = [];
        $users = [];
        $reasons = [];
        
        foreach ($lines as $line) {
            $log = json_decode($line, true);
            if (!$log || !isset($log['unix_timestamp'])) continue;
            
            // Filtrar por fecha
            if ($log['unix_timestamp'] < $cutoff) continue;
            
            $action = $log['data']['action'] ?? null;
            $ip = $log['ip'] ?? 'Unknown';
            $username = $log['data']['username'] ?? 'Unknown';
            
            $stats['total']++;
            
            if ($action === 'LOGIN_SUCCESS') {
                $stats['success']++;
                $users[$username] = ($users[$username] ?? 0) + 1;
            } elseif ($action === 'LOGIN_FAILED') {
                $stats['failed']++;
                $reason = $log['data']['reason'] ?? 'unknown';
                $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
                $users[$username] = ($users[$username] ?? 0) + 1;
            }
            
            $ips[$ip] = ($ips[$ip] ?? 0) + 1;
        }
        
        // Calcular tasa de éxito
        if ($stats['total'] > 0) {
            $stats['success_rate'] = round(($stats['success'] / $stats['total']) * 100, 1);
        }
        
        // Top 5 IPs
        arsort($ips);
        $stats['top_ips'] = array_slice($ips, 0, 5);
        
        // Top 5 usuarios
        arsort($users);
        $stats['top_users'] = array_slice($users, 0, 5);
        
        // Razones de fallos
        arsort($reasons);
        $stats['failed_reasons'] = $reasons;
        
        return $stats;
    }
    
    /**
     * Obtener últimas actividades
     */
    public static function getRecentActivity($limit = 10) {
        $authLog = self::$logDir . '/auth.log';
        
        if (!file_exists($authLog)) {
            return [];
        }
        
        $lines = array_reverse(file($authLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        $activity = [];
        
        foreach ($lines as $line) {
            if (count($activity) >= $limit) break;
            
            $log = json_decode($line, true);
            if (!$log) continue;
            
            $activity[] = [
                'timestamp' => $log['timestamp'] ?? '',
                'action' => $log['data']['action'] ?? '',
                'username' => $log['data']['username'] ?? 'Unknown',
                'ip' => $log['ip'] ?? 'Unknown',
                'reason' => $log['data']['reason'] ?? '',
                'user_id' => $log['data']['user_id'] ?? null
            ];
        }
        
        return $activity;
    }
    
    /**
     * Obtener estadísticas de errores
     */
    public static function getErrorStats($days = 7) {
        $errorLog = self::$logDir . '/error.log';
        
        if (!file_exists($errorLog)) {
            return [
                'total' => 0,
                'errors' => []
            ];
        }
        
        $lines = file($errorLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $cutoff = time() - ($days * 24 * 60 * 60);
        
        $stats = [
            'total' => 0,
            'errors' => []
        ];
        
        foreach ($lines as $line) {
            // Parse log line: [timestamp] [level] message
            if (preg_match('/\[([^\]]+)\]/', $line, $matches)) {
                $timestamp = strtotime($matches[1]);
                if ($timestamp < $cutoff) continue;
                
                $stats['total']++;
            }
        }
        
        return $stats;
    }
    
    /**
     * Obtener estadísticas API
     */
    public static function getApiStats($days = 7) {
        $apiLog = self::$logDir . '/api.log';
        
        if (!file_exists($apiLog)) {
            return [
                'total_requests' => 0,
                'endpoints' => []
            ];
        }
        
        $lines = file($apiLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $cutoff = time() - ($days * 24 * 60 * 60);
        
        $stats = [
            'total_requests' => 0,
            'endpoints' => []
        ];
        
        foreach ($lines as $line) {
            $log = json_decode($line, true);
            if (!$log || !isset($log['unix_timestamp'])) continue;
            
            if ($log['unix_timestamp'] < $cutoff) continue;
            
            $stats['total_requests']++;
        }
        
        return $stats;
    }
    
    /**
     * Obtener estadísticas de seguridad (intentos sospechosos)
     */
    public static function getSecurityStats($days = 7) {
        $authLog = self::$logDir . '/auth.log';
        
        if (!file_exists($authLog)) {
            return [
                'failed_attempts' => 0,
                'suspicious_ips' => [],
                'alerts' => []
            ];
        }
        
        $lines = file($authLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $cutoff = time() - ($days * 24 * 60 * 60);
        
        $stats = [
            'failed_attempts' => 0,
            'suspicious_ips' => [],
            'alerts' => []
        ];
        
        $ipFailures = [];
        
        foreach ($lines as $line) {
            $log = json_decode($line, true);
            if (!$log || !isset($log['unix_timestamp'])) continue;
            
            if ($log['unix_timestamp'] < $cutoff) continue;
            
            if ($log['data']['action'] === 'LOGIN_FAILED') {
                $stats['failed_attempts']++;
                $ip = $log['ip'] ?? 'Unknown';
                $ipFailures[$ip] = ($ipFailures[$ip] ?? 0) + 1;
            }
        }
        
        // IPs con más de 5 intentos fallidos = sospechosas
        foreach ($ipFailures as $ip => $count) {
            if ($count > 5) {
                $stats['suspicious_ips'][$ip] = $count;
            }
        }
        
        // Generar alertas
        if (count($stats['suspicious_ips']) > 0) {
            $stats['alerts'][] = 'Detectadas ' . count($stats['suspicious_ips']) . ' IPs con múltiples intentos fallidos';
        }
        
        if ($stats['failed_attempts'] > 50) {
            $stats['alerts'][] = 'Alto número de intentos fallidos (' . $stats['failed_attempts'] . ')';
        }
        
        return $stats;
    }
    
    /**
     * Obtener gráfico de actividad por hora
     */
    public static function getActivityByHour($days = 1) {
        $authLog = self::$logDir . '/auth.log';
        
        if (!file_exists($authLog)) {
            return [];
        }
        
        $lines = file($authLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $cutoff = time() - ($days * 24 * 60 * 60);
        
        $hours = array_fill(0, 24, 0);
        
        foreach ($lines as $line) {
            $log = json_decode($line, true);
            if (!$log || !isset($log['unix_timestamp'])) continue;
            
            if ($log['unix_timestamp'] < $cutoff) continue;
            
            if (isset($log['timestamp'])) {
                $hour = intval(date('H', strtotime($log['timestamp'])));
                $hours[$hour]++;
            }
        }
        
        return $hours;
    }
}
