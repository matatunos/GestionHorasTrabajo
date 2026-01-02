<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

// Constants
define('IMPORT_NOTE_TEXT', 'Importado');

$user = current_user();
require_login();
$pdo = get_pdo();

$message = '';
$messageType = '';

// Handle import submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_data'])) {
  $importData = json_decode($_POST['import_data'], true);
  $year = intval($_POST['year']);
  
  if ($importData && is_array($importData)) {
    $imported = 0;
    $errors = [];
    
    foreach ($importData as $record) {
      $fechaISO = $record['fechaISO'] ?? '';
      if (!$fechaISO) continue;
      
      // Extraer horas del registro importado
      // El formato de horas puede variar, intentamos mapear lo mejor posible
      $horas = $record['horas'] ?? [];
      
      // Mapeo simple: primera hora = start, última hora = end
      $start = count($horas) > 0 ? $horas[0] : null;
      $end = count($horas) > 0 ? $horas[count($horas) - 1] : null;
      
      // Si hay más de 2 horas, intentar mapear intermedias
      $coffee_out = null;
      $coffee_in = null;
      $lunch_out = null;
      $lunch_in = null;
      
      if (count($horas) >= 6) {
        // Formato completo: start, coffee_out, coffee_in, lunch_out, lunch_in, end
        $coffee_out = $horas[1] ?? null;
        $coffee_in = $horas[2] ?? null;
        $lunch_out = $horas[3] ?? null;
        $lunch_in = $horas[4] ?? null;
        $end = $horas[5] ?? null;
      } elseif (count($horas) == 5) {
        // Sin coffee break: start, lunch_out, lunch_in, end (o similar)
        $lunch_out = $horas[1] ?? null;
        $lunch_in = $horas[2] ?? null;
        $end = $horas[3] ?? null;
      } elseif (count($horas) == 4) {
        // Dos pausas: start, pausa1_out, pausa1_in, end
        $lunch_out = $horas[1] ?? null;
        $lunch_in = $horas[2] ?? null;
        $end = $horas[3] ?? null;
      } elseif (count($horas) == 3) {
        $lunch_out = $horas[1] ?? null;
        $lunch_in = $horas[2] ?? null;
      } elseif (count($horas) == 2) {
        // Solo entrada y salida
        $start = $horas[0];
        $end = $horas[1];
      }
      
      try {
        // Check if entry already exists
        $stmt = $pdo->prepare('SELECT id FROM entries WHERE user_id = ? AND date = ? LIMIT 1');
        $stmt->execute([$user['id'], $fechaISO]);
        $existing = $stmt->fetch();
        
        if ($existing) {
          // Update existing entry
          $stmt = $pdo->prepare('UPDATE entries SET start=?,coffee_out=?,coffee_in=?,lunch_out=?,lunch_in=?,end=? WHERE id=?');
          $stmt->execute([$start, $coffee_out, $coffee_in, $lunch_out, $lunch_in, $end, $existing['id']]);
        } else {
          // Insert new entry
          $stmt = $pdo->prepare('INSERT INTO entries (user_id,date,start,coffee_out,coffee_in,lunch_out,lunch_in,end,note) VALUES (?,?,?,?,?,?,?,?,?)');
          $stmt->execute([$user['id'], $fechaISO, $start, $coffee_out, $coffee_in, $lunch_out, $lunch_in, $end, IMPORT_NOTE_TEXT]);
        }
        $imported++;
      } catch (Exception $e) {
        $errors[] = "Error importando fecha $fechaISO: " . $e->getMessage();
      }
    }
    
    if ($imported > 0) {
      $message = "Se importaron correctamente $imported registros.";
      $messageType = 'success';
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
  <link rel="stylesheet" href="styles.css">
  <style>
    .import-form {
      display: flex;
      flex-direction: column;
      gap: 15px;
      max-width: 600px;
    }
    .form-group {
      display: flex;
      flex-direction: column;
      gap: 5px;
    }
    .form-group label {
      font-weight: 500;
    }
    .form-control {
      padding: 8px;
      border: 1px solid #ddd;
      border-radius: 4px;
    }
    .preview-section {
      margin-top: 20px;
      display: none;
    }
    .preview-section.show {
      display: block;
    }
    .preview-table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 10px;
      font-size: 0.9em;
    }
    .preview-table th,
    .preview-table td {
      border: 1px solid #ddd;
      padding: 8px;
      text-align: left;
    }
    .preview-table th {
      background-color: #f5f5f5;
      font-weight: 600;
    }
    .preview-table tr:nth-child(even) {
      background-color: #fafafa;
    }
    .message {
      padding: 12px;
      border-radius: 4px;
      margin-bottom: 15px;
    }
    .message.success {
      background-color: #d4edda;
      border: 1px solid #c3e6cb;
      color: #155724;
    }
    .message.error {
      background-color: #f8d7da;
      border: 1px solid #f5c6cb;
      color: #721c24;
    }
    .message.warning {
      background-color: #fff3cd;
      border: 1px solid #ffeaa7;
      color: #856404;
    }
    .btn-secondary {
      background-color: #6c757d;
      color: white;
      padding: 8px 16px;
      border: none;
      border-radius: 4px;
      cursor: pointer;
    }
    .btn-secondary:hover {
      background-color: #5a6268;
    }
    .instructions {
      background-color: #e7f3ff;
      border-left: 4px solid #2196F3;
      padding: 12px;
      margin-bottom: 20px;
    }
    .instructions h3 {
      margin-top: 0;
      color: #1976D2;
    }
    .instructions ol {
      margin: 10px 0;
      padding-left: 20px;
    }
    .button-group {
      display: flex;
      gap: 10px;
    }
  </style>
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>
<div class="container">
  <div class="card">
    <h1>Importar Fichajes desde HTML</h1>
    
    <?php if ($message): ?>
      <div class="message <?php echo htmlspecialchars($messageType); ?>">
        <?php echo htmlspecialchars($message); ?>
      </div>
    <?php endif; ?>
    
    <div class="instructions">
      <h3>Instrucciones</h3>
      <ol>
        <li>Descarga tu informe de fichajes en formato HTML desde el portal de horas externo usando la opción "Guardar como" del navegador.</li>
        <li>Selecciona el archivo HTML descargado usando el botón "Seleccionar archivo".</li>
        <li>Indica el año correspondiente al informe (necesario ya que la tabla no suele incluir el año).</li>
        <li>Haz clic en "Cargar y previsualizar" para ver los datos extraídos.</li>
        <li>Revisa la previsualización y, si es correcta, haz clic en "Importar registros".</li>
      </ol>
    </div>
    
    <form class="import-form" id="import-form">
      <div class="form-group">
        <label for="html-file">Archivo HTML:</label>
        <input type="file" id="html-file" name="html_file" accept=".html,.htm" required class="form-control">
      </div>
      
      <div class="form-group">
        <label for="year">Año del informe:</label>
        <input type="number" id="year" name="year" min="2020" max="2030" value="<?php echo date('Y'); ?>" required class="form-control">
      </div>
      
      <div class="button-group">
        <button type="button" id="load-preview-btn" class="btn btn-primary">Cargar y previsualizar</button>
        <button type="button" id="clear-btn" class="btn-secondary">Limpiar</button>
      </div>
    </form>
    
    <div class="preview-section" id="preview-section">
      <h2>Previsualización de datos</h2>
      <p>Se encontraron <strong id="record-count">0</strong> registros.</p>
      
      <div style="overflow-x: auto;">
        <table class="preview-table" id="preview-table">
          <thead>
            <tr>
              <th>Día</th>
              <th>Fecha</th>
              <th>Fecha ISO</th>
              <th>Horas</th>
              <th>Balance</th>
            </tr>
          </thead>
          <tbody id="preview-tbody">
          </tbody>
        </table>
      </div>
      
      <form method="post" id="import-submit-form" style="margin-top: 15px;">
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
  
  loadPreviewBtn.addEventListener('click', function() {
    const file = fileInput.files[0];
    const year = parseInt(yearInput.value);
    
    if (!file) {
      alert('Por favor, selecciona un archivo HTML.');
      return;
    }
    
    if (!year || year < 2020 || year > 2030) {
      alert('Por favor, indica un año válido.');
      return;
    }
    
    const reader = new FileReader();
    reader.onload = function(e) {
      const htmlContent = e.target.result;
      
      try {
        const registros = window.importFichajes.parseFichajesHTML(htmlContent, year);
        const validacion = window.importFichajes.validarRegistros(registros);
        
        if (!validacion.valid) {
          alert('Errores en los registros:\n' + validacion.errors.join('\n'));
          return;
        }
        
        currentRecords = registros;
        displayPreview(registros);
        previewSection.classList.add('show');
        
        // Preparar datos para importación
        importDataInput.value = JSON.stringify(registros);
        importYearInput.value = year;
      } catch (error) {
        alert('Error al procesar el archivo: ' + error.message);
        console.error('Error:', error);
      }
    };
    
    reader.readAsText(file);
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
    
    registros.forEach(function(registro) {
      const row = document.createElement('tr');
      
      const diaCell = document.createElement('td');
      diaCell.textContent = registro.dia;
      row.appendChild(diaCell);
      
      const fechaCell = document.createElement('td');
      fechaCell.textContent = registro.fecha;
      row.appendChild(fechaCell);
      
      const fechaISOCell = document.createElement('td');
      fechaISOCell.textContent = registro.fechaISO;
      row.appendChild(fechaISOCell);
      
      const horasCell = document.createElement('td');
      horasCell.textContent = registro.horas.join(', ');
      row.appendChild(horasCell);
      
      const balanceCell = document.createElement('td');
      balanceCell.textContent = registro.balance;
      row.appendChild(balanceCell);
      
      previewTbody.appendChild(row);
    });
  }
  
  importSubmitForm.addEventListener('submit', function(e) {
    if (!confirm('¿Estás seguro de que deseas importar estos ' + currentRecords.length + ' registros?')) {
      e.preventDefault();
    }
  });
})();
</script>

<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
