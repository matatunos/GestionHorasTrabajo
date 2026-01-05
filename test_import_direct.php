<?php
require_once 'db.php';
require_once 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$pdo = get_pdo();

// Buscar archivos Excel
$files = glob('uploads/*.xlsx');
if (empty($files)) {
    die("No hay archivos XLSX\n");
}

$file = $files[0];
echo "Procesando: " . basename($file) . "\n\n";

try {
    $spreadsheet = IOFactory::load($file);
    $sheet = $spreadsheet->getActiveSheet();
    $name = $sheet->getTitle();
    
    echo "Hoja: $name\n";
    echo "Detectando año...\n";
    
    $sheetYear = null;
    if (preg_match('/\b(20\d{2})\b/', $name, $matches)) {
        $sheetYear = (int)$matches[1];
        echo "  Año encontrado: $sheetYear\n";
    }
    
    $highestRow = $sheet->getHighestDataRow();
    echo "Filas de datos: $highestRow\n\n";
    
    // Procesar primeras 3 filas
    for ($row = 2; $row <= min(4, $highestRow); $row++) {
        echo "=== Fila $row ===\n";
        
        $dateVal = $sheet->getCell('B' . $row)->getValue();
        $h1 = $sheet->getCell('D' . $row)->getValue();
        $h2 = $sheet->getCell('E' . $row)->getValue();
        $h3 = $sheet->getCell('F' . $row)->getValue();
        $h4 = $sheet->getCell('I' . $row)->getValue();
        $h5 = $sheet->getCell('J' . $row)->getValue();
        $h6 = $sheet->getCell('L' . $row)->getValue();
        
        echo "Fecha (raw): ";
        var_dump($dateVal);
        
        echo "Horas (raw): [$h1] [$h2] [$h3] [$h4] [$h5] [$h6]\n";
        
        // Procesar fecha como lo hace import.php
        $dateStr = $dateVal;
        if (is_object($dateVal)) {
            $dateStr = $dateVal->format('Y-m-d');
            echo "Fecha (DateTime): $dateStr\n";
        } else {
            echo "Fecha (string): $dateStr\n";
        }
        
        // Procesar horas
        $horas = [];
        foreach ([$h1, $h2, $h3, $h4, $h5, $h6] as $h) {
            if ($h && trim((string)$h) !== '') {
                $horas[] = (string)$h;
            }
        }
        
        echo "Horas procesadas: [" . implode(", ", $horas) . "]\n";
        
        echo "Mapeando...\n";
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
?>
