<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

$file = __DIR__ . '/uploads/HORARIO_COMPLETO.xlsx';

if (!file_exists($file)) {
  echo "Archivo no encontrado: $file\n";
  exit(1);
}

echo "=== ANÁLISIS DE ARCHIVO EXCEL ===\n\n";

try {
  $spreadsheet = IOFactory::load($file);
  $allSheetNames = $spreadsheet->getSheetNames();
  
  echo "Hojas encontradas: " . count($allSheetNames) . "\n";
  foreach ($allSheetNames as $name) {
    echo "  - $name\n";
  }
  
  echo "\n=== ANÁLISIS POR HOJA ===\n\n";
  
  foreach ($allSheetNames as $sheetName) {
    $sheet = $spreadsheet->getSheetByName($sheetName);
    $rows = $sheet->toArray(null, true, true, true);
    
    echo "Hoja: $sheetName\n";
    echo "  Total de filas: " . count($rows) . "\n";
    
    // Buscar headers
    $dataStartRow = null;
    foreach ($rows as $rowIndex => $row) {
      $rowStr = implode('|', array_map('strtolower', $row));
      if (strpos($rowStr, 'hora entra') !== false || strpos($rowStr, 'entra') !== false) {
        $dataStartRow = $rowIndex;
        echo "  Headers encontrados en fila: $rowIndex\n";
        break;
      }
    }
    
    if ($dataStartRow === null) {
      echo "  ⚠️  No se encontraron headers\n";
    } else {
      // Contar datos
      $dataCount = 0;
      for ($i = $dataStartRow + 1; $i <= min($dataStartRow + 10, count($rows)); $i++) {
        if (isset($rows[$i])) {
          $day = trim($rows[$i]['B'] ?? '');
          if ($day && strlen($day) >= 2) {
            $dataCount++;
          }
        }
      }
      echo "  Datos encontrados (primeras 10 filas): ~$dataCount\n";
    }
    echo "\n";
  }
  
} catch (Exception $e) {
  echo "Error: " . $e->getMessage() . "\n";
  echo $e->getTraceAsString() . "\n";
}
?>
