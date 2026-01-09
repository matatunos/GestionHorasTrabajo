<?php
/**
 * Test final de las 5 mejoras implementadas
 * Valida que schedule_suggestions.php retorna todos los nuevos campos
 */

// Configuración de prueba
$_REQUEST = [
    'user_id' => 1,
    'date' => date('Y-m-d')
];

// Capturar output
ob_start();
include 'schedule_suggestions.php';
$json_response = ob_get_clean();

// Decodificar respuesta
$response = json_decode($json_response, true);

if (!$response) {
    echo "❌ ERROR: No se pudo decodificar la respuesta JSON\n";
    echo "Raw output: " . substr($json_response, 0, 500) . "\n";
    exit(1);
}

echo "\n=== VALIDACIÓN DE 5 MEJORAS ===\n\n";

// Validar cada mejora
$improvements = [
    'alerts' => 'Alertas de límites cercanos',
    'week_projection' => 'Predicción de finalización semanal',
    'consistency' => 'Análisis de consistencia',
    'adaptive_recommendations' => 'Recomendaciones adaptativas',
    'trends' => 'Historial y tendencias'
];

$all_passed = true;

foreach ($improvements as $field => $description) {
    if (isset($response[$field])) {
        echo "✅ $field\n";
        echo "   Descripción: $description\n";
        
        if (is_array($response[$field]) && !empty($response[$field])) {
            echo "   Datos presentes: " . count($response[$field]) . " items\n";
        } elseif (is_array($response[$field])) {
            echo "   Datos: Array vacío (posible - depende de datos disponibles)\n";
        } else {
            echo "   Datos: " . json_encode($response[$field], JSON_PRETTY_PRINT) . "\n";
        }
        echo "\n";
    } else {
        echo "❌ $field - FALTA EN RESPUESTA\n";
        $all_passed = false;
    }
}

// Resumen
echo "=== RESUMEN ===\n";
if ($all_passed) {
    echo "✅ Todas las 5 mejoras implementadas correctamente\n";
    echo "✅ La respuesta JSON contiene todos los campos requeridos\n";
} else {
    echo "❌ Faltan algunos campos en la respuesta\n";
}

// Mostrar estructura completa
echo "\n=== ESTRUCTURA COMPLETA DE RESPUESTA ===\n";
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
