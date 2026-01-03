<?php
/**
 * Test OCRProcessor with a real image file
 * This tests the Tesseract OCR engine directly
 */

require_once __DIR__ . '/ocr_processor.php';

echo "=== REAL OCR TEST ===\n\n";

$testImagePath = '/tmp/test_ocr_image.png';

if (!file_exists($testImagePath)) {
    echo "❌ Test image not found: {$testImagePath}\n";
    exit(1);
}

echo "Testing OCR on: {$testImagePath}\n";
echo "File size: " . filesize($testImagePath) . " bytes\n\n";

$ocr = new OCRProcessor();
$result = $ocr->processImage($testImagePath);

if (!$result['success']) {
    echo "❌ OCR processing failed\n";
    if (isset($result['error'])) {
        echo "Error: {$result['error']}\n";
    }
    exit(1);
}

echo "✓ OCR processing successful!\n\n";

echo "Raw OCR text:\n";
echo str_repeat("-", 60) . "\n";
echo substr($result['raw_text'], 0, 500);
echo "\n" . str_repeat("-", 60) . "\n\n";

echo "Extracted records:\n";
if (empty($result['records'])) {
    echo "  (no records extracted)\n";
} else {
    foreach ($result['records'] as $idx => $record) {
        echo "\nRecord " . ($idx + 1) . ":\n";
        echo "  Date: {$record['fechaISO']}\n";
        echo "  Times: " . implode(", ", $record['horas']) . "\n";
        
        // Map times to slots
        function mapTimesToSlots($times) {
            $horas_slots = array_fill(0, 6, '');
            $timeCount = count($times);
            
            if ($timeCount === 0) return $horas_slots;
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
        
        $slots = mapTimesToSlots($record['horas']);
        $labels = ['Entrada', 'Salida café', 'Entrada café', 'Salida comida', 'Entrada comida', 'Salida'];
        
        echo "  Slots:\n";
        for ($i = 0; $i < 6; $i++) {
            if (!empty($slots[$i])) {
                echo "    [$i] {$labels[$i]}: {$slots[$i]}\n";
            }
        }
    }
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "✓ TEST COMPLETED\n";
?>
