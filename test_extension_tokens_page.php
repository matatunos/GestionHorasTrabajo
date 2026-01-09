<?php
// Simular acceso a extension-tokens.php

// Capturar errores y excepciones
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Redirigir salida a un buffer
ob_start();

// Simular acceso HTTP autenticado
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['username'] = 'admin';

// Simular ambiente HTTP
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['PHP_SELF'] = '/extension-tokens.php';
$_SERVER['HTTP_HOST'] = 'calendar.favala.es';

echo "=== Simulando acceso a extension-tokens.php ===\n\n";

try {
    // Capturar el contenido de extension-tokens.php
    ob_clean();
    ob_start();
    
    // Incluir el archivo
    include __DIR__ . '/extension-tokens.php';
    
    $output = ob_get_clean();
    
    if (strlen($output) > 0) {
        echo "✅ Página generada exitosamente\n";
        echo "Tamaño: " . strlen($output) . " bytes\n";
        echo "\nPrimer contenido (primeras 500 caracteres):\n";
        echo substr($output, 0, 500) . "...\n";
    } else {
        echo "⚠️  La página no generó contenido\n";
    }
    
} catch (Throwable $e) {
    echo "❌ ERROR: " . get_class($e) . "\n";
    echo "Mensaje: " . $e->getMessage() . "\n";
    echo "Archivo: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "\nStacktrace:\n";
    echo $e->getTraceAsString() . "\n";
}

ob_end_flush();
