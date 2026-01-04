<?php
/**
 * Script para descargar la extensión de Chrome como ZIP
 * Genera un archivo comprimido con todos los archivos necesarios
 * Preconfigurado con la URL de la aplicación
 */

require_once __DIR__ . '/auth.php';
require_login(); // Solo usuarios autenticados pueden descargar

// Detectar la URL base de la aplicación
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$appUrl = "$protocol://$host";

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

// Crear archivo de configuración dinámico con la URL preconfigurada
$configContent = <<<'JS'
/**
 * Configuración preconfigurada de GestionHorasTrabajo Extension
 * Generada automáticamente al descargar el addon
 */

const DEFAULT_APP_URL = 'APP_URL_PLACEHOLDER';

// Cargar la URL guardada o usar la configurada
chrome.storage.sync.get(['appUrl'], (result) => {
  if (!result.appUrl) {
    // Si no existe configuración previa, usar la URL preconfigurada
    chrome.storage.sync.set({ appUrl: DEFAULT_APP_URL });
  }
});

JS;

// Reemplazar el placeholder con la URL real
$configContent = str_replace('APP_URL_PLACEHOLDER', $appUrl, $configContent);

// Agregar el archivo de configuración al ZIP
$zip->addFromString('config.js', $configContent);

// Modificar popup.js para cargar el config.js
if (is_file($sourceDir . '/popup.js')) {
    $popupContent = file_get_contents($sourceDir . '/popup.js');
    // Inyectar comentario indicando que se auto-configura
    $popupContent = "// Auto-configurado con: $appUrl\n// Cargar config.js para usar la URL preconfigurada\n" . $popupContent;
    $zip->addFromString('popup.js', $popupContent);
} else {
    addFilesToZip($zip, $sourceDir);
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
