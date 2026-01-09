<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ocr_processor.php';
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

// Constants
define('IMPORT_NOTE_TEXT', 'Importado');
define('MAX_UPLOAD_SIZE', 10 * 1024 * 1024); // 10MB

/**
 * Map raw time array to horas_slots intelligently based on count
 * Logic: uses same mapping as HTML importer
 * @param array $times Array of time strings
 * @return array horas_slots (6 elements: start, coffee_out, coffee_in, lunch_out, lunch_in, end)
 */
function mapTimesToSlots($times) {
  $horas_slots = array_fill(0, 6, '');
  $timeCount = count($times);
  
  if ($timeCount === 0) {
    return $horas_slots;
  }
  
  // REGLA UNIVERSAL: 
  // - Primera hora siempre es ENTRADA (slot 0)
  // - Última hora siempre es SALIDA (slot 5)
  // - Las demás se llenan en orden: coffee_out, coffee_in, lunch_out, lunch_in
  
  $horas_slots[0] = $times[0];  // First is always entry
  
  if ($timeCount === 1) {
    // Solo entrada
    return $horas_slots;
  }
  
  if ($timeCount === 2) {
    // Entrada y salida
    $horas_slots[5] = $times[1];
    return $horas_slots;
  }
  
  // Para 3+ horas, llenar los slots intermedios
  if ($timeCount >= 3) {
    $intermediateIndex = 1;  // Empieza en coffee_out (slot 1)
    
    // Llenar los slots intermedios (1-4) con horas medias, dejando el último para la salida
    for ($i = 1; $i < $timeCount - 1; $i++) {
      if ($intermediateIndex <= 4) {
        $horas_slots[$intermediateIndex] = $times[$i];
        $intermediateIndex++;
      }
    }
    
    // Última hora es siempre la salida (slot 5)
    $horas_slots[5] = $times[$timeCount - 1];
  }
  
  return $horas_slots;
}

$user = current_user();
require_login();
$pdo = get_pdo();

$message = '';
$messageType = '';
$ocrData = null;

