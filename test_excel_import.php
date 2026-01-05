<?php
require_once 'vendor/autoload.php';
require_once 'db.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$file = 'HORARIO_2024.xlsx';
$spreadsheet = IOFactory::load($file);

$allSheetNames = $spreadsheet->getSheetNames();
echo "Hojas encontradas: " . implode(', ', $allSheetNames) . PHP_EOL;

// Filtrar hojas
$sheetsToProcess = [];
foreach ($allSheetNames as $name) {
  if (preg_match('/guardia|personal|^\+|nota|totales/i', $name)) {
    continue;
  }
  if (preg_match('/\b(20\d{2})\b/', $name)) {
    $sheetsToProcess[] = $name;
  }
}

echo "Procesando hojas: " . implode(', ', $sheetsToProcess) . PHP_EOL . PHP_EOL;

foreach ($sheetsToProcess as $sheetName) {
  echo "Procesando hoja: $sheetName" . PHP_EOL;
  $sheet = $spreadsheet->getSheetByName($sheetName);
  $rows = $sheet->toArray(null, true, true, true);
  
  $dataStartRow = 13;
  foreach ($rows as $rowIndex => $row) {
    $rowStr = implode('|', array_map('strtolower', $row));
    if (strpos($rowStr, 'hora entra') !== false) {
      $dataStartRow = $rowIndex + 1;
      break;
    }
  }
  echo "  Fila de inicio: $dataStartRow" . PHP_EOL;
  
  $count = 0;
  foreach ($rows as $rowIndex => $row) {
    if ($rowIndex < $dataStartRow) continue;
    if ($count >= 5) break;
    
    $day = trim($row['B'] ?? '');
    if (!$day || strlen($day) < 2) continue;
    
    $horas = [];
    foreach (['D', 'E', 'F', 'I', 'J', 'L'] as $col) {
      $time = trim($row[$col] ?? '');
      if ($time && preg_match('/\d{1,2}[:\.]\d{2}/', $time)) {
        $horas[] = $time;
      }
    }
    
    if (!empty($horas)) {
      echo "  Row $rowIndex: $day -> " . implode(', ', $horas) . PHP_EOL;
      $count++;
    }
  }
  echo "  Total encontrados: $count" . PHP_EOL;
}
?>
