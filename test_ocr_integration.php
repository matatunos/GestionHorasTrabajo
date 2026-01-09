<?php
/**
 * Integration test: OCR extraction validation
 * This simulates what OCRProcessor extracts from a mobile screenshot
 */

// Simulated OCR text that Tesseract would extract from your mobile screenshot
$simulatedOCRText = <<<'TEXT'
11:36

Martes 30 dic 2025

Jornada 6:30

7:32
ASTURIAS HUNOSA PLANTA 1

10:26
ASTURIAS HUNOSA PLANTA 1

00:34

11:00
ASTURIAS HUNOSA PLANTA 1

14:16
ASTURIAS HUNOSA PLANTA 1
TEXT;

// Extract times using regex (same logic as OCRProcessor)
$times = [];
if (preg_match_all('/(\d{1,2}):(\d{2})/', $simulatedOCRText, $matches)) {
    foreach ($matches[0] as $time) {
        $times[] = $time;
    }
}

echo "=== OCR INTEGRATION TEST ===\n\n";
echo "Simulated OCR extracted text (first 500 chars):\n";
echo str_repeat("-", 60) . "\n";
echo substr($simulatedOCRText, 0, 500);
echo "\n" . str_repeat("-", 60) . "\n\n";

echo "Raw times extracted: " . implode(", ", $times) . "\n";
echo "Total times found: " . count($times) . "\n\n";

// Now apply the OCRProcessor logic:
// 1. Remove "Jornada" lines
// 2. Skip first time (it's the screenshot timestamp - 11:36)
// 3. Filter times with hour < 4 (durations, errors)
// 4. Use remaining times

// Filter out Jornada lines, first time, and invalid hours
$filteredTimes = [];
$skipFirstTime = true;
$foundTimes = 0;

$lines = explode("\n", $simulatedOCRText);
foreach ($lines as $line) {
    // Skip Jornada lines
    if (stripos($line, 'jornada') !== false) {
        continue;
    }
    
    if (preg_match_all('/(\d{1,2}):(\d{2})/', $line, $matches)) {
        foreach ($matches[0] as $time) {
            // Skip first time (screenshot timestamp)
            if ($skipFirstTime && $foundTimes === 0) {
                $skipFirstTime = false;
                echo "Skipping first time (screenshot timestamp): {$time}\n";
                continue;
            }
            
            // Parse and validate time
            list($hour, $minute) = explode(':', $time);
            $hour = (int)$hour;
            $minute = (int)$minute;
            
            // Filter out invalid times (hours 0-3 are likely durations)
            if ($hour >= 4 && $hour < 24 && $minute < 60) {
                echo "Valid time: {$time} (hour={$hour}, minute={$minute})\n";
                $filteredTimes[] = $time;
                $foundTimes++;
            } else {
                echo "SKIPPED invalid time: {$time} (hour={$hour}, minute={$minute})\n";
            }
        }
    }
}

echo "\nFiltered times (for OCR processing): " . implode(", ", $filteredTimes) . "\n";
echo "Count: " . count($filteredTimes) . "\n\n";

// Now test the date extraction
$spanishMonths = [
    'ene' => '01', 'feb' => '02', 'mar' => '03', 'abr' => '04',
    'may' => '05', 'jun' => '06', 'jul' => '07', 'ago' => '08',
    'sep' => '09', 'oct' => '10', 'nov' => '11', 'dic' => '12'
];

$extractedDate = null;
if (preg_match('/(\d{1,2})\s+(' . implode('|', array_keys($spanishMonths)) . ')\s+(\d{4})/i', $simulatedOCRText, $dateMatches)) {
    echo "Date found (Spanish format): " . $dateMatches[0] . "\n";
    
    $day = str_pad($dateMatches[1], 2, '0', STR_PAD_LEFT);
    $month = $spanishMonths[strtolower($dateMatches[2])];
    $year = $dateMatches[3];
    
    $extractedDate = "{$year}-{$month}-{$day}";
    echo "Converted to ISO format: {$extractedDate}\n\n";
}

// Final test: Map filtered times to slots
echo "=== TIME MAPPING TEST ===\n\n";
echo "mapTimesToSlots(" . implode(", ", $filteredTimes) . ")\n\n";

// Copied mapTimesToSlots function
function mapTimesToSlots($times) {
    $horas_slots = array_fill(0, 6, '');
    $timeCount = count($times);
    
    if ($timeCount === 0) {
        return $horas_slots;
    }
    
    if ($timeCount === 1) {
        $horas_slots[0] = $times[0];
    } elseif ($timeCount === 2) {
        $horas_slots[0] = $times[0];
        $horas_slots[5] = $times[1];
    } elseif ($timeCount === 3) {
        $horas_slots[0] = $times[0];
        $horas_slots[1] = $times[1];
        $horas_slots[5] = $times[2];
    } elseif ($timeCount === 4) {
        $horas_slots[0] = $times[0];
        $horas_slots[1] = $times[1];
        $horas_slots[2] = $times[2];
        $horas_slots[5] = $times[3];
    } elseif ($timeCount === 5) {
        $horas_slots[0] = $times[0];
        $horas_slots[1] = $times[1];
        $horas_slots[2] = $times[2];
        $horas_slots[3] = $times[3];
        $horas_slots[5] = $times[4];
    } else {
        for ($i = 0; $i < 6 && $i < $timeCount; $i++) {
            $horas_slots[$i] = $times[$i];
        }
    }
    
    return $horas_slots;
}

$slots = mapTimesToSlots($filteredTimes);
$labels = ['Entrada', 'Salida café', 'Entrada café', 'Salida comida', 'Entrada comida', 'Salida'];

echo "Result:\n";
for ($i = 0; $i < 6; $i++) {
    if (!empty($slots[$i])) {
        echo "  [$i] {$labels[$i]}: {$slots[$i]}\n";
    }
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "✓ INTEGRATION TEST COMPLETED\n";
echo "\nFinal record would be:\n";
echo "  Fecha: {$extractedDate}\n";
echo "  Entrada: {$slots[0]}\n";
echo "  Salida café: {$slots[1]}\n";
echo "  Entrada café: {$slots[2]}\n";
echo "  Salida comida: {$slots[3]}\n";
echo "  Entrada comida: {$slots[4]}\n";
echo "  Salida: {$slots[5]}\n";
?>