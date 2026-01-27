<?php
/**
 * Script para descargar la extensión de Firefox como ZIP
 * Genera un archivo comprimido con todos los archivos necesarios
 * Preconfigurado con URL y token de autenticación
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib.php';
require_login(); // Solo usuarios autenticados pueden descargar

$user = current_user();

// Determinar protocolo; permitir http en entornos locales/offline
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$appUrl = "$protocol://$host";
if ($protocol === 'http') {
    error_log('Aviso: Descarga de extensión sobre HTTP en host: ' . $host);
}

// Crear token único para esta instalación
$tokenInfo = create_extension_token($user['id'], 'Firefox Extension - ' . date('Y-m-d H:i'));
if (!$tokenInfo) {
    http_response_code(500);
    die('Error al generar token de seguridad');
}

// Crear un ZIP en memoria
$zipFile = tempnam(sys_get_temp_dir(), 'gestionhoras_firefox_');
$zip = new ZipArchive();

if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    die('Error al crear el ZIP');
}

// Carpeta origen
$sourceDir = __DIR__ . '/firefox-extension';

// Función recursiva para agregar archivos al ZIP
function addFilesToZip($zip, $dir, $zipPath = '') {
    if (!is_dir($dir)) {
        return;
    }
    
    $files = scandir($dir);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        
        $filePath = $dir . '/' . $file;
        $zipPath = $zipPath . '/' . $file;
        
        if (is_dir($filePath)) {
            addFilesToZip($zip, $filePath, $zipPath);
        } else {
            $zip->addFile($filePath, ltrim($zipPath, '/'));
        }
    }
}

// Agregar todos los archivos de la extensión
addFilesToZip($zip, $sourceDir);

// Cerrar ZIP
$zip->close();

// Descargar
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="gestionhoras-firefox-extension.zip"');
header('Content-Length: ' . filesize($zipFile));
header('Cache-Control: no-cache, must-revalidate');

readfile($zipFile);
unlink($zipFile);
exit;
