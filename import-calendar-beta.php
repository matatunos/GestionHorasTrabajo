<?php
require_once __DIR__ . '/auth.php';
require_login();
require_once __DIR__ . '/db.php';

$pdo = get_pdo();
$user = current_user();
$message = '';
$error = '';
$preview = [];
$previewYear = date('Y');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (isset($_POST['action'])) {
    if ($_POST['action'] === 'parse') {
      // Procesar PDF subido
      if (!isset($_FILES['pdf_file']) || $_FILES['pdf_file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Por favor sube un archivo PDF válido.';
      } else {
        $filePath = $_FILES['pdf_file']['tmp_name'];
        $fileName = $_FILES['pdf_file']['name'];
        
        // Validar que sea un PDF
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $filePath);
        finfo_close($finfo);
        
        if ($mimeType !== 'application/pdf') {
          $error = 'El archivo debe ser un PDF válido.';
        } else {
          // Extraer texto del PDF usando pdftotext
          $tempText = tempnam(sys_get_temp_dir(), 'pdf_');
          $cmd = 'pdftotext ' . escapeshellarg($filePath) . ' ' . escapeshellarg($tempText) . ' 2>&1';
          $output = [];
          exec($cmd, $output, $returnCode);
          
          if ($returnCode !== 0) {
            $error = 'Error al procesar el PDF: ' . implode(' ', $output);
          } else {
            $text = file_get_contents($tempText);
            unlink($tempText);
            
            // El año se detectará automáticamente dentro de parseCalendarText
            // Usamos el año del formulario como fallback
            $previewYear = intval($_POST['year'] ?? date('Y'));
            
            // Extraer fechas (la función detectará automáticamente el año)
            $preview = parseCalendarText($text, $previewYear);
            
            if (empty($preview)) {
              $error = 'No se encontraron fechas en el formato esperado (día de mes, Nombre).';
            }
          }
        }
      }
    } elseif ($_POST['action'] === 'import') {
      // Importar fechas seleccionadas
      $year = intval($_POST['year'] ?? date('Y'));
      $dates = $_POST['dates'] ?? [];
      
      if (empty($dates)) {
        $error = 'Selecciona al menos una fecha para importar.';
      } else {
        $imported = 0;
        $duplicates = 0;
        $errors = 0;
        
        foreach ($dates as $dateStr) {
          $parts = explode('|', $dateStr);
          if (count($parts) !== 2) continue;
          
          $date = $parts[0];
          $label = $parts[1];
          
          try {
            // Verificar si ya existe
            $stmt = $pdo->prepare('SELECT id FROM holidays WHERE date = ? AND user_id IS NULL');
            $stmt->execute([$date]);
            
            if ($stmt->fetch()) {
              $duplicates++;
            } else {
              // Insertar como festivo del sistema (user_id NULL)
              $stmt = $pdo->prepare('INSERT INTO holidays (date, label, type, annual) VALUES (?, ?, ?, 1)');
              $stmt->execute([$date, $label, 'holiday']);
              $imported++;
            }
          } catch (Exception $e) {
            $errors++;
          }
        }
        
        if ($imported > 0) {
          $message = "✓ Se importaron $imported festivos.";
          if ($duplicates > 0) {
            $message .= " ($duplicates duplicados ignorados)";
          }
        } else {
          if ($duplicates > 0) {
            $error = "Todos los festivos ya existen en el sistema.";
          } else {
            $error = "Error al importar los festivos.";
          }
        }
        
        $preview = [];
      }
    }
  }
}