// Handle XLSX upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['xlsx_file'])) {
  $file = $_FILES['xlsx_file'];
  
  if ($file['error'] === UPLOAD_ERR_OK) {
    if ($file['size'] > MAX_UPLOAD_SIZE) {
      $message = 'El archivo es demasiado grande (máximo 10MB)';
      $messageType = 'error';
    } else {
      try {
        $spreadsheet = IOFactory::load($file['tmp_name']);
        $excelData = [];
        
        // Procesar hojas que coincidan con años (2024, 2025, 2026, etc.)
        $allSheetNames = $spreadsheet->getSheetNames();
        $sheetsToProcess = [];
        
        // Buscar hojas que sean años (números o contengan años)
        foreach ($allSheetNames as $name) {
          // Saltar hojas especiales
          if (preg_match('/guardia|personal|^\\+|nota|totales/i', $name)) {
            continue;
          }
          // Incluir si es un número de 4 dígitos (año)
          if (preg_match('/\b(20\d{2})\b/', $name)) {
            $sheetsToProcess[] = $name;
          }
        }
        
        // Si no encontramos hojas de años, procesar todas excepto especiales
        if (empty($sheetsToProcess)) {
          foreach ($allSheetNames as $name) {
            if (!preg_match('/guardia|personal|^\\+|nota|totales/i', $name)) {
              $sheetsToProcess[] = $name;
            }
          }
        }
        
        foreach ($sheetsToProcess as $sheetName) {
          try {
            $sheet = $spreadsheet->getSheetByName($sheetName);
            if (!$sheet) continue;
            
            $rows = $sheet->toArray(null, true, true, true);
            
            // Extraer año del nombre de la hoja PRIMERO
            $sheetYear = null;
            if (preg_match('/\b(20\d{2})\b/', $sheetName, $m)) {
              $sheetYear = intval($m[1]);
            }
            
            // Si no encontramos año en el nombre, usar año actual como fallback
            if (!$sheetYear) {
              $sheetYear = intval(date('Y'));
            }
            
            // Buscar fila de encabezados (contiene "HORA ENTRA" o similar)
            $dataStartRow = 13; // Default
            foreach ($rows as $rowIndex => $row) {
              $rowStr = implode('|', array_map('strtolower', $row));
              if (strpos($rowStr, 'hora entra') !== false || strpos($rowStr, 'entra') !== false) {
                $dataStartRow = $rowIndex + 1;
                break;
              }
            }
            
            // Leer datos desde dataStartRow
            foreach ($rows as $rowIndex => $row) {
              if ($rowIndex < $dataStartRow) continue;
              if ($rowIndex > 500) break; // Límite de seguridad
              
              $day = trim($row['B'] ?? '');
              if (!$day || strlen($day) < 2) continue; // Saltar vacíos
              
              // Convertir fecha (puede estar en formato "1-Jan", como número Excel, etc.)
              $fechaISO = null;
              $parsedYear = $sheetYear;
              
              // Intentar como número de Excel
              if (is_numeric($day) && $day > 0) {
                try {
                  $excelDate = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($day);
                  $fechaISO = $excelDate->format('Y-m-d');
                  $parsedYear = intval($excelDate->format('Y'));
                } catch (Exception $e) {
                  // No es fecha de Excel, intentar como texto
                }
              }
              
              // Intentar como texto de fecha
              if (!$fechaISO) {
                // Formatos: "1-Jan", "01-01-2024", "01/01/2024", etc.
                $dateFormats = [
                  'd-M-Y',    // 01-Jan-2024 (intenta año primero)
                  'd/m/Y',    // 01/01/2024
                  'Y-m-d',    // 2024-01-01
                  'd-m-Y',    // 01-01-2024
                  'm/d/Y',    // 01/01/2024
                  'j/n/Y',    // 1/1/2024
                  'j-M',      // 1-Jan (SIN año, usará $sheetYear)
                  'd-m',      // 01-01 (sin año)
                  'd/m',      // 01/01 (sin año)
                ];
                
                foreach ($dateFormats as $format) {
                  $dateTime = \DateTime::createFromFormat($format, $day);
                  if ($dateTime) {
                    // Si el formato no incluye año, usar el año de la hoja
                    if (strpos($format, 'Y') === false) {
                      $dateTime->setDate($sheetYear, $dateTime->format('m'), $dateTime->format('d'));
                    }
                    $fechaISO = $dateTime->format('Y-m-d');
                    $parsedYear = intval($dateTime->format('Y'));
                    break;
                  }
                }
              }
              
              if (!$fechaISO) continue; // No pudo convertir fecha
              
              // VALIDACIÓN CRÍTICA: si el año es 2005, convertir a 2025
              if ($parsedYear === 2005) {
                $parsedYear = 2025;
                $parts = explode('-', $fechaISO);
                if (count($parts) === 3) {
                  $fechaISO = '2025' . '-' . str_pad($parts[1], 2, '0', STR_PAD_LEFT) . '-' . str_pad($parts[2], 2, '0', STR_PAD_LEFT);
                }
              }
              // Si el año es inválido (anterior a 2000 o más de 1 año futuro), usar año de hoja
              else if ($parsedYear < 2000 || $parsedYear > (intval(date('Y')) + 1)) {
                // Reconstruir la fecha usando SOLO el año de la hoja
                $parts = explode('-', $fechaISO);
                if (count($parts) === 3) {
                  $fechaISO = $sheetYear . '-' . str_pad($parts[1], 2, '0', STR_PAD_LEFT) . '-' . str_pad($parts[2], 2, '0', STR_PAD_LEFT);
                  $parsedYear = $sheetYear;
                }
              }
              
              // Extraer horas de las columnas conocidas (D=entrada, E=café_out, F=café_in, I=comida_out, J=comida_in, L=salida)
              $horas = [];
              $timeColumns = ['D', 'E', 'F', 'I', 'J', 'L'];
              foreach ($timeColumns as $col) {
                $time = trim($row[$col] ?? '');
                // Filtrar: debe parecer una hora (contiene : o .)
                if ($time && preg_match('/\d{1,2}[:\.]\d{2}/', $time)) {
                  $horas[] = $time;
                }
              }
              
              // Si no encontramos horas, saltar
              if (empty($horas)) continue;
              
              // Validación final: 2005 → 2025
              if ($parsedYear === 2005) {
                $parsedYear = 2025;
                $parts = explode('-', $fechaISO);
                if (count($parts) === 3) {
                  $fechaISO = '2025' . '-' . $parts[1] . '-' . $parts[2];
                }
              }
              else if ($parsedYear < 2000 || $parsedYear > (intval(date('Y')) + 1)) {
                $parsedYear = $sheetYear;
                // Reconstruir la fecha con el año correcto
                $parts = explode('-', $fechaISO);
                if (count($parts) === 3) {
                  $fechaISO = $parsedYear . '-' . $parts[1] . '-' . $parts[2];
                }
              }
              
              $excelData[] = [
                'fechaISO' => $fechaISO,
                'horas' => $horas,
                'dia' => $day,
                'fecha' => $fechaISO
              ];
            }
          } catch (Exception $e) {
            error_log('Excel sheet error: ' . $e->getMessage());
          }
        }
        
        if (empty($excelData)) {
          $message = 'No se encontraron datos en las hojas del archivo Excel. Asegúrate de que:' . PHP_EOL .
                     '• La columna B contenga fechas (en cualquier formato)' . PHP_EOL .
                     '• Las hojas contengan horarios en formato HH:MM (ej: 08:00, 10:30)' . PHP_EOL .
                     '• El archivo no esté corrupto';
          $messageType = 'error';
        } else {
          // Mapear horas a slots y mostrar previsualización
          foreach ($excelData as &$record) {
            $record['horas_slots'] = mapTimesToSlots($record['horas']);
          }
          unset($record);
          
          $message = "✓ Se encontraron " . count($excelData) . " registros en el archivo Excel.";
          $messageType = 'success';
          
          // Guardar en variable temporal para mostrar previsualización
          $excelImportData = $excelData;
        }
      } catch (Exception $e) {
        $message = 'Error cargando archivo Excel: ' . $e->getMessage();
        $messageType = 'error';
      }
    }
  } elseif ($file['error'] !== UPLOAD_ERR_NO_FILE) {
    $message = 'Error cargando archivo: ' . $file['error'];
    $messageType = 'error';
  }
}

// Handle image upload with OCR
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['image_file'])) {
  $file = $_FILES['image_file'];
  
  if ($file['error'] === UPLOAD_ERR_OK) {
    if ($file['size'] > MAX_UPLOAD_SIZE) {
      $message = 'El archivo es demasiado grande (máximo 10MB)';
      $messageType = 'error';
    } else {
      $ocr = new OCRProcessor();
      $result = $ocr->processImage($file['tmp_name']);
      
      if (isset($result['error'])) {
        $message = 'Error procesando imagen: ' . $result['error'];
        $messageType = 'error';
      } else {
        $message = 'Imagen procesada exitosamente. Revisa los datos extraídos abajo.';
        $messageType = 'success';
        
        // Map times to slots using intelligent mapping
        $mappedRecords = [];
        if (!empty($result['records'])) {
          foreach ($result['records'] as $record) {
            $slots = mapTimesToSlots($record['horas']);
            $mappedRecords[] = [
              'fechaISO' => $record['fechaISO'],
              'horas' => $record['horas'],
              'horas_slots' => $slots
            ];
          }
        }
        
        $ocrData = [
          'records' => $mappedRecords,
          'raw_text' => $result['raw_text'] ?? ''
        ];
      }
      
      $ocr->cleanup($file['tmp_name']);
    }
  } elseif ($file['error'] !== UPLOAD_ERR_NO_FILE) {
    $message = 'Error cargando archivo: ' . $file['error'];
    $messageType = 'error';
  }
}

