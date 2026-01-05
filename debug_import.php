<?php
// Debug de importación - muestra errores exactos

require_once 'db.php';
require_once 'vendor/autoload.php';
require_once 'import.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$pdo = get_pdo();

// Verificar archivos en uploads/
echo "Archivos en uploads/:\n";
$files = glob('uploads/*.{xlsx,html,json}', GLOB_BRACE);
foreach ($files as $file) {
    echo "  - " . basename($file) . " (" . filesize($file) . " bytes)\n";
}

if (empty($files)) {
    echo "No hay archivos para importar.\n";
    exit;
}

$file = $files[0];
echo "\nIntentando importar: " . basename($file) . "\n\n";

// Simular lo que hace import.php al procesar archivo
$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

if ($ext === 'xlsx') {
    
    try {
        $spreadsheet = IOFactory::load($file);
        $sheets = $spreadsheet->getSheetNames();
        
        echo "Hojas encontradas: " . implode(", ", $sheets) . "\n\n";
        
        // Procesar primera hoja como test
        $sheet = $spreadsheet->getSheetByName($sheets[0]);
        $sheetYear = null;
        
        // Detectar año del nombre de la hoja
        if (preg_match('/\b(20\d{2})\b/', $sheets[0], $matches)) {
            $sheetYear = (int)$matches[1];
            echo "Año detectado del nombre de hoja: $sheetYear\n";
        }
        
        $highestRow = $sheet->getHighestDataRow();
        echo "Filas con datos: $highestRow\n\n";
        
        // Procesar primeras 3 filas de datos (skip header)
        for ($row = 2; $row <= min(4, $highestRow); $row++) {
            $dateStr = $sheet->getCell('B' . $row)->getValue();
            $h1 = $sheet->getCell('D' . $row)->getValue();
            $h2 = $sheet->getCell('E' . $row)->getValue();
            $h3 = $sheet->getCell('F' . $row)->getValue();
            $h4 = $sheet->getCell('I' . $row)->getValue();
            $h5 = $sheet->getCell('J' . $row)->getValue();
            $h6 = $sheet->getCell('L' . $row)->getValue();
            
            echo "Fila $row:\n";
            echo "  Fecha (B): " . json_encode($dateStr) . "\n";
            echo "  Horas: [$h1] [$h2] [$h3] [$h4] [$h5] [$h6]\n";
            
            // Si es una celda con objeto DateTime
            if (is_object($dateStr)) {
                $dateStr = $dateStr->format('Y-m-d H:i:s');
                echo "  Fecha (como DateTime): $dateStr\n";
            }
            
            // Intentar mapear horas
            $horas = array_filter(array_map(function($v) {
                return $v && trim($v) !== '' ? (string)$v : null;
            }, [$h1, $h2, $h3, $h4, $h5, $h6]));
            
            echo "  Horas array: " . json_encode(array_values($horas)) . "\n";
            
            if (!empty($horas)) {
                // Llamar a mapTimesToSlots
                $mapped = mapTimesToSlots(array_values($horas));
                echo "  Mapped: " . json_encode($mapped) . "\n";
            }
            echo "\n";
        }
        
    } catch (Exception $e) {
        echo "Error al leer Excel: " . $e->getMessage() . "\n";
        echo "Trace: " . $e->getTraceAsString() . "\n";
    }
}
?>
