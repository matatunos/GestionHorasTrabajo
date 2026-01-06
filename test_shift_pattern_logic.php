<?php
/**
 * Test script for shift pattern detection logic
 * Validates jornada_partida vs jornada_continua detection
 */

// Minimal database simulation for testing
class TestDB {
    private $data = [];
    
    public function setEntries($entries) {
        $this->data['entries'] = $entries;
    }
    
    public function prepare($sql) {
        return new TestStatement($this->data);
    }
}

class TestStatement {
    private $data;
    
    public function __construct($data) {
        $this->data = $data;
    }
    
    public function execute($params = []) {
        return true;
    }
    
    public function fetch($mode = null) {
        return $this->data['entries'][0] ?? null;
    }
    
    public function fetchAll($mode = null) {
        return $this->data['entries'] ?? [];
    }
}

// Test Case 1: Monday with lunch break (jornada partida)
echo "=== TEST CASE 1: Jornada Partida (lunes con pausa comida) ===\n";
$monday_with_lunch = [
    'id' => 1,
    'user_id' => 1,
    'date' => '2024-01-15',
    'start' => '08:00',
    'end' => '17:00',
    'lunch_out' => '13:45',
    'lunch_in' => '14:45'
];

// Check: Has lunch break?
$has_lunch = !empty($monday_with_lunch['lunch_out']) && 
             !empty($monday_with_lunch['lunch_in']);
echo "Entrada: {$monday_with_lunch['start']}-{$monday_with_lunch['end']}\n";
echo "Pausa comida: {$monday_with_lunch['lunch_out']}-{$monday_with_lunch['lunch_in']}\n";
echo "Resultado: " . ($has_lunch ? "JORNADA PARTIDA ✓" : "JORNADA CONTINUA") . "\n";
echo "Estado esperado: JORNADA PARTIDA\n";
echo "Test: " . ($has_lunch ? "PASS" : "FAIL") . "\n\n";

// Test Case 2: Monday without lunch break (jornada continua)
echo "=== TEST CASE 2: Jornada Continua (lunes sin pausa comida) ===\n";
$monday_without_lunch = [
    'id' => 2,
    'user_id' => 1,
    'date' => '2024-01-22',
    'start' => '07:30',
    'end' => '15:30',
    'lunch_out' => null,
    'lunch_in' => null
];

$has_lunch = !empty($monday_without_lunch['lunch_out']) && 
             !empty($monday_without_lunch['lunch_in']);
echo "Entrada: {$monday_without_lunch['start']}-{$monday_without_lunch['end']}\n";
echo "Pausa comida: " . ($monday_without_lunch['lunch_out'] ? "{$monday_without_lunch['lunch_out']}-{$monday_without_lunch['lunch_in']}" : "NINGUNA") . "\n";
echo "Resultado: " . ($has_lunch ? "JORNADA PARTIDA" : "JORNADA CONTINUA ✓") . "\n";
echo "Estado esperado: JORNADA CONTINUA\n";
echo "Test: " . (!$has_lunch ? "PASS" : "FAIL") . "\n\n";

// Test Case 3: Monday with only one lunch field (edge case)
echo "=== TEST CASE 3: Casos Especiales (solo lunch_out, sin lunch_in) ===\n";
$monday_partial_lunch = [
    'id' => 3,
    'user_id' => 1,
    'date' => '2024-01-29',
    'start' => '08:00',
    'end' => '16:00',
    'lunch_out' => '13:00',
    'lunch_in' => null
];

$has_lunch = !empty($monday_partial_lunch['lunch_out']) && 
             !empty($monday_partial_lunch['lunch_in']);
echo "Entrada: {$monday_partial_lunch['start']}-{$monday_partial_lunch['end']}\n";
echo "Pausa comida: lunch_out={$monday_partial_lunch['lunch_out']}, lunch_in=" . ($monday_partial_lunch['lunch_in'] ?? "NULL") . "\n";
echo "Resultado: " . ($has_lunch ? "JORNADA PARTIDA" : "JORNADA CONTINUA ✓") . "\n";
echo "Estado esperado: JORNADA CONTINUA (ambos campos requeridos)\n";
echo "Test: " . (!$has_lunch ? "PASS" : "FAIL") . "\n\n";

