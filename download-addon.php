<?php
/**
 * Script para descargar la extensión de Chrome como ZIP
 * Genera un archivo comprimido con todos los archivos necesarios
 */

require_once __DIR__ . '/auth.php';
require_login(); // Solo usuarios autenticados pueden descargar

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

// Agregar la carpeta chrome-extension al ZIP (archivos directamente en raíz)
if (is_dir($sourceDir)) {
    addFilesToZip($zip, $sourceDir); // Sin especificar zipPath para poner en raíz
} else {
    http_response_code(404);
    die('Carpeta de extensión no encontrada');
}

$zip->close();

// Enviar el ZIP al navegador
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="GestionHorasTrabajo-ChromeExtension.zip"');
header('Content-Length: ' . filesize($zipFile));

readfile($zipFile);

// Limpiar archivo temporal
unlink($zipFile);
exit;
