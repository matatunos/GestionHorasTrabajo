<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

// Simular usuario
$_SESSION = ['user_id' => 1, 'username' => 'admin'];

$file = __DIR__ . '/uploads/HORARIO_COMPLETO.xlsx';
$pdo = get_pdo();
$user = ['id' => 1];

echo "=== IMPORTAR EXCEL ===\n\n";

if (!file_exists($file)) {
  echo "Archivo no encontrado\n";
  exit(1);
}

function mapTimesToSlots($times) {
  $horas_slots = array_fill(0, 6, '');
  $timeCount = count($times);
  
  if ($timeCount === 0) {
    return $horas_slots;
  } elseif ($timeCount === 1) {
    $horas_slots[0] = $times[0];
  } elseif ($timeCount === 2) {
    $horas_slots[0] = $times[0];
    $horas_slots[5] = $times[1];
  } elseif ($timeCount >= 3) {
    $horas_slots[0] = $times[0];
    $horas_slots[1] = $times[1] ?? '';
    $horas_slots[2] = $times[2] ?? '';
    $horas_slots[3] = $times[3] ?? '';
    $horas_slots[4] = $times[4] ?? '';
    $horas_slots[5] = $times[count($times)-1];
  }
  
  return $horas_slots;
}

try {
  $spreadsheet = IOFactory::load($file);
  $excelData = [];
  
  $allSheetNames = $spreadsheet->getSheetNames();
  $sheetsToProcess = [];
  
  foreach ($allSheetNames as $name) {
    if (preg_match('/\b(20\d{2})\b/', $name)) {
      $sheetsToProcess[] = $name;
    }
  }
  
  echo "Hojas a procesar: " . implode(', ', $sheetsToProcess) . "\n\n";
  
  foreach ($sheetsToProcess as $sheetName) {
    echo "Procesando hoja: $sheetName\n";
    
    $sheet = $spreadsheet->getSheetByName($sheetName);
    if (!$sheet) {
      echo "  ✗ No se pudo cargar hoja\n";
      continue;
    }
    
    $rows = $sheet->toArray(null, true, true, true);
    
    // Extraer año
    $sheetYear = null;
    if (preg_match('/\b(20\d{2})\b/', $sheetName, $m)) {
      $sheetYear = intval($m[1]);
    }
    if (!$sheetYear) {
      $sheetYear = intval(date('Y'));
    }
    echo "  Año detectado: $sheetYear\n";
    
    // Buscar headers
    $dataStartRow = 13;
    foreach ($rows as $rowIndex => $row) {
      $rowStr = implode('|', array_map('strtolower', $row));
      if (strpos($rowStr, 'hora entra') !== false || strpos($rowStr, 'entra') !== false) {
        $dataStartRow = $rowIndex + 1;
        echo "  Headers encontrados en fila: $rowIndex\n";
        break;
      }
    }
    
    $sheetDataCount = 0;
    
    foreach ($rows as $rowIndex => $row) {
      if ($rowIndex < $dataStartRow) continue;
      if ($rowIndex > 500) break;
      
      $day = trim($row['B'] ?? '');
      if (!$day || strlen($day) < 2) continue;
      
      echo "  - Procesando fila $rowIndex: día=$day\n";
      
      // Convertir fecha
      $fechaISO = null;
      $parsedYear = $sheetYear;
      
      // Intentar número Excel
      if (is_numeric($day) && $day > 0) {
        try {
          $excelDate = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($day);
          $fechaISO = $excelDate->format('Y-m-d');
          $parsedYear = intval($excelDate->format('Y'));
          echo "    ✓ Excel date: $fechaISO\n";
        } catch (Exception $e) {
          echo "    ! Excel date failed: " . $e->getMessage() . "\n";
        }
      }
      
      // Intentar texto
      if (!$fechaISO) {
        $dateFormats = ['d-M-Y', 'd/m/Y', 'Y-m-d', 'd-m-Y', 'm/d/Y', 'j/n/Y', 'j-M', 'd-m', 'd/m'];
        
        foreach ($dateFormats as $format) {
          $dateTime = \DateTime::createFromFormat($format, $day);
          if ($dateTime) {
            if (strpos($format, 'Y') === false) {
              $dateTime->setDate($sheetYear, $dateTime->format('m'), $dateTime->format('d'));
            }
            $fechaISO = $dateTime->format('Y-m-d');
            $parsedYear = intval($dateTime->format('Y'));
            echo "    ✓ Text date ($format): $fechaISO\n";
            break;
          }
        }
      }
      
      if (!$fechaISO) {
        echo "    ✗ No se pudo parsear fecha\n";
        continue;
      }
      
      // Validar año
      if ($parsedYear < 2000 || $parsedYear > (intval(date('Y')) + 1)) {
        $parts = explode('-', $fechaISO);
        if (count($parts) === 3) {
          $fechaISO = $sheetYear . '-' . str_pad($parts[1], 2, '0', STR_PAD_LEFT) . '-' . str_pad($parts[2], 2, '0', STR_PAD_LEFT);
          $parsedYear = $sheetYear;
          echo "    ! Año corregido a: $fechaISO\n";
        }
      }
      
      // Extraer horas
      $horas = [];
      $timeColumns = ['D', 'E', 'F', 'I', 'J', 'L'];
      foreach ($timeColumns as $col) {
        $time = trim($row[$col] ?? '');
        if ($time && preg_match('/\d{1,2}[:\.]\d{2}/', $time)) {
          $horas[] = $time;
        }
      }
      
      if (empty($horas)) {
        echo "    ✗ No hay horas\n";
        continue;
      }
      
      echo "    ✓ Horas encontradas: " . implode(', ', $horas) . "\n";
      
      $excelData[] = [
        'fechaISO' => $fechaISO,
        'horas' => $horas,
        'dia' => $day
      ];
      $sheetDataCount++;
    }
    
    echo "  Total registros en hoja: $sheetDataCount\n\n";
  }
  
  echo "=== RESULTADO ===\n";
  echo "Total registros parseados: " . count($excelData) . "\n";
  
  if (empty($excelData)) {
    echo "✗ No se encontraron datos\n";
  } else {
    echo "✓ Datos listos para importar\n";
    
    // Intentar insertar
    $stmt = $pdo->prepare('INSERT INTO entries (user_id, date, start, end, coffee_out, coffee_in, lunch_out, lunch_in, note) 
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) 
                          ON DUPLICATE KEY UPDATE start=?, end=?, coffee_out=?, coffee_in=?, lunch_out=?, lunch_in=?');
    
    $inserted = 0;
    $updated = 0;
    
    foreach ($excelData as $record) {
      $slots = mapTimesToSlots($record['horas']);
      $result = $stmt->execute([
        $user['id'], $record['fechaISO'],
        $slots[0], $slots[5], $slots[1], $slots[2], $slots[3], $slots[4], 'Importado',
        $slots[0], $slots[5], $slots[1], $slots[2], $slots[3], $slots[4]
      ]);
      
      if ($result) {
        $inserted++;
      }
    }
    
    echo "Registros insertados/actualizados: $inserted\n";
  }
  
} catch (Exception $e) {
  echo "ERROR: " . $e->getMessage() . "\n";
  echo $e->getTraceAsString() . "\n";
}
?>