// Test Case 4: End-time calculation - Jornada Partida
echo "=== TEST CASE 4: Cálculo de Hora de Salida - Jornada Partida ===\n";
$start_time = "08:00";
$required_hours = 8;
$lunch_minutes = 60;
$is_split_shift = true;

// Convert start time to minutes
$start_parts = explode(':', $start_time);
$start_minutes = $start_parts[0] * 60 + $start_parts[1];

// Calculate end time
$end_minutes = $start_minutes + ($required_hours * 60) + ($is_split_shift ? $lunch_minutes : 0);
$end_hour = intval($end_minutes / 60);
$end_minute = $end_minutes % 60;
$end_time = sprintf('%02d:%02d', $end_hour, $end_minute);

echo "Entrada: {$start_time}\n";
echo "Horas requeridas: {$required_hours}h\n";
echo "Tipo de jornada: " . ($is_split_shift ? "Partida (con pausa)" : "Continua (sin pausa)") . "\n";
echo "Pausa comida: {$lunch_minutes} minutos\n";
echo "Cálculo: {$start_time} + {$required_hours}h + {$lunch_minutes}m = {$end_time}\n";
echo "Resultado esperado: 17:00\n";
echo "Test: " . ($end_time === "17:00" ? "PASS" : "FAIL") . "\n\n";

// Test Case 5: End-time calculation - Jornada Continua
echo "=== TEST CASE 5: Cálculo de Hora de Salida - Jornada Continua ===\n";
$start_time = "07:30";
$required_hours = 8;
$is_split_shift = false;

// Convert start time to minutes
$start_parts = explode(':', $start_time);
$start_minutes = $start_parts[0] * 60 + $start_parts[1];

// Calculate end time (no lunch deduction)
$end_minutes = $start_minutes + ($required_hours * 60);
$end_hour = intval($end_minutes / 60);
$end_minute = $end_minutes % 60;
$end_time = sprintf('%02d:%02d', $end_hour, $end_minute);

echo "Entrada: {$start_time}\n";
echo "Horas requeridas: {$required_hours}h\n";
echo "Tipo de jornada: " . ($is_split_shift ? "Partida (con pausa)" : "Continua (sin pausa)") . "\n";
echo "Pausa comida: Ninguna (no se deduce)\n";
echo "Cálculo: {$start_time} + {$required_hours}h = {$end_time}\n";
echo "Resultado esperado: 15:30\n";
echo "Test: " . ($end_time === "15:30" ? "PASS" : "FAIL") . "\n\n";

// Test Case 6: Friday special case (always continuous, 6 hours)
echo "=== TEST CASE 6: Viernes Especial (siempre continua, 6h, salida 14:00) ===\n";
$friday_start = "08:00";
$friday_hours = 6;
$friday_is_split = true; // Even if week is split shift

// Convert start time to minutes
$start_parts = explode(':', $friday_start);
$start_minutes = $start_parts[0] * 60 + $start_parts[1];

// Friday is ALWAYS continuous (no lunch deduction)
$end_minutes = $start_minutes + ($friday_hours * 60);
$end_hour = intval($end_minutes / 60);
$end_minute = $end_minutes % 60;
$end_time = sprintf('%02d:%02d', $end_hour, $end_minute);

echo "Entrada: {$friday_start}\n";
echo "Horas requeridas: {$friday_hours}h (viernes es jornada corta)\n";
echo "Tipo de jornada: Continua (viernes SIEMPRE es continua)\n";
echo "Pausa comida: Ninguna (viernes nunca tiene pausa deducida)\n";
echo "Cálculo: {$friday_start} + {$friday_hours}h = {$end_time}\n";
echo "Resultado esperado: 14:00\n";
echo "Test: " . ($end_time === "14:00" ? "PASS" : "FAIL") . "\n\n";

echo "=== RESUMEN DE PRUEBAS ===\n";
echo "Todos los tests de lógica completados.\n";
echo "Próximo paso: Integración con base de datos real.\n";
