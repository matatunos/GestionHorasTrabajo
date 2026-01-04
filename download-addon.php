<?php
/**
 * Script para descargar la extensión de Chrome como ZIP
 * Genera un archivo comprimido con todos los archivos necesarios
 * Preconfigurado con URL y token de autenticación
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib.php';
require_login(); // Solo usuarios autenticados pueden descargar

$user = get_current_user();

// Validar que sea HTTPS en producción
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
if ($protocol === 'http' && $_SERVER['HTTP_HOST'] !== 'localhost' && $_SERVER['HTTP_HOST'] !== '127.0.0.1') {
    http_response_code(403);
    die('⚠️ Las descargas de la extensión solo son permitidas por HTTPS por seguridad. Usa: https://' . $_SERVER['HTTP_HOST']);
}

$host = $_SERVER['HTTP_HOST'];
$appUrl = "$protocol://$host";

// Crear token único para esta instalación
$tokenInfo = create_extension_token($user['id'], 'Chrome Extension - ' . date('Y-m-d H:i'));
if (!$tokenInfo) {
    http_response_code(500);
    die('Error al generar token de seguridad');
}

// Crear un ZIP en memoria
$zipFile = tempnam(sys_get_temp_dir(), 'gestionhoras_');
$zip = new ZipArchive();

if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    die('Error al crear el ZIP');
}

// Carpeta origen
$sourceDir = __DIR__ . '/chrome-extension';

// Función recursiva para agregar archivos al ZIP
function addFilesToZip($zip, $dir, $zipPath = '') {
    if (!is_dir($dir)) {
        return;
    }
    
    $files = scandir($dir);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        
        $filePath = $dir . '/' . $file;
        $zipPath_full = $zipPath ? $zipPath . '/' . $file : $file;
        
        if (is_dir($filePath)) {
            $zip->addEmptyDir($zipPath_full);
            addFilesToZip($zip, $filePath, $zipPath_full);
        } else {
            $zip->addFile($filePath, $zipPath_full);
        }
    }
}

// Agregar la carpeta chrome-extension al ZIP
if (is_dir($sourceDir)) {
    addFilesToZip($zip, $sourceDir);
} else {
    http_response_code(404);
    die('Carpeta de extensión no encontrada');
}

// Crear archivo de configuración dinámico
$configContent = <<<'JS'
/**
 * Configuración preconfigurada de GestionHorasTrabajo Extension
 * Generada automáticamente al descargar el addon
 * 
 * ⚠️ IMPORTANTE: Este token es personal y único para este usuario
 * No lo compartas con otros usuarios
 * Expira en 7 días. Si necesitas más extensiones, descarga nuevas desde la aplicación
 */

const DEFAULT_APP_URL = 'APP_URL_PLACEHOLDER';
const EXTENSION_TOKEN = 'TOKEN_PLACEHOLDER';  // Token de autenticación

JS;

// Reemplazar placeholders
$configContent = str_replace('APP_URL_PLACEHOLDER', $appUrl, $configContent);
$configContent = str_replace('TOKEN_PLACEHOLDER', $tokenInfo['token'], $configContent);

// Agregar config.js
$zip->addFromString('config.js', $configContent);

// Cerrar ZIP
$zip->close();

// Registrar descarga en logs
error_log("Extension descargada por usuario: " . $user['username'] . " (ID: " . $user['id'] . ") - Token: " . substr($tokenInfo['token'], 0, 8) . "...");

// Enviar ZIP al navegador
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="GestionHorasTrabajo-ChromeExtension.zip"');
header('Content-Length: ' . filesize($zipFile));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

readfile($zipFile);

// Limpiar archivo temporal
@unlink($zipFile);
exit;