function parseCalendarText($text, $yearParam) {
  $preview = [];
  
  // 1. Detectar año del documento: "PARA EL AÑO 2025"
  $detectedYear = $yearParam;
  if (preg_match('/PARA\s+EL\s+AÑO\s+(\d{4})/i', $text, $matches)) {
    $detectedYear = intval($matches[1]);
  }
  
  $monthMap = [
    'enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4,
    'mayo' => 5, 'junio' => 6, 'julio' => 7, 'agosto' => 8,
    'septiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12
  ];
  
  // FESTIVOS NORMALES (FIESTAS NACIONALES, LOCALES, etc.)
  // Solo buscar líneas que tengan COMA (separador entre fecha y nombre)
  if (preg_match('/FIESTAS\s+(?:NACIONALES|LOCALES|ACORDADAS|EMPRESA|CONVENIO|RECUPERABLES)[^\n]*\n(.+?)(?=TURNOS\s+DE\s+NAVIDAD|JORNADA|DOMINGOS|$)/is', $text, $matches)) {
    $section = $matches[1];
    
    // Patrón MÁS RESTRICTIVO: requiere coma y nombre descente después de "de mes"
    // Ejemplos VÁLIDOS:
    // "1 de enero, Año Nuevo"
    // "6 de enero, Día de Reyes"
    // "24 y 31 de diciembre, Festivo"
    // "29 Y 30 de diciembre de 2025, 2 y 5 de enero de 2026"
    
    $pattern = '/(\d{1,2}(?:\s*,\s*\d{1,2})*(?:\s+(?:y|Y)\s+\d{1,2})?)\s+de\s+(\w+)(?:\s+de\s+(\d{4}))?,\s+([^\n]+?)(?:\n|$)/i';
    
    if (preg_match_all($pattern, $section, $matches, PREG_SET_ORDER)) {
      foreach ($matches as $match) {
        $daysStr = $match[1];
        $monthName = strtolower(trim($match[2]));
        $yearFromDate = !empty($match[3]) ? intval($match[3]) : null;
        $labelStr = trim($match[4]);
        
        // Limpiar la etiqueta: remover años, comas, caracteres raros
        $labelStr = preg_replace('/\d{4}\s*$/', '', $labelStr); // Remover año al final
        $labelStr = preg_replace('/^de\s+\d{4}\s+/', '', $labelStr); // Remover "de XXXX" al inicio
        $labelStr = preg_replace('/[,\.]\s*$/', '', $labelStr); // Remover puntuación
        $labelStr = preg_replace('/^\d+[\.\-]\s*/', '', $labelStr); // Remover "1º.- " al inicio
        $labelStr = preg_replace('/^\d+\s+y\s+/', '', $labelStr); // Remover "2 y"
        $labelStr = trim($labelStr);
        
        // Rechazar si la etiqueta es muy corta, solo números, o patrones raros
        if (strlen($labelStr) < 3 || preg_match('/^\d+\s*$/', $labelStr) || preg_match('/^\d+[\.\-]/', $labelStr)) {
          continue;
        }
        
        if (isset($monthMap[$monthName])) {
          $monthNum = str_pad($monthMap[$monthName], 2, '0', STR_PAD_LEFT);
          preg_match_all('/\d{1,2}/', $daysStr, $dayMatches);
          
          foreach ($dayMatches[0] as $dayStr) {
            $dayNum = str_pad(intval($dayStr), 2, '0', STR_PAD_LEFT);
            $year = $yearFromDate ?: $detectedYear;
            $date = "$year-$monthNum-$dayNum";
            
            if (strtotime($date) !== false) {
              // Verificar que no sea duplicado
              $exists = false;
              foreach ($preview as $p) {
                if ($p['date'] === $date) {
                  $exists = true;
                  break;
                }
              }
              
              if (!$exists) {
                $preview[] = [
                  'date' => $date,
                  'label' => $labelStr,
                  'original' => "$dayStr de $monthName - $labelStr"
                ];
              }
            }
          }
        }
      }
    }
  }
  
  // Ordenar por fecha
  usort($preview, function($a, $b) {
    return strcmp($a['date'], $b['date']);
  });
  
  // Eliminar duplicados por fecha
  $seen = [];
  $unique = [];
  foreach ($preview as $item) {
    if (!isset($seen[$item['date']])) {
      $seen[$item['date']] = true;
      $unique[] = $item;
    }
  }
  
  return $unique;
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Importar Calendario Laboral (Beta)</title>
  <link rel="icon" type="image/svg+xml" href="images/favicon.svg">
  <link rel="stylesheet" href="styles.css">
  <style>
    .beta-badge { display: inline-block; background: #ffc107; color: #000; padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.75rem; font-weight: bold; margin-left: 1rem; }
    .upload-area { border: 2px dashed #0056b3; border-radius: 8px; padding: 2rem; text-align: center; background: #f0f7ff; cursor: pointer; transition: all 0.3s; }
    .upload-area:hover { border-color: #004494; background: #e6f0ff; }
    .upload-area.dragover { border-color: #004494; background: #e6f0ff; }
    .upload-icon { font-size: 2.5rem; margin-bottom: 1rem; }
    .btn { padding: 0.6rem 1.2rem; border: none; border-radius: 4px; font-size: 0.95rem; cursor: pointer; transition: all 0.25s ease; text-decoration: none; display: inline-block; font-weight: 500; }
    .btn-primary { background: #0056b3; color: white; }
    .btn-primary:hover { background: #004494; }
    .btn-secondary { background: #6c757d; color: white; }
    .btn-secondary:hover { background: #5a6268; }
    .btn-success { background: #28a745; color: white; }
    .btn-success:hover { background: #218838; }
    .alert { padding: 1rem 1.2rem; border-radius: 6px; margin-bottom: 1.5rem; border-left: 4px solid; }
    .alert-success { background: #d4edda; color: #155724; border-left-color: #28a745; }
    .alert-error { background: #f8d7da; color: #721c24; border-left-color: #dc3545; }
    .alert-info { background: #d1ecf1; color: #0c5460; border-left-color: #17a2b8; }
    .preview-table { width: 100%; border-collapse: collapse; margin-top: 1rem; background: white; border: 1px solid #dee2e6; border-radius: 6px; overflow: hidden; }
    .preview-table th, .preview-table td { padding: 0.75rem; text-align: left; border-bottom: 1px solid #dee2e6; }
    .preview-table th { background: #f8f9fa; font-weight: 600; color: #333; }
    .preview-table tbody tr:hover { background: #f8f9fa; }
    .preview-table input[type="checkbox"] { width: 18px; height: 18px; cursor: pointer; }
    .preview-controls { display: flex; gap: 1rem; margin-bottom: 1.5rem; flex-wrap: wrap; }
    .form-group { margin-bottom: 1rem; }
    .form-group label { display: block; margin-bottom: 0.5rem; font-weight: 600; color: #333; }
    .form-group select { padding: 0.5rem 0.75rem; border: 1px solid #dee2e6; border-radius: 4px; font-size: 1rem; }
    .card { background: white; border: 1px solid #dee2e6; border-radius: 8px; padding: 1.5rem 2rem; margin-bottom: 1.5rem; }
    .card h2 { margin-top: 0; color: #333; }
  </style>
</head>
<body class="page-import-calendar">
  <?php include __DIR__ . '/header.php'; ?>

  <div class="container">
    <div class="card">
      <h1>📅 Importar Calendario Laboral<span class="beta-badge">BETA</span></h1>
      <p style="color: #666; margin-top: 0.5rem;">Sube un PDF del calendario laboral para importar automáticamente los festivos</p>
    </div>

    <?php if ($message): ?>
      <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
      <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php if (empty($preview)): ?>
      <!-- Formulario de subida -->
      <div class="card">
        <h2>Paso 1: Sube el PDF</h2>
        <form method="POST" enctype="multipart/form-data" id="uploadForm">
          <input type="hidden" name="action" value="parse">
          
          <div class="form-group">
            <label for="year">Año del calendario:</label>
            <select id="year" name="year" required>
              <?php for ($y = date('Y'); $y <= date('Y') + 2; $y++): ?>
                <option value="<?php echo $y; ?>" <?php echo ($y === intval(date('Y')) ? 'selected' : ''); ?>><?php echo $y; ?></option>
              <?php endfor; ?>
            </select>
          </div>

          <div class="upload-area" id="uploadArea">
            <div class="upload-icon">📄</div>
            <p style="margin: 0 0 1rem 0;"><strong>Arrastra un PDF aquí</strong> o haz clic para seleccionar</p>
            <input type="file" name="pdf_file" id="pdfFile" accept=".pdf" style="display: none;">
            <button type="button" class="btn btn-primary" onclick="document.getElementById('pdfFile').click()">Seleccionar PDF</button>
          </div>

          <div style="margin-top: 1.5rem;">
            <button type="submit" class="btn btn-primary" id="submitBtn" disabled>📤 Analizar PDF</button>
          </div>
        </form>
      </div>

      <div class="card">
        <h2>💡 Formato soportado</h2>
        <p>El PDF debe contener una sección con el formato:</p>
        <pre style="background: #f8f9fa; padding: 1rem; border-radius: 4px; overflow-x: auto;">FIESTAS NACIONALES Y AUTONÓMICAS
1 de enero, Año Nuevo
6 de enero, Día de Reyes
15 de agosto, La Asunción
25 de diciembre, Navidad</pre>
        <p style="color: #666; font-size: 0.9rem; margin-bottom: 0;">También se detectan automáticamente FIESTAS LOCALES</p>
      </div>
    <?php else: ?>
      <!-- Vista previa -->
      <div class="card">
        <h2>Paso 2: Confirma los festivos a importar</h2>
        <p style="color: #666;">Se encontraron <?php echo count($preview); ?> festivos. Selecciona cuáles deseas importar:</p>
        
        <form method="POST">
          <input type="hidden" name="action" value="import">
          <input type="hidden" name="year" value="<?php echo $previewYear; ?>">
          
          <div class="preview-controls">
            <button type="button" class="btn btn-secondary" onclick="selectAll()">✓ Seleccionar todo</button>
            <button type="button" class="btn btn-secondary" onclick="deselectAll()">✗ Deseleccionar todo</button>
            <button type="submit" class="btn btn-success" onclick="return confirm('¿Importar los festivos seleccionados?')">📥 Importar seleccionados</button>
            <a href="import-calendar-beta.php" class="btn btn-secondary">🔄 Empezar de nuevo</a>
          </div>

          <table class="preview-table">
            <thead>
              <tr>
                <th style="width: 40px;"><input type="checkbox" id="selectAllCheck" onchange="this.checked ? selectAll() : deselectAll()"></th>
                <th>Fecha</th>
                <th>Día</th>
                <th>Nombre</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($preview as $item): 
                $date = new DateTime($item['date']);
                $dayNames = ['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];
                $dayName = $dayNames[$date->format('N') - 1];
              ?>
                <tr>
                  <td><input type="checkbox" name="dates[]" value="<?php echo htmlspecialchars($item['date']); ?>|<?php echo htmlspecialchars($item['label']); ?>" checked></td>
                  <td><strong><?php echo $date->format('d/m/Y'); ?></strong></td>
                  <td><?php echo ucfirst($dayName); ?></td>
                  <td><?php echo htmlspecialchars($item['label']); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </form>
      </div>
    <?php endif; ?>
  </div>

  <?php include __DIR__ . '/footer.php'; ?>

  <script>
    const uploadArea = document.getElementById('uploadArea');
    const pdfFile = document.getElementById('pdfFile');
    const submitBtn = document.getElementById('submitBtn');

    if (uploadArea && pdfFile) {
      // Drag and drop
      uploadArea.addEventListener('dragover', (e) => {
        e.preventDefault();
        uploadArea.classList.add('dragover');
      });

      uploadArea.addEventListener('dragleave', () => {
        uploadArea.classList.remove('dragover');
      });

      uploadArea.addEventListener('drop', (e) => {
        e.preventDefault();
        uploadArea.classList.remove('dragover');
        
        const files = e.dataTransfer.files;
        if (files.length > 0 && files[0].type === 'application/pdf') {
          pdfFile.files = files;
          submitBtn.disabled = false;
        } else {
          alert('Por favor sube un archivo PDF válido');
        }
      });

      // Click to select
      uploadArea.addEventListener('click', () => {
        pdfFile.click();
      });

      pdfFile.addEventListener('change', () => {
        if (pdfFile.files.length > 0) {
          submitBtn.disabled = false;
        }
      });
    }

    function selectAll() {
      document.querySelectorAll('input[name="dates[]"]').forEach(cb => cb.checked = true);
      const selectAllCheck = document.getElementById('selectAllCheck');
      if (selectAllCheck) selectAllCheck.checked = true;
    }

    function deselectAll() {
      document.querySelectorAll('input[name="dates[]"]').forEach(cb => cb.checked = false);
      const selectAllCheck = document.getElementById('selectAllCheck');
      if (selectAllCheck) selectAllCheck.checked = false;
    }
  </script>
</body>
</html>
