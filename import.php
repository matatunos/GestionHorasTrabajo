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
      // Preferimos recibir un array `horas_slots` con 6 posiciones (puede contener cadenas vacías o marcadores)
      $horas_slots = null;
      if (isset($record['horas_slots']) && is_array($record['horas_slots'])) {
        $horas_slots = $record['horas_slots'];
      }

      // Si no hay `horas_slots`, caemos en el antiguo comportamiento usando 'horas'
      if ($horas_slots === null) {
        $horas = $record['horas'] ?? [];
        // mapear por posición asumida
        $horas_slots = [];
        for ($i = 0; $i < 6; $i++) {
          $horas_slots[$i] = $horas[$i] ?? '';
        }
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
        <li>Descarga tu informe de fichajes en formato HTML desde el portal externo (opción "Guardar como" del navegador).</li>
        <li>Selecciona el archivo HTML con "Seleccionar archivo" y confirma el <strong>año</strong> del informe.</li>
        <li>Haz clic en <strong>"Cargar y previsualizar"</strong>. El sistema intentará parsear el HTML en el navegador; si no encuentra registros, enviará el fichero al servidor como fallback.</li>
        <li>En la previsualización verás una fila por día con columnas: <em>Entrada, Salida café, Entrada café, Salida comida, Entrada comida, Salida</em> y la columna editable <em>Horas (editar)</em>. Por defecto los <strong>sábados y domingos</strong> no se muestran aunque aparezcan en el informe; si quieres verlos, edítalos manualmente antes de importar.</li>
        <li>Para ajustar las horas de un día edítalas en <em>Horas (editar)</em>:
          <ul>
            <li>Si separas con <strong>comas</strong> preservamos huecos entre comas. Ejemplo: <code>08:00,,10:45</code> crea un hueco en la posición intermedia.</li>
            <li>Puedes usar <strong>#</strong> o <strong>-</strong> como marcador explícito (se tratan como hueco y no se importan).</li>
            <li>Si no usas comas, separadores por espacios o <code>;</code> se interpretan y se eliminan huecos vacíos.</li>
          </ul>
        </li>
        <li>Marca/ desmarca la casilla <strong>Incluir</strong> para decidir qué días se importan; solo las filas marcadas se enviarán al servidor.</li>
        <li>Cuando todo esté correcto haz clic en <strong>Importar registros</strong>. Se te pedirá confirmación y solo se enviarán las filas incluidas.</li>
      </ol>
      <p>Notas: el parser intenta mapear las horas por posición (0=entrada, 1=salida café, 2=entrada café, 3=salida comida, 4=entrada comida, 5=salida). Si tienes dudas, edita la columna <em>Horas (editar)</em> para colocar las horas o huecos en el orden correcto.</p>
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
        let registros = [];
        if (window.importFichajes && typeof window.importFichajes.parseFichajesHTML === 'function') {
          registros = window.importFichajes.parseFichajesHTML(htmlContent, year);
        } else {
          registros = [];
        }

        // If client-side parser found nothing, try server-side parser
        if ((!registros || registros.length === 0) && file) {
          const fd = new FormData();
          fd.append('file', file);
          fetch('scripts/parse_tragsa.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
              if (data && data.ok && Array.isArray(data.records) && data.records.length > 0) {
                registros = data.records.map(function(r){
                  return {
                    dia: r.dia || '',
                    fecha: r.fecha || '',
                    fechaISO: r.fechaISO || '',
                    horas: r.horas || [],
                    balance: r.balance || ''
                  };
                });
              }

              let validacion;
              if (window.importFichajes && typeof window.importFichajes.validarRegistros === 'function') {
                validacion = window.importFichajes.validarRegistros(registros);
              } else {
                // Fallback mínimo de validación si el script cliente no está disponible
                const errors = [];
                if (!Array.isArray(registros) || registros.length === 0) {
                  errors.push('No se encontraron registros en el archivo');
                }
                (registros || []).forEach(function(r, i) {
                  if (!r || !r.fechaISO) errors.push('Registro ' + (i + 1) + ': falta fechaISO');
                  if (r && !Array.isArray(r.horas)) errors.push('Registro ' + (i + 1) + ': horas debe ser un array');
                });
                validacion = { valid: errors.length === 0, errors: errors };
              }

              if (!validacion.valid) {
                alert('Errores en los registros:\n' + validacion.errors.join('\n'));
                return;
              }

              // Por defecto ocultamos sábados y domingos en la previsualización
              const filtered = (registros || []).filter(function(r) {
                if (!r || !r.fechaISO) return false;
                const d = new Date(r.fechaISO);
                const day = d.getDay(); // 0 = domingo, 6 = sábado
                return day !== 0 && day !== 6;
              });

              currentRecords = filtered;
              displayPreview(filtered);
              previewSection.classList.add('show');
              importDataInput.value = JSON.stringify(registros);
              importYearInput.value = year;
            })
            .catch(err => {
              alert('Error al procesar el archivo en el servidor: ' + err.message);
              console.error(err);
            });

          return; // server will finalize
        }

        let validacion;
        if (window.importFichajes && typeof window.importFichajes.validarRegistros === 'function') {
          validacion = window.importFichajes.validarRegistros(registros);
        } else {
          const errors = [];
          if (!Array.isArray(registros) || registros.length === 0) {
            errors.push('No se encontraron registros en el archivo');
          }
          (registros || []).forEach(function(r, i) {
            if (!r || !r.fechaISO) errors.push('Registro ' + (i + 1) + ': falta fechaISO');
            if (r && !Array.isArray(r.horas)) errors.push('Registro ' + (i + 1) + ': horas debe ser un array');
          });
          validacion = { valid: errors.length === 0, errors: errors };
        }

        if (!validacion.valid) {
          alert('Errores en los registros:\n' + validacion.errors.join('\n'));
          return;
        }

        // Por defecto ocultamos sábados y domingos en la previsualización
        const filtered = (registros || []).filter(function(r) {
          if (!r || !r.fechaISO) return false;
          const d = new Date(r.fechaISO);
          const day = d.getDay();
          return day !== 0 && day !== 6;
        });

        currentRecords = filtered;
        displayPreview(filtered);
        previewSection.classList.add('show');

        // El valor de importDataInput lo fija updateImportData()
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
      rawCell.textContent = (Array.isArray(registro.horas) ? registro.horas.join(', ') : '');
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

<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
