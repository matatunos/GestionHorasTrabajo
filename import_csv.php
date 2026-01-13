<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/db.php';

$user = current_user();
require_login();
$pdo = get_pdo();

$error = null;
$success = null;
$preview = null;
$import_result = null;

// Handle CSV file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
  $file = $_FILES['csv_file'];
  
  // Validate file
  if ($file['error'] !== UPLOAD_ERR_OK) {
    $error = 'Error al subir el archivo: código de error ' . $file['error'];
  } elseif (!in_array($file['type'], ['text/csv', 'text/plain', 'application/vnd.ms-excel'])) {
    $error = 'Tipo de archivo no válido. Por favor sube un archivo CSV.';
  } else {
    // Read file content
    $content = file_get_contents($file['tmp_name']);
    if ($content === false) {
      $error = 'No se pudo leer el archivo.';
    } else {
      // Parse CSV
      $lines = array_filter(array_map('trim', explode("\n", $content)), 'strlen');
      
      if (count($lines) < 2) {
        $error = 'El archivo CSV está vacío o tiene un formato inválido.';
      } else {
        // Check header
        $header = str_getcsv($lines[0]);
        $header = array_map('trim', $header);
        
        // Expected columns: Fecha, Entrada, Salida, Nota (or similar variations)
        $required_cols = ['Fecha', 'Entrada', 'Salida'];
        $valid_header = true;
        $col_map = [];
        
        $expected_patterns = [
          'fecha' => 'date',
          'entry' => 'start',
          'entrada' => 'start',
          'start_time' => 'start',
          'hora_entrada' => 'start',
          'exit' => 'end',
          'salida' => 'end',
          'end_time' => 'end',
          'hora_salida' => 'end',
          'nota' => 'note',
          'note' => 'note',
          'notas' => 'note'
        ];
        
        foreach ($header as $col) {
          $col_lower = strtolower($col);
          $found = false;
          foreach ($expected_patterns as $pattern => $field) {
            if (strpos($col_lower, $pattern) !== false) {
              $col_map[$col] = $field;
              $found = true;
              break;
            }
          }
          if (!$found && strtolower($col) !== 'nota' && strtolower($col) !== 'note') {
            // Allow unknown columns - they'll be ignored
          }
        }
        
        // Check that we have at least date and one time field
        if (!in_array('date', $col_map) && !in_array('Fecha', $col_map)) {
          $error = 'No se encontró columna de "Fecha". Encabezados encontrados: ' . implode(', ', $header);
          $valid_header = false;
        }
        
        if ($valid_header && is_null($error)) {
          // Generate preview
          $preview = [];
          for ($i = 1; $i < min(6, count($lines)); $i++) {
            $row = str_getcsv($lines[$i]);
            if (count($row) < count($header)) {
              $row = array_pad($row, count($header), '');
            }
            
            $parsed = [];
            foreach ($header as $j => $col) {
              $parsed[$col] = isset($row[$j]) ? trim($row[$j]) : '';
            }
            $preview[] = $parsed;
          }
          
          // Check if importing (confirmation)
          if (isset($_POST['confirm_import']) && $_POST['confirm_import'] === '1') {
            // Perform import
            $imported = 0;
            $errors_import = [];
            $skipped = 0;
            
            for ($i = 1; $i < count($lines); $i++) {
              try {
                $row = str_getcsv($lines[$i]);
                if (count($row) < count($header)) {
                  $row = array_pad($row, count($header), '');
                }
                
                $data = [];
                foreach ($header as $j => $col) {
                  $value = isset($row[$j]) ? trim($row[$j]) : '';
                  if (isset($col_map[$col])) {
                    $data[$col_map[$col]] = $value;
                  }
                }
                
                // Skip empty rows
                if (empty($data['date'])) {
                  $skipped++;
                  continue;
                }
                
                // Validate and format date
                $date_obj = DateTime::createFromFormat('Y-m-d', $data['date']);
                if ($date_obj === false) {
                  // Try other formats
                  $date_obj = DateTime::createFromFormat('d/m/Y', $data['date']) ?: DateTime::createFromFormat('d-m-Y', $data['date']);
                }
                
                if ($date_obj === false) {
                  $errors_import[] = "Fila " . ($i + 1) . ": Fecha inválida '{$data['date']}'";
                  continue;
                }
                
                $formatted_date = $date_obj->format('Y-m-d');
                
                // Prepare entry data
                $entry_data = [
                  'start' => !empty($data['start']) ? $data['start'] : null,
                  'end' => !empty($data['end']) ? $data['end'] : null,
                  'note' => $data['note'] ?? ''
                ];
                
                // Try to insert or update
                $stmt = $pdo->prepare('SELECT id FROM entries WHERE user_id = ? AND date = ?');
                $stmt->execute([$user['id'], $formatted_date]);
                $existing = $stmt->fetch();
                
                if ($existing) {
                  // Update existing
                  $stmt = $pdo->prepare('UPDATE entries SET start = ?, end = ?, note = ? WHERE user_id = ? AND date = ?');
                  $stmt->execute([$entry_data['start'], $entry_data['end'], $entry_data['note'], $user['id'], $formatted_date]);
                } else {
                  // Insert new
                  $stmt = $pdo->prepare('INSERT INTO entries (user_id, date, start, end, note) VALUES (?, ?, ?, ?, ?)');
                  $stmt->execute([$user['id'], $formatted_date, $entry_data['start'], $entry_data['end'], $entry_data['note']]);
                }
                
                $imported++;
                
              } catch (Throwable $e) {
                $errors_import[] = "Fila " . ($i + 1) . ": " . $e->getMessage();
              }
            }
            
            $import_result = [
              'imported' => $imported,
              'skipped' => $skipped,
              'errors' => $errors_import
            ];
          }
        }
      }
    }
  }
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Importar CSV - GestionHorasTrabajo</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
  <style>
    body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; }
    .card { box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15); border: none; }
    .navbar { background: rgba(0, 0, 0, 0.1) !important; backdrop-filter: blur(10px); }
    .btn-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border: none; }
    .btn-primary:hover { background: linear-gradient(135deg, #764ba2 0%, #667eea 100%); }
    .back-btn { color: white; text-decoration: none; }
    .error-box { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 1rem; border-radius: 5px; }
    .success-box { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 1rem; border-radius: 5px; }
    .preview-table { font-size: 0.85rem; }
    .info-box { background: #d1ecf1; border: 1px solid #bee5eb; color: #0c5460; padding: 1rem; border-radius: 5px; margin-top: 1rem; }
  </style>
</head>
<body>
  <nav class="navbar navbar-dark">
    <div class="container">
      <a href="index.php" class="back-btn">← Volver</a>
      <span class="navbar-brand mb-0 h1">📤 Importar CSV</span>
    </div>
  </nav>

  <div class="container my-5">
    <div class="row justify-content-center">
      <div class="col-md-8">
        <div class="card">
          <div class="card-body p-4">

            <?php if ($error): ?>
              <div class="error-box"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if ($import_result): ?>
              <div class="success-box">
                <h5>✓ Importación completada</h5>
                <p><strong><?php echo $import_result['imported']; ?></strong> registros importados</p>
                <?php if ($import_result['skipped'] > 0): ?>
                  <p><strong><?php echo $import_result['skipped']; ?></strong> filas vacías omitidas</p>
                <?php endif; ?>
                <?php if (!empty($import_result['errors'])): ?>
                  <h6>Errores:</h6>
                  <ul style="font-size: 0.9rem;">
                    <?php foreach ($import_result['errors'] as $err): ?>
                      <li><?php echo htmlspecialchars($err); ?></li>
                    <?php endforeach; ?>
                  </ul>
                <?php endif; ?>
              </div>
              <div class="mt-3">
                <a href="index.php" class="btn btn-primary">Volver a la lista</a>
              </div>
            <?php elseif ($preview): ?>
              <h5 class="mb-3">Vista previa de importación</h5>
              
              <div class="info-box">
                <strong>⚠️ Vista previa</strong><br>
                Se importarán aproximadamente <strong><?php echo count($lines) - 1; ?></strong> registros.
              </div>

              <div class="table-responsive mt-3">
                <table class="table table-sm preview-table">
                  <thead class="table-light">
                    <tr>
                      <?php foreach (array_keys($preview[0]) as $col): ?>
                        <th><?php echo htmlspecialchars($col); ?></th>
                      <?php endforeach; ?>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($preview as $row): ?>
                      <tr>
                        <?php foreach ($row as $value): ?>
                          <td><?php echo htmlspecialchars($value); ?></td>
                        <?php endforeach; ?>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>

              <form method="POST" enctype="multipart/form-data" class="mt-3">
                <input type="hidden" name="csv_file" value="">
                <input type="hidden" name="confirm_import" value="1">
                <!-- Re-submit file -->
                <button type="submit" formaction="javascript:void(0);" class="btn btn-primary" onclick="document.getElementById('csv-file-hidden').value = document.getElementById('csv_file').value; this.form.submit();">
                  ✓ Confirmar e importar
                </button>
                <a href="import_csv.php" class="btn btn-secondary">← Volver</a>
              </form>

              <script>
                // Store file in hidden field
                document.addEventListener('DOMContentLoaded', function() {
                  const form = document.querySelector('form');
                  const fileInput = document.getElementById('csv_file');
                  if (fileInput && form) {
                    form.addEventListener('submit', function(e) {
                      if (!confirm('¿Deseas importar estos registros?')) {
                        e.preventDefault();
                      }
                    });
                  }
                });
              </script>

            <?php else: ?>
              <h5 class="mb-3">Selecciona un archivo CSV para importar</h5>
              
              <div class="info-box">
                <strong>ℹ️ Formato esperado:</strong><br>
                El archivo debe tener columnas: <code>Fecha</code>, <code>Entrada</code>, <code>Salida</code> (opcional: <code>Nota</code>)<br>
                Fechas en formato <code>YYYY-MM-DD</code>, <code>DD/MM/YYYY</code> o <code>DD-MM-YYYY</code><br>
                Horas en formato <code>HH:MM</code>
              </div>

              <form method="POST" enctype="multipart/form-data" class="mt-3">
                <div class="mb-3">
                  <label for="csv_file" class="form-label">Archivo CSV</label>
                  <input type="file" class="form-control" id="csv_file" name="csv_file" accept=".csv,.txt" required>
                </div>
                <button type="submit" class="btn btn-primary w-100">📤 Cargar y previsualizar</button>
              </form>

              <hr class="my-4">
              <h6>Ejemplo de formato CSV:</h6>
              <pre><code>Fecha,Entrada,Salida,Nota
2026-01-13,09:00,17:30,Día normal
2026-01-14,09:15,17:45,Llegué tarde
2026-01-15,09:00,13:00,</code></pre>

            <?php endif; ?>

          </div>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