// Handle import submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_data'])) {
  $importData = json_decode($_POST['import_data'], true);
  $year = intval($_POST['year']);
  $isOcrImport = false; // Will be set to true if we detect OCR imports
  
  if ($importData && is_array($importData)) {
    $imported = 0;
    $errors = [];
    $yearMismatchYears = [];

    // Use a single UPSERT statement to guarantee overwrite when (user_id,date) already exists.
    // Note: relies on UNIQUE KEY (user_id,date) a.k.a. user_date.
    $upsertSql = 'INSERT INTO entries (user_id,date,start,coffee_out,coffee_in,lunch_out,lunch_in,end,note) '
      . 'VALUES (?,?,?,?,?,?,?,?,?) '
      . 'ON DUPLICATE KEY UPDATE '
      . 'start=VALUES(start),coffee_out=VALUES(coffee_out),coffee_in=VALUES(coffee_in),'
      . 'lunch_out=VALUES(lunch_out),lunch_in=VALUES(lunch_in),end=VALUES(end),'
      . 'note=CASE WHEN note IS NULL OR note = "" THEN VALUES(note) ELSE note END';
    $upsertStmt = $pdo->prepare($upsertSql);
    
    foreach ($importData as $record) {
      $fechaISO = trim((string)($record['fechaISO'] ?? ''));
      if (!$fechaISO) continue;
      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaISO)) {
        $errors[] = "Fecha inválida (fechaISO) '$fechaISO'";
        continue;
      }
      // Extract year from the date itself (from OCR or HTML parser)
      $fechaYear = intval(substr($fechaISO, 0, 4));
      
      // Detect if this is OCR import: OCR records have 'horas' array (not just horas_slots)
      $isOcrRecord = isset($record['horas']) && is_array($record['horas']);
      if ($isOcrRecord) {
        $isOcrImport = true;
      }
      
      // For OCR imports, use the year from the extracted date (ignore form selection)
      // For HTML imports, validate year matches selection
      if (!$isOcrRecord && $year > 0 && $fechaYear !== $year) {
        $yearMismatchYears[$fechaYear] = true;
      }

      // Extraer horas del registro importado
      // Preferimos recibir un array `horas_slots` con 6 posiciones (puede contener cadenas vacías o marcadores)
      // Si viene `horas` como array simple, aplicamos mapeo inteligente
      $horas_slots = null;
      if (isset($record['horas_slots']) && is_array($record['horas_slots'])) {
        $horas_slots = $record['horas_slots'];
      } elseif (isset($record['horas']) && is_array($record['horas'])) {
        // Use intelligent mapping based on number of times (same as HTML importer)
        $horas_slots = mapTimesToSlots($record['horas']);
      }

      // Fallback: if still no horas_slots, create empty
      if ($horas_slots === null) {
        $horas_slots = array_fill(0, 6, '');
      } else {
        // Asegurar exactamente 6 posiciones
        for ($i = 0; $i < 6; $i++) {
          if (!isset($horas_slots[$i])) $horas_slots[$i] = '';
        }
      }

      // Normalizar valores vacíos
      foreach ($horas_slots as $k => $v) {
        if ($v === null) $horas_slots[$k] = '';
        $horas_slots[$k] = trim((string)$horas_slots[$k]);
        if ($horas_slots[$k] === '#' || $horas_slots[$k] === '-') {
          $horas_slots[$k] = '';
        }
      }

      // Mapeo explícito por slot: 0=start,1=coffee_out,2=coffee_in,3=lunch_out,4=lunch_in,5=end
      $start = $horas_slots[0] !== '' ? $horas_slots[0] : null;
      $coffee_out = $horas_slots[1] !== '' ? $horas_slots[1] : null;
      $coffee_in = $horas_slots[2] !== '' ? $horas_slots[2] : null;
      $lunch_out = $horas_slots[3] !== '' ? $horas_slots[3] : null;
      $lunch_in = $horas_slots[4] !== '' ? $horas_slots[4] : null;
      $end = $horas_slots[5] !== '' ? $horas_slots[5] : null;
      
      try {
        $upsertStmt->execute([$user['id'], $fechaISO, $start, $coffee_out, $coffee_in, $lunch_out, $lunch_in, $end, IMPORT_NOTE_TEXT]);
        $imported++;
      } catch (Exception $e) {
        $errors[] = "Error importando fecha $fechaISO: " . $e->getMessage();
      }
    }
    
    if ($imported > 0) {
      $message = "Se importaron correctamente $imported registros.";
      $messageType = 'success';
    }
    if (!empty($yearMismatchYears) && !$isOcrImport) {
      // Only show year mismatch warning for HTML imports (OCR uses date extraction)
      $yrs = array_keys($yearMismatchYears);
      sort($yrs);
      $message .= " Aviso: el fichero contiene fechas de año distinto a $year (" . implode(', ', $yrs) . "). Se han importado igualmente.";
      if ($messageType === 'success') $messageType = 'warning';
    }
    if (count($errors) > 0) {
      $message .= " Errores: " . implode(', ', $errors);
      $messageType = 'warning';
    }
  } else {
    $message = 'No se recibieron datos válidos para importar.';
    $messageType = 'error';
  }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Importar Fichajes</title>
  <link rel="icon" type="image/svg+xml" href="images/favicon.svg">
  <link rel="stylesheet" href="styles.css">
  <style>
    .import-form {
      display: flex;
      flex-direction: column;
      gap: 15px;
      max-width: 600px;
    }
    .preview-section {
      margin-top: 20px;
      display: none;
    }
    .preview-section.show {
      display: block;
    }
    .instructions {
      background: var(--bg-secondary);
      border-left: 4px solid var(--primary-color);
      padding: 12px;
      margin-bottom: 20px;
      border-radius: 8px;
    }
    .instructions h3 {
      margin-top: 0;
      color: var(--text-primary);
    }
    .instructions ol {
      margin: 10px 0;
      padding-left: 20px;
    }
    .button-group {
      display: flex;
      gap: 10px;
    }
    
    /* Loading overlay styles */
    .loading-overlay {
      display: none;
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0, 0, 0, 0.6);
      z-index: 9999;
      justify-content: center;
      align-items: center;
      backdrop-filter: blur(2px);
    }
    
    .loading-overlay.active {
      display: flex;
    }
    
    .loading-content {
      background: white;
      padding: 40px;
      border-radius: 12px;
      text-align: center;
      box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
    }
    
    .spinner {
      width: 60px;
      height: 60px;
      margin: 0 auto 20px;
      border: 4px solid #f3f3f3;
      border-top: 4px solid #2196F3;
      border-radius: 50%;
      animation: spin 1s linear infinite;
    }
    
    @keyframes spin {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }
    
    .loading-text {
      font-size: 18px;
      color: #333;
      font-weight: 500;
      margin: 0;
    }
    
    .loading-subtext {
      font-size: 14px;
      color: #666;
      margin-top: 10px;
    }
  </style>
