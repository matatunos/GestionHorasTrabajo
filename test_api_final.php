<?php
$_REQUEST = ['user_id' => 1, 'date' => date('Y-m-d')];
ob_start();
include 'schedule_suggestions.php';
$output = ob_get_clean();
$data = json_decode($output, true);

if (!isset($data['success'])) {
    echo "❌ Respuesta no válida\n";
    echo "Output: " . substr($output, 0, 200) . "\n";
    exit(1);
}

echo "✅ Response JSON válido\n";

// Verificar los 5 nuevos campos
$required_fields = ['alerts', 'week_projection', 'consistency', 'adaptive_recommendations', 'trends'];
$found_fields = [];

foreach ($required_fields as $field) {
    if (isset($data[$field])) {
        $found_fields[] = $field;
        echo "✅ $field presente\n";
    } else {
        echo "❌ $field FALTA\n";
    }
}

echo "\n";
echo "5 mejoras encontradas: " . count($found_fields) . "/5\n";

if (count($found_fields) === 5) {
    echo "✅ TODAS LAS 5 MEJORAS ESTÁN INTEGRADAS EN LA API\n";
}
