<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'db.php';
require_once 'vendor/autoload.php';
require_once 'import.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$pdo = get_pdo();
$file = 'uploads/DEMO_IMPORT_2024.xlsx';

echo "=== Test Manual de Import ===\n\n";
echo "Archivo: $file\n";

try {
    $spreadsheet = IOFactory::load($file);
    $sheet = $spreadsheet->getActiveSheet();
    $sheetName = $sheet->getTitle();
    
    echo "Hoja: $sheetName\n";
    
    // Detectar año
    $sheetYear = null;
    if (preg_match('/\b(20\d{2})\b/', $sheetName, $matches)) {
        $sheetYear = (int)$matches[1];
    }
    echo "Año: $sheetYear\n\n";
    
    $highestRow = $sheet->getHighestDataRow();
    echo "Procesando $highestRow filas...\n\n";
    
    // Procesar cada fila
    for ($row = 2; $row <= $highestRow; $row++) {
        $usuario = trim($sheet->getCell('A' . $row)->getValue() ?? '');
        $fecha = $sheet->getCell('B' . $row)->getValue();
        
        if (!$usuario || !$fecha) {
            echo "Fila $row: SKIP (sin usuario o fecha)\n";
            continue;
        }
        
        // Parsear fecha
        $fechaStr = $fecha;
        if (is_object($fecha)) {
            $fechaISO = $fecha->format('Y-m-d');
        } else {
            try {
                $dt = \DateTime::createFromFormat('d-m-Y', $fechaStr);
                if (!$dt) {
                    $dt = \DateTime::createFromFormat('d/m/Y', $fechaStr);
                }
                if (!$dt) {
                    throw new Exception("No se pudo parsear fecha: $fechaStr");
                }
                $fechaISO = $dt->format('Y-m-d');
            } catch (\Exception $e) {
                echo "Fila $row: ERROR en fecha: " . $e->getMessage() . "\n";
                continue;
            }
        }
        
        // Extraer horas
        $horas = [];
        foreach (['D', 'E', 'F', 'I', 'J', 'L'] as $col) {
            $h = trim($sheet->getCell($col . $row)->getValue() ?? '');
            if ($h) {
                $horas[] = $h;
            }
        }
        
        // Mapear a slots
        $slots = mapTimesToSlots($horas);
        
        echo "Fila $row: $usuario - $fechaISO\n";
        echo "  Horas: [" . implode(", ", $horas) . "]\n";
        echo "  Slots: start=$slots[0], c_out=$slots[1], c_in=$slots[2], l_out=$slots[3], l_in=$slots[4], end=$slots[5]\n\n";
    }
    
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
?>