</head>
<body>
<!-- Loading Overlay -->
<div id="loadingOverlay" class="loading-overlay">
  <div class="loading-content">
    <div class="spinner"></div>
    <p class="loading-text">Importando fichajes...</p>
    <p class="loading-subtext">Por favor, espera mientras procesamos tu archivo</p>
  </div>
</div>

<?php include __DIR__ . '/header.php'; ?>
<div class="container">
  <div class="card">
    <h1>Importar Fichajes</h1>
    
    <?php if ($message): ?>
      <?php
        $alertClass = 'alert-info';
        if ($messageType === 'success') $alertClass = 'alert-success';
        if ($messageType === 'warning') $alertClass = 'alert-warning';
        if ($messageType === 'error') $alertClass = 'alert-danger';
      ?>
      <div class="alert <?php echo htmlspecialchars($alertClass); ?>">
        <?php echo htmlspecialchars($message); ?>
      </div>
    <?php endif; ?>
    
    <!-- XLSX Import Section -->
    <div style="margin-bottom: 30px;">
      <h2>Importar desde archivo Excel (.xlsx)</h2>
      <p class="muted">Carga un archivo Excel con formato HORARIO_2024.xlsx. El sistema procesará automáticamente las hojas de 2024, 2025 y 2026.</p>
      
      <form method="post" enctype="multipart/form-data" class="import-form form-wrapper">
        <div class="form-group">
          <label for="xlsx_file">Archivo Excel (.xlsx):</label>
          <input type="file" id="xlsx_file" name="xlsx_file" accept=".xlsx" class="form-control">
          <div class="muted">Máximo 10MB. Formato: HORARIO_2024.xlsx con hojas 2024, 2025, 2026.</div>
        </div>
        <button type="submit" class="btn btn-primary">Procesar archivo Excel</button>
      </form>
      
      <?php if (!empty($excelImportData)): ?>
        <div style="margin-top: 20px; padding: 15px; background: rgba(33, 150, 243, 0.1); border: 1px solid rgba(33, 150, 243, 0.3); border-radius: 8px;">
          <h3>Previsualización de datos Excel</h3>
          <p>Se encontraron <strong><?php echo count($excelImportData); ?></strong> registros para importar.</p>
          
          <div class="table-responsive">
            <table class="sheet compact">
              <thead>
                <tr>
                  <th>Fecha</th>
                  <th>Entrada</th>
                  <th>Salida café</th>
                  <th>Entrada café</th>
                  <th>Salida comida</th>
                  <th>Entrada comida</th>
                  <th>Salida</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($excelImportData as $rec): ?>
                  <tr>
                    <td><?php echo htmlspecialchars($rec['fechaISO']); ?></td>
                    <?php for ($i = 0; $i < 6; $i++): ?>
                      <td><?php echo htmlspecialchars($rec['horas_slots'][$i] ?? ''); ?></td>
                    <?php endfor; ?>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          
          <form method="post" class="mt-2">
            <input type="hidden" name="import_data" value="<?php echo htmlspecialchars(json_encode($excelImportData)); ?>">
            <input type="hidden" name="year" value="0">
            <button type="submit" class="btn btn-primary">Confirmar importación</button>
          </form>
        </div>
      <?php endif; ?>
    </div>
    
    <hr style="margin: 30px 0; border: 1px solid rgba(255,255,255,0.1);">
    
    <div class="instructions">
      <h3>O importar desde HTML</h3>
      <ol>
        <li>Descarga el informe HTML desde el portal externo ("Guardar como" del navegador).</li>
        <li>Selecciona el archivo y confirma el año.</li>
        <li>Haz clic en <strong>"Cargar y previsualizar"</strong>. Edita los datos si es necesario (puedes usar comas para espacios: <code>08:00,,10:45</code>).</li>
        <li>Marca los días a importar y haz clic en <strong>Importar registros</strong>.</li>
      </ol>
    </div>
    
    <form class="import-form form-wrapper" id="import-form">
      <div class="form-group">
        <label for="html-file">Archivos HTML:</label>
        <input type="file" id="html-file" name="html_file" accept=".html,.htm" required multiple class="form-control">
        <div class="muted">Puedes seleccionar varios ficheros (se combinarán).</div>
      </div>
      
      <div class="form-group">
        <label for="year">Año del informe:</label>
        <input type="number" id="year" name="year" min="2020" max="2030" value="<?php echo date('Y'); ?>" required class="form-control">
      </div>
      
      <div class="button-group">
        <button type="button" id="load-preview-btn" class="btn btn-primary">Cargar y previsualizar</button>
        <button type="button" id="clear-btn" class="btn btn-secondary">Limpiar</button>
      </div>
    </form>
    
    <hr style="margin: 30px 0; border: 1px solid rgba(255,255,255,0.1);">
    
    <h2>O importar desde captura de pantalla (OCR)</h2>
    <p class="muted">Carga una captura de pantalla de tu app móvil y el sistema extraerá automáticamente los horarios usando OCR. La fecha completa (incluyendo año) se detectará de la imagen.</p>
    
    <form method="post" enctype="multipart/form-data" class="import-form form-wrapper">
      <div class="form-group">
        <label for="image_file">Captura de pantalla:</label>
        <input type="file" id="image_file" name="image_file" accept="image/*" class="form-control">
        <div class="muted">Soporta: JPG, PNG, GIF. Máximo 10MB.</div>
      </div>
      
      <div class="form-group">
        <label for="image_year">Año (detectado automáticamente de la imagen):</label>
        <input type="number" id="image_year" name="year" min="2020" max="2030" value="<?php echo date('Y'); ?>" class="form-control" disabled style="opacity: 0.6; cursor: not-allowed;">
        <div class="muted">El año se extraerá automáticamente de la fecha en la imagen, no es necesario seleccionar.</div>
      </div>
      
      <button type="submit" class="btn btn-primary">Procesar imagen</button>
    </form>
    
    <?php if ($ocrData): ?>
      <div style="margin-top: 20px; padding: 15px; background: rgba(34, 197, 94, 0.1); border: 1px solid rgba(34, 197, 94, 0.3); border-radius: 8px;">
        <h3>Datos extraídos de la imagen</h3>
        <p><strong>Texto OCR detectado:</strong></p>
        <pre style="background: rgba(0,0,0,0.3); padding: 10px; border-radius: 4px; overflow-x: auto; max-height: 200px;"><?php echo htmlspecialchars(substr($ocrData['raw_text'], 0, 500)); ?></pre>
        
        <?php if (!empty($ocrData['records'])): ?>
          <p><strong>Registros extraídos:</strong></p>
          <div class="table-responsive">
            <table class="sheet compact">
              <thead>
                <tr>
                  <th>Fecha</th>
                  <th>Entrada</th>
                  <th>Salida café</th>
                  <th>Entrada café</th>
                  <th>Salida comida</th>
                  <th>Entrada comida</th>
                  <th>Salida</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($ocrData['records'] as $rec): ?>
                  <tr>
                    <td><?php echo htmlspecialchars($rec['fechaISO']); ?></td>
                    <?php for ($i = 0; $i < 6; $i++): ?>
                      <td><?php echo htmlspecialchars($rec['horas_slots'][$i] ?? ''); ?></td>
                    <?php endfor; ?>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          
          <form method="post" class="mt-2">
            <input type="hidden" name="import_data" value="<?php echo htmlspecialchars(json_encode($ocrData['records'])); ?>">
            <input type="hidden" name="year" value="<?php echo htmlspecialchars($_POST['year'] ?? date('Y')); ?>">
            <button type="submit" class="btn btn-primary">Importar estos datos</button>
          </form>
        <?php endif; ?>
      </div>
    <?php endif; ?>
      </div>
    </form>
    
    <div class="preview-section" id="preview-section">
      <h2>Previsualización de datos</h2>
      <p>Se encontraron <strong id="record-count">0</strong> registros.</p>
      
      <div class="table-responsive">
        <table class="sheet compact preview-table" id="preview-table">
          <thead>
            <tr>
              <th>Incluir</th>
              <th>Día</th>
              <th>Fecha</th>
              <th>Fecha ISO</th>
              <th>Entrada</th>
              <th>Salida café</th>
              <th>Entrada café</th>
              <th>Salida comida</th>
              <th>Entrada comida</th>
              <th>Salida</th>
              <th>Balance</th>
              <th>Horas (editar)</th>
            </tr>
          </thead>
          <tbody id="preview-tbody">
          </tbody>
        </table>
      </div>
      
      <form method="post" id="import-submit-form" class="mt-2">
        <input type="hidden" name="import_data" id="import-data">
        <input type="hidden" name="year" id="import-year">
        <button type="submit" class="btn btn-primary">Importar registros</button>
      </form>
    </div>
  </div>
</div>

<script src="importFichajes.js"></script>
<script>
(function() {
  const fileInput = document.getElementById('html-file');
  const yearInput = document.getElementById('year');
  const loadPreviewBtn = document.getElementById('load-preview-btn');
  const clearBtn = document.getElementById('clear-btn');
  const previewSection = document.getElementById('preview-section');
  const previewTbody = document.getElementById('preview-tbody');
  const recordCount = document.getElementById('record-count');
  const importDataInput = document.getElementById('import-data');
  const importYearInput = document.getElementById('import-year');
  const importSubmitForm = document.getElementById('import-submit-form');
  
  let currentRecords = [];

  function readFileAsText(file) {
    return new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onload = e => resolve(String(e.target.result || ''));
      reader.onerror = () => reject(new Error('No se pudo leer el fichero'));
      reader.readAsText(file);
    });
  }

  function parseServerSide(file, year) {
    const fd = new FormData();
    fd.append('file', file);
    fd.append('year', String(year));
    return fetch('scripts/parse_tragsa.php', { method: 'POST', body: fd })
      .then(r => r.json())
      .then(data => {
        if (data && data.ok && Array.isArray(data.records)) {
          return data.records.map(function(r){
            return {
              dia: r.dia || '',
              fecha: r.fecha || '',
              fechaISO: r.fechaISO || '',
              horas: r.horas || [],
              balance: r.balance || ''
            };
          });
        }
        return [];
      });
  }

  // --- Heurística de importación: inferir slots (0..5) sin intervención ---
  // Slots: 0=start,1=coffee_out,2=coffee_in,3=lunch_out,4=lunch_in,5=end
  function parseTimeToMinutes(v) {
    if (!v) return null;
    const s = String(v).trim();
    if (!s) return null;
    const m = s.match(/^(\d{1,2})\s*[:\.]\s*(\d{2})$/);
    if (!m) return null;
    const hh = parseInt(m[1], 10);
    const mm = parseInt(m[2], 10);
    if (Number.isNaN(hh) || Number.isNaN(mm)) return null;
    if (hh < 0 || hh > 23 || mm < 0 || mm > 59) return null;
    return hh * 60 + mm;
  }
  function minutesToTimeStr(min) {
    if (min === null || min === undefined) return '';
    const hh = Math.floor(min / 60);
    const mm = min % 60;
    return String(hh).padStart(2, '0') + ':' + String(mm).padStart(2, '0');
  }
  function inferSlotsFromTimes(horasArr) {
    const raw = Array.isArray(horasArr) ? horasArr : [];
    let times = raw
      .map(parseTimeToMinutes)
      .filter(v => v !== null)
      .sort((a, b) => a - b);

    // de-dup
    times = times.filter((v, i) => i === 0 || v !== times[i - 1]);

    // Fichajes muy juntos suelen ser errores (el sistema obliga a esperar >=1 min).
    // Si hay varios fichajes separados por pocos minutos, nos quedamos con el más tardío.
    // Ejemplo típico: 10:25 (mal), 10:27, 10:29 (bueno) => conservar 10:29.
    const CLOSE_FICHAJE_MAX_MIN = 4; // tolerancia de 2-4 min según el caso real
    if (times.length >= 2) {
      const collapsed = [];
      for (let i = 0; i < times.length; i++) {
        const t = times[i];
        if (collapsed.length === 0) {
          collapsed.push(t);
          continue;
        }
        const prev = collapsed[collapsed.length - 1];
        if ((t - prev) <= CLOSE_FICHAJE_MAX_MIN) {
          // reemplazar por el más tardío
          collapsed[collapsed.length - 1] = t;
        } else {
          collapsed.push(t);
        }
      }
      times = collapsed;
    }

    const slotsMin = [null, null, null, null, null, null];
    if (times.length === 0) return slotsMin.map(minutesToTimeStr);
    if (times.length === 1) {
      slotsMin[0] = times[0];
      return slotsMin.map(minutesToTimeStr);
    }

    const start = times[0];
    const end = times[times.length - 1];
    slotsMin[0] = start;
    slotsMin[5] = end;

    const mids = times.slice(1, -1);

    // Detectar si parece jornada partida por hora de salida
    const isSplit = end >= (17 * 60);

    // Parámetros (tolerantes) en minutos
    const COFFEE_MIN = 5;
    const COFFEE_MAX = 50;          // > esto suele ser trabajo de campo, no café
    const LUNCH_MIN = 40;
    const LUNCH_MAX = 180;
    const FIELD_WORK_OMIT_MIN = 75; // pares out/in más largos (fuera de ventana comida) se omiten

    // Buscar mejor par de comida (solo si jornada partida)
    let lunchPair = null; // {out,in,dur,idxOut,idxIn}
    if (isSplit && mids.length >= 2) {
      for (let i = 0; i < mids.length - 1; i++) {
        for (let j = i + 1; j < mids.length; j++) {
          const out = mids[i];
          const inn = mids[j];
          const dur = inn - out;
          if (dur < LUNCH_MIN || dur > LUNCH_MAX) continue;
          // ventana amplia de comida: 13:00 - 16:30 aprox
          if (out < (13 * 60) || out > (16 * 60 + 30)) continue;
          if (inn > (18 * 60 + 30)) continue;
          // preferir el descanso más largo dentro de ventana
          if (!lunchPair || dur > lunchPair.dur) {
            lunchPair = { out, inn, dur, idxOut: i, idxIn: j };
          }
        }
      }
    }

    // Construir lista de mids disponibles tras asignar comida
    let remaining = mids.slice();
    if (lunchPair) {
      slotsMin[3] = lunchPair.out;
      slotsMin[4] = lunchPair.inn;
      // eliminar por índices (j > i)
      remaining.splice(lunchPair.idxIn, 1);
      remaining.splice(lunchPair.idxOut, 1);
    }

    // Buscar mejor par de café
    let coffeePair = null;
    if (remaining.length >= 2) {
      for (let i = 0; i < remaining.length - 1; i++) {
        const out = remaining[i];
        const inn = remaining[i + 1];
        const dur = inn - out;
        if (dur < COFFEE_MIN || dur > COFFEE_MAX) continue;
        // ventana típica del café: 08:30 - 12:45
        if (out < (8 * 60 + 30) || out > (12 * 60 + 45)) continue;
        // preferir duración más cercana a 15-20
        const score = Math.abs(dur - 20);
        if (!coffeePair || score < coffeePair.score) {
          coffeePair = { out, inn, dur, score, idxOut: i, idxIn: i + 1 };
        }
      }
    }
    if (coffeePair) {
      slotsMin[1] = coffeePair.out;
      slotsMin[2] = coffeePair.inn;
    }

    // Omitir periodos "largos" (trabajo de campo) en el medio: no se asignan a slots.
    // No hace falta eliminarlos explícitamente porque no los usamos.
    // Aun así, evitamos que un par largo se cuele como café/comida por umbrales.
    // (FIELD_WORK_OMIT_MIN queda como recordatorio de intención.)
    void FIELD_WORK_OMIT_MIN;

    return slotsMin.map(minutesToTimeStr);
  }

  function hasClosePunchCluster(horasArr) {
    const raw = Array.isArray(horasArr) ? horasArr : [];
    let mins = raw.map(parseTimeToMinutes).filter(v => v !== null).sort((a, b) => a - b);
    mins = mins.filter((v, i) => i === 0 || v !== mins[i - 1]);
    const CLOSE_FICHAJE_MAX_MIN = 4;
    for (let i = 1; i < mins.length; i++) {
      if ((mins[i] - mins[i - 1]) <= CLOSE_FICHAJE_MAX_MIN) return true;
    }
    return false;
  }

  function hasLongFieldWorkGap(horasArr) {
    const raw = Array.isArray(horasArr) ? horasArr : [];
    let mins = raw.map(parseTimeToMinutes).filter(v => v !== null).sort((a, b) => a - b);
    mins = mins.filter((v, i) => i === 0 || v !== mins[i - 1]);
    if (mins.length < 4) return false;
    const start = mins[0];
    const end = mins[mins.length - 1];
    const mids = mins.slice(1, -1);
    const FIELD_WORK_OMIT_MIN = 75;
    // ignore if the long gap is clearly lunch-ish (we still want to infer lunch)
    const LUNCH_WINDOW_START = 12 * 60 + 30;
    const LUNCH_WINDOW_END = 16 * 60 + 30;
    for (let i = 0; i < mids.length - 1; i++) {
      const out = mids[i];
      const inn = mids[i + 1];
      const dur = inn - out;
      if (dur < FIELD_WORK_OMIT_MIN) continue;
      // If this gap is outside a broad lunch window, it's a strong signal of field work.
      if (out < LUNCH_WINDOW_START || out > LUNCH_WINDOW_END) return true;
      // If it's inside lunch window but the day ends early (continuous schedule), still treat as field work.
      if (end < 17 * 60) return true;
    }
    void start;
    return false;
  }

  function normalizeRegistrosToSlots(registros) {
    (registros || []).forEach(function(r) {
      if (!r) return;
      // Si ya viene como 6 slots con huecos, lo respetamos.
      if (Array.isArray(r.horas) && r.horas.length === 6) {
        const nonEmpty = r.horas.filter(v => (v != null && String(v).trim() !== '' && v !== '#' && v !== '-')).length;
        const minsSorted = r.horas.map(parseTimeToMinutes).filter(v => v !== null).sort((a, b) => a - b);
        const endMin = (minsSorted.length > 0) ? minsSorted[minsSorted.length - 1] : null;
        const looksSlotted = r.horas.every(function(v){
          const s = (v == null) ? '' : String(v).trim();
          return s === '' || parseTimeToMinutes(s) !== null || s === '#' || s === '-';
        });
        // Pero si hay fichajes muy juntos (posible error típico), re-inferimos.
        // También re-inferimos si detectamos periodos largos (trabajo de campo) o
        // si parece jornada continua (salida < 17:00) pero vienen 6 marcas no vacías.
        const forceReinfer = hasClosePunchCluster(r.horas)
          || hasLongFieldWorkGap(r.horas)
          || (nonEmpty === 6 && endMin !== null && endMin < 17 * 60);
        if (looksSlotted && !forceReinfer) return;
      }
      r.horas = inferSlotsFromTimes(r.horas);
    });
  }
  
  loadPreviewBtn.addEventListener('click', function() {
    const files = Array.from(fileInput.files || []);
    const year = parseInt(yearInput.value);
    
    if (!files.length) {
      alert('Por favor, selecciona al menos un archivo HTML.');
      return;
    }
    
    if (!year || year < 2020 || year > 2030) {
      alert('Por favor, indica un año válido.');
      return;
    }
    
    (async function(){
      try {
        let allRegistros = [];

        for (const file of files) {
          let registros = [];
          try {
            const htmlContent = await readFileAsText(file);
            if (window.importFichajes && typeof window.importFichajes.parseFichajesHTML === 'function') {
              registros = window.importFichajes.parseFichajesHTML(htmlContent, year) || [];
            }
          } catch (e) {
            registros = [];
          }

          // If client-side parser found nothing, try server-side parser for this file
          if (!registros || registros.length === 0) {
            try {
              registros = await parseServerSide(file, year);
            } catch (e) {
              console.error('server parse error', e);
              registros = [];
            }
          }

          allRegistros = allRegistros.concat(registros || []);
        }

        // Validate (basic)
        if (!Array.isArray(allRegistros) || allRegistros.length === 0) {
          alert('No se encontraron registros en los archivos seleccionados.');
          return;
        }

        // Dedupe by fechaISO (last one wins)
        const map = new Map();
        allRegistros.forEach(r => {
          if (r && r.fechaISO) map.set(r.fechaISO, r);
        });
        const merged = Array.from(map.values());

        // Por defecto ocultamos sábados y domingos en la previsualización
        const filtered = merged.filter(function(r) {
          if (!r || !r.fechaISO) return false;
          const d = new Date(r.fechaISO);
          const day = d.getDay();
          return day !== 0 && day !== 6;
        });

        normalizeRegistrosToSlots(filtered);
        currentRecords = filtered;
        displayPreview(filtered);
        previewSection.classList.add('show');
        importYearInput.value = year;
        updateImportData();
      } catch (error) {
        alert('Error al procesar los archivos: ' + error.message);
        console.error('Error:', error);
      }
    })();
  });
  
  clearBtn.addEventListener('click', function() {
    fileInput.value = '';
    previewSection.classList.remove('show');
    previewTbody.innerHTML = '';
    currentRecords = [];
    importDataInput.value = '';
  });
  
  function displayPreview(registros) {
    previewTbody.innerHTML = '';
    recordCount.textContent = registros.length;
    
    registros.forEach(function(registro, idx) {
      // Asegurar estructura
      registro._include = registro._include !== false;

      const row = document.createElement('tr');

      // Incluir checkbox
      const includeCell = document.createElement('td');
      const includeCb = document.createElement('input');
      includeCb.type = 'checkbox';
      includeCb.checked = registro._include;
      includeCb.addEventListener('change', function() {
        registro._include = includeCb.checked;
        updateImportData();
        recordCount.textContent = (registros.filter(r => r._include)).length;
      });
      includeCell.appendChild(includeCb);
      row.appendChild(includeCell);

      const diaCell = document.createElement('td');
      diaCell.textContent = registro.dia;
      row.appendChild(diaCell);

      const fechaCell = document.createElement('td');
      fechaCell.textContent = registro.fecha;
      row.appendChild(fechaCell);

      const fechaISOCell = document.createElement('td');
      fechaISOCell.textContent = registro.fechaISO;
      row.appendChild(fechaISOCell);

      // Mostrar horas en columnas separadas según el orden esperado
      const getHora = function(horas, idx) {
        if (!Array.isArray(horas)) return '';
        const v = horas[idx];
        if (!v) return '';
        if (v === '#' || v === '-') return '';
        return v;
      };

      const entradaCell = document.createElement('td');
      entradaCell.textContent = getHora(registro.horas, 0);
      row.appendChild(entradaCell);

      const salidaCafeCell = document.createElement('td');
      salidaCafeCell.textContent = getHora(registro.horas, 1);
      row.appendChild(salidaCafeCell);

      const entradaCafeCell = document.createElement('td');
      entradaCafeCell.textContent = getHora(registro.horas, 2);
      row.appendChild(entradaCafeCell);

      const salidaComidaCell = document.createElement('td');
      salidaComidaCell.textContent = getHora(registro.horas, 3);
      row.appendChild(salidaComidaCell);

      const entradaComidaCell = document.createElement('td');
      entradaComidaCell.textContent = getHora(registro.horas, 4);
      row.appendChild(entradaComidaCell);

      const salidaFinalCell = document.createElement('td');
      salidaFinalCell.textContent = getHora(registro.horas, 5);
      row.appendChild(salidaFinalCell);

      const balanceCell = document.createElement('td');
      balanceCell.textContent = registro.balance;
      row.appendChild(balanceCell);

      // Horas crudas editable
      const rawCell = document.createElement('td');
      rawCell.contentEditable = true;
      rawCell.style.minWidth = '140px';
      // Prefill con 6 slots separados por comas para evitar intervención manual
      rawCell.textContent = (Array.isArray(registro.horas) ? registro.horas.slice(0,6).join(', ') : '');
      rawCell.addEventListener('input', function() {
        const text = rawCell.textContent.trim();
        let parts = [];
        if (text.indexOf(',') !== -1) {
          // Si el usuario usa comas, interpretarlas como slots y preservar vacíos
          parts = text.split(',').map(s => s.trim());
        } else if (text.length === 0) {
          parts = [];
        } else {
          // Sin comas: separar por espacios/; y eliminar tokens vacíos
          parts = text.split(/[\s;]+/).map(s => s.trim()).filter(Boolean);
        }

        // Limitar a 6 posiciones
        if (parts.length > 6) parts = parts.slice(0, 6);
        registro.horas = parts;

        // actualizar celdas visibles (tratando '#' y '-' como huecos)
        entradaCell.textContent = getHora(registro.horas, 0);
        salidaCafeCell.textContent = getHora(registro.horas, 1);
        entradaCafeCell.textContent = getHora(registro.horas, 2);
        salidaComidaCell.textContent = getHora(registro.horas, 3);
        entradaComidaCell.textContent = getHora(registro.horas, 4);
        salidaFinalCell.textContent = getHora(registro.horas, 5);
        updateImportData();
      });
      row.appendChild(rawCell);

      previewTbody.appendChild(row);
    });

    // actualizar contador y datos de import
    recordCount.textContent = (registros.filter(r => r._include)).length;
    updateImportData();
  }

  function updateImportData() {
    const toImport = currentRecords.filter(r => r._include !== false).map(function(r) {
      // Construir 'horas_slots' con 6 posiciones (vacías si no hay valor)
      const slots = [];
      if (Array.isArray(r.horas)) {
        for (let i = 0; i < 6; i++) {
          slots[i] = r.horas[i] || '';
        }
      } else {
        for (let i = 0; i < 6; i++) slots[i] = '';
      }

      return {
        dia: r.dia || '',
        fecha: r.fecha || '',
        fechaISO: r.fechaISO || '',
        horas: Array.isArray(r.horas) ? r.horas.filter(h => h && h !== '#' && h !== '-') : [],
        horas_slots: slots,
        balance: r.balance || ''
      };
    });
    importDataInput.value = JSON.stringify(toImport);
  }
  
  importSubmitForm.addEventListener('submit', function(e) {
    // Asegurarse de que los datos están actualizados y contar solo los incluidos
    updateImportData();
    const includedCount = currentRecords.filter(r => r._include !== false).length;
    if (!confirm('¿Estás seguro de que deseas importar estos ' + includedCount + ' registros?')) {
      e.preventDefault();
      return;
    }
    // Si no hay registros incluidos, evitar envío
    if (includedCount === 0) {
      alert('No hay registros seleccionados para importar.');
      e.preventDefault();
    }
  });
})();
</script>

<script>
// Show loading overlay on form submission
document.addEventListener('DOMContentLoaded', function() {
  const loadingOverlay = document.getElementById('loadingOverlay');
  
  // Handle all form submissions
  const forms = document.querySelectorAll('form');
  forms.forEach(form => {
    form.addEventListener('submit', function(e) {
      // Show loading overlay
      loadingOverlay.classList.add('active');
    });
  });
  
  // Optional: Hide overlay if user goes back (back button during loading)
  window.addEventListener('pageshow', function(e) {
    if (e.persisted) {
      loadingOverlay.classList.remove('active');
    }
  });
});
</script>

<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
