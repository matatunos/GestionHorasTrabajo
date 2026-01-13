<?php
require_once __DIR__ . '/auth.php';
require_login();
require_once __DIR__ . '/db.php';

$pdo = get_pdo();
$user = current_user();

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
  header('Content-Type: application/json');
  
  $action = $_POST['action'];
  
  if ($action === 'add_holiday') {
    try {
      $date = $_POST['date'] ?? '';
      $label = $_POST['label'] ?? '';
      $type = $_POST['type'] ?? 'holiday';
      $annual = isset($_POST['annual']) && $_POST['annual'] === 'on' ? 1 : 0;
      
      if (empty($date)) {
        http_response_code(400);
        echo json_encode(['error' => 'Fecha requerida']);
        exit;
      }
      
      $stmt = $pdo->prepare('INSERT INTO holidays (user_id, date, label, type, annual) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE label = ?, type = ?, annual = ?');
      $stmt->execute([$user['id'], $date, $label, $type, $annual, $label, $type, $annual]);
      
      echo json_encode(['success' => true, 'message' => 'Festivo agregado']);
      exit;
    } catch (Exception $e) {
      http_response_code(500);
      echo json_encode(['error' => $e->getMessage()]);
      exit;
    }
  }
  
  if ($action === 'edit_holiday') {
    try {
      $date = $_POST['date'] ?? '';
      $label = $_POST['label'] ?? '';
      $type = $_POST['type'] ?? 'holiday';
      $annual = isset($_POST['annual']) && $_POST['annual'] === 'on' ? 1 : 0;
      
      if (empty($date)) {
        http_response_code(400);
        echo json_encode(['error' => 'Fecha requerida']);
        exit;
      }
      
      $stmt = $pdo->prepare('UPDATE holidays SET label = ?, type = ?, annual = ? WHERE user_id = ? AND date = ?');
      $stmt->execute([$label, $type, $annual, $user['id'], $date]);
      
      echo json_encode(['success' => true, 'message' => 'Festivo actualizado']);
      exit;
    } catch (Exception $e) {
      http_response_code(500);
      echo json_encode(['error' => $e->getMessage()]);
      exit;
    }
  }
  
  if ($action === 'delete_holiday') {
    try {
      $date = $_POST['date'] ?? '';
      
      if (empty($date)) {
        http_response_code(400);
        echo json_encode(['error' => 'Fecha requerida']);
        exit;
      }
      
      $stmt = $pdo->prepare('DELETE FROM holidays WHERE user_id = ? AND date = ?');
      $stmt->execute([$user['id'], $date]);
      
      echo json_encode(['success' => true, 'message' => 'Festivo eliminado']);
      exit;
    } catch (Exception $e) {
      http_response_code(500);
      echo json_encode(['error' => $e->getMessage()]);
      exit;
    }
  }
}

// Mensaje de importación exitosa si viene del importador
$import_message = '';
if (!empty($_GET['imported'])) {
  $imported = intval($_GET['imported']);
  $import_message = "✓ Se importaron $imported festivos correctamente.";
}

// Asegurar que las tablas de festivos existen
try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS holidays (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    date DATE NOT NULL,
    label VARCHAR(255) DEFAULT NULL,
    type VARCHAR(20) DEFAULT 'holiday',
    annual TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY user_date_unique (user_id,date)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  
  $pdo->exec("CREATE TABLE IF NOT EXISTS holiday_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    label VARCHAR(100) NOT NULL,
    color VARCHAR(7) DEFAULT '#0f172a',
    sort_order INT DEFAULT 0,
    is_system TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  
  $typeCheck = $pdo->query("SELECT COUNT(*) as cnt FROM holiday_types")->fetch();
  if ($typeCheck['cnt'] == 0) {
    $defaults = [
      ['holiday', 'Festivo', '#dc2626', 0, 1],
      ['vacation', 'Vacaciones', '#059669', 1, 1],
      ['personal', 'Asuntos propios', '#f97316', 2, 1],
      ['enfermedad', 'Enfermedad', '#3b82f6', 3, 1],
      ['permiso', 'Permiso', '#8b5cf6', 4, 1],
    ];
    $insertStmt = $pdo->prepare('INSERT INTO holiday_types (code, label, color, sort_order, is_system) VALUES (?, ?, ?, ?, ?)');
    foreach ($defaults as $def) {
      $insertStmt->execute($def);
    }
  }
} catch (Exception $e) {
  // ok
}

$yearQuery = 'SELECT DISTINCT YEAR(date) as year FROM holidays ORDER BY year DESC LIMIT 10';
$yearsResult = $pdo->query($yearQuery);
$availableYears = array_column($yearsResult->fetchAll(PDO::FETCH_ASSOC), 'year');

$selectedYear = intval($_GET['year'] ?? date('Y'));
if (!in_array($selectedYear, $availableYears) && !empty($availableYears)) {
  $selectedYear = $availableYears[0];
}
if (empty($availableYears)) {
  $availableYears = [date('Y')];
  $selectedYear = date('Y');
}

$holidays = $pdo->query("
  SELECT * FROM holidays 
  WHERE YEAR(date) = $selectedYear
  ORDER BY date ASC
")->fetchAll(PDO::FETCH_ASSOC);

$holidayTypes = $pdo->query("SELECT * FROM holiday_types ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);

$holidays = array_map(function($h) use ($user) {
  return [
    'date' => $h['date'],
    'label' => $h['label'],
    'type' => $h['type'] ?? 'holiday',
    'annual' => $h['annual'],
    'user_id' => $h['user_id'],
    'is_own' => !$h['annual']  // Mostrar botones en festivos no-anuales (personalizables)
  ];
}, $holidays);

$holidaysByType = [];
foreach ($holidays as $h) {
  $type = $h['type'] ?? 'holiday';
  if (!isset($holidaysByType[$type])) {
    $holidaysByType[$type] = [];
  }
  $holidaysByType[$type][] = $h;
}

$pageStyles = '
    .holidays-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; gap: 1rem; flex-wrap: wrap; }
    .year-selector { display: flex; gap: 0.75rem; align-items: center; }
    .year-selector select { padding: 0.5rem 0.75rem; border: 1px solid #dee2e6; border-radius: 4px; font-size: 1rem; min-width: 120px; }
    .filter-panel { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; padding: 1.5rem; margin-bottom: 2rem; }
    .filter-title { font-weight: 600; margin-bottom: 1rem; color: #333; display: flex; align-items: center; gap: 0.5rem; }
    .filter-options { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 0.75rem; }
    .filter-checkbox { display: flex; align-items: center; gap: 0.5rem; padding: 0.5rem; border-radius: 4px; transition: background-color 0.2s; }
    .filter-checkbox:hover { background-color: #e9ecef; }
    .filter-checkbox input[type="checkbox"] { cursor: pointer; width: 18px; height: 18px; }
    .filter-checkbox label { cursor: pointer; margin: 0; flex: 1; display: flex; align-items: center; gap: 0.5rem; }
    .type-color-dot { width: 12px; height: 12px; border-radius: 2px; flex-shrink: 0; }
    .holidays-grid { display: grid; gap: 1.5rem; }
    .holiday-type-section { background: white; border: 1px solid #dee2e6; border-radius: 8px; overflow: hidden; transition: all 0.2s ease; }
    .holiday-type-section.hidden { display: none; }
    .holiday-type-header { background: #f8f9fa; padding: 1rem 1.5rem; border-bottom: 2px solid #dee2e6; display: flex; align-items: center; gap: 0.75rem; font-weight: 600; color: #333; }
    .holiday-type-header .color-dot { width: 16px; height: 16px; border-radius: 3px; flex-shrink: 0; }
    .holiday-type-count { margin-left: auto; font-size: 0.9rem; color: #666; background: white; padding: 0.25rem 0.75rem; border-radius: 20px; font-weight: normal; }
    .holidays-list { padding: 1rem; }
    .holiday-item { display: flex; justify-content: space-between; align-items: center; padding: 0.75rem; border-bottom: 1px solid #eee; transition: background-color 0.2s; }
    .holiday-item:last-child { border-bottom: none; }
    .holiday-item:hover { background-color: #f8f9fa; }
    .holiday-date { display: flex; flex-direction: column; gap: 0.25rem; flex: 1; }
    .holiday-date-main { font-weight: 600; color: #333; font-size: 1rem; }
    .holiday-date-day { font-size: 0.85rem; color: #666; }
    .holiday-label { flex: 2; padding: 0 1rem; color: #333; }
    .holiday-badge { display: inline-block; padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.8rem; color: #666; background: #e9ecef; }
    .empty-state { text-align: center; padding: 3rem; color: #666; }
    .empty-state-icon { font-size: 3rem; margin-bottom: 1rem; }
    .stats-summary { background: white; border: 1px solid #dee2e6; border-radius: 8px; padding: 1.5rem; margin-bottom: 2rem; display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 1rem; }
    .stat-card { text-align: center; }
    .stat-value { font-size: 1.75rem; font-weight: bold; color: #0056b3; margin-bottom: 0.25rem; }
    .stat-label { font-size: 0.9rem; color: #666; }
    .month-section { margin-bottom: 1.5rem; }
    .month-section:last-child { margin-bottom: 0; }
    .month-header { display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 1rem; background: #f0f7ff; border-left: 4px solid #3b82f6; margin-bottom: 0.75rem; border-radius: 4px; }
    .month-name { font-weight: 600; color: #1e40af; font-size: 1rem; }
    .month-count { font-size: 0.85rem; color: #666; background: white; padding: 0.25rem 0.75rem; border-radius: 12px; }
    .month-items { border-left: 2px solid #bfdbfe; padding-left: 1rem; margin-left: 0.5rem; }
    .btn { padding: 0.6rem 1.2rem; border: none; border-radius: 4px; font-size: 0.95rem; cursor: pointer; transition: all 0.25s ease; text-decoration: none; display: inline-block; font-weight: 500; }
    .btn-secondary { background: #6c757d; color: white; }
    .btn-secondary:hover { background: #5a6268; }
    .btn-primary { background: #0056b3; color: white; }
    .btn-primary:hover { background: #004085; }
    .btn-sm { padding: 0.4rem 0.7rem; font-size: 0.8rem; }
    .holiday-actions { display: flex; gap: 0.5rem; }
    .holiday-actions .btn-sm { margin-left: auto; }
    .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.4); }
    .modal.show { display: flex; align-items: center; justify-content: center; }
    .modal-content { background-color: white; padding: 2rem; border-radius: 8px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); max-width: 500px; width: 90%; max-height: 90vh; overflow-y: auto; }
    .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
    .modal-header h2 { margin: 0; }
    .modal-close { background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #666; }
    .modal-close:hover { color: #000; }
    .form-group { margin-bottom: 1rem; }
    .form-group label { display: block; margin-bottom: 0.5rem; font-weight: 500; }
    .form-group input, .form-group select { width: 100%; padding: 0.5rem; border: 1px solid #dee2e6; border-radius: 4px; font-size: 0.95rem; }
    .form-group input:focus, .form-group select:focus { outline: none; border-color: #0056b3; box-shadow: 0 0 0 3px rgba(0, 86, 179, 0.1); }
    .modal-footer { display: flex; gap: 1rem; justify-content: flex-end; margin-top: 1.5rem; }
    .modal-footer .btn { margin: 0; }
';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>📅 Festivos y Ausencias</title>
  <link rel="icon" type="image/svg+xml" href="images/favicon.svg">
  <link rel="stylesheet" href="styles.css">
  <style><?php echo $pageStyles; ?></style>
</head>
<body class="page-holidays">
  <?php include __DIR__ . '/header.php'; ?>

  <div class="container">
    <div class="card">
      <?php if ($import_message): ?>
      <div class="alert alert-success" style="background-color: #d4edda; color: #155724; border-left: 4px solid #28a745; padding: 1rem 1.2rem; border-radius: 6px; margin-bottom: 1.5rem;">
        <?php echo htmlspecialchars($import_message); ?>
      </div>
      <?php endif; ?>
      <div class="holidays-header">
        <div><h1>📅 Festivos y Ausencias</h1></div>
        <div style="display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;">
          <button class="btn btn-primary" id="addHolidayBtn" style="white-space: nowrap;">➕ Agregar Festivo</button>
          <a href="holiday-types.php" class="btn btn-secondary" style="white-space: nowrap;">🏷️ Gestionar Tipos</a>
          <div class="year-selector">
            <label>Año:</label>
            <select id="yearFilter">
              <?php foreach($availableYears as $y): ?>
                <option value="<?php echo $y; ?>" <?php if ($y === $selectedYear) echo 'selected'; ?>><?php echo $y; ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>
    </div>

    <div class="filter-panel">
      <div class="filter-title">🏷️ Filtrar por tipo</div>
      <div class="filter-options" id="typeFilters">
        <div class="filter-checkbox">
          <input type="checkbox" id="filterAll" value="all" checked>
          <label for="filterAll">Mostrar todos</label>
        </div>
        <?php foreach ($holidayTypes as $type): ?>
          <div class="filter-checkbox">
            <input type="checkbox" class="type-filter" value="<?php echo htmlspecialchars($type['code']); ?>" checked>
            <label>
              <span class="type-color-dot" style="background-color: <?php echo htmlspecialchars($type['color']); ?>"></span>
              <?php echo htmlspecialchars($type['label']); ?>
            </label>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="stats-summary">
      <div class="stat-card">
        <div class="stat-value"><?php echo count($holidays); ?></div>
        <div class="stat-label">Total de días</div>
      </div>
      <?php
      $typeCounts = [];
      foreach ($holidays as $h) {
        $type = $h['type'] ?? 'holiday';
        $typeCounts[$type] = ($typeCounts[$type] ?? 0) + 1;
      }
      foreach ($typeCounts as $type => $count) {
        $typeInfo = array_filter($holidayTypes, fn($t) => $t['code'] === $type);
        $typeInfo = reset($typeInfo);
        ?>
        <div class="stat-card">
          <div class="stat-value" style="color: <?php echo htmlspecialchars($typeInfo['color'] ?? '#0056b3'); ?>">
            <?php echo $count; ?>
          </div>
          <div class="stat-label"><?php echo htmlspecialchars($typeInfo['label'] ?? $type); ?></div>
        </div>
        <?php
      }
      ?>
    </div>

    <div class="holidays-grid" id="holidaysContainer">
      <?php if (empty($holidays)): ?>
        <div class="empty-state">
          <div class="empty-state-icon">📋</div>
          <p>No hay festivos registrados para este año</p>
        </div>
      <?php else: ?>
        <?php
        $typeMap = [];
        foreach ($holidayTypes as $type) {
          $typeMap[$type['code']] = $type;
        }
        
        foreach ($holidayTypes as $typeInfo):
          $type = $typeInfo['code'];
          $typeLabelHolidays = $holidaysByType[$type] ?? [];
          if (empty($typeLabelHolidays)) continue;
          
          $holidaysByMonth = [];
          $monthNames = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 
                        'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
          
          foreach ($typeLabelHolidays as $h) {
            $month = intval(substr($h['date'], 5, 2));
            if (!isset($holidaysByMonth[$month])) {
              $holidaysByMonth[$month] = [];
            }
            $holidaysByMonth[$month][] = $h;
          }
          
          ksort($holidaysByMonth);
          ?>
          <div class="holiday-type-section" data-type="<?php echo htmlspecialchars($type); ?>">
            <div class="holiday-type-header">
              <span class="color-dot" style="background-color: <?php echo htmlspecialchars($typeInfo['color']); ?>"></span>
              <span><?php echo htmlspecialchars($typeInfo['label']); ?></span>
              <span class="holiday-type-count"><?php echo count($typeLabelHolidays); ?> días</span>
            </div>
            <div class="holidays-list">
              <?php foreach ($holidaysByMonth as $month => $monthHolidays): ?>
                <div class="month-section">
                  <div class="month-header">
                    <span class="month-name"><?php echo $monthNames[$month] ?? 'Mes ' . $month; ?></span>
                    <span class="month-count"><?php echo count($monthHolidays); ?> días</span>
                  </div>
                  <div class="month-items">
                    <?php foreach ($monthHolidays as $h): ?>
                      <?php
                        $date = DateTime::createFromFormat('Y-m-d', $h['date']);
                        $dayName = ['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'][$date->format('N') - 1];
                      ?>
                      <div class="holiday-item">
                        <div class="holiday-date">
                          <div class="holiday-date-main"><?php echo $date->format('d/m/Y'); ?></div>
                          <div class="holiday-date-day"><?php echo ucfirst($dayName); ?></div>
                        </div>
                        <div class="holiday-label"><?php echo htmlspecialchars($h['label'] ?? '—'); ?></div>
                        <?php if ($h['annual']): ?>
                          <span class="holiday-badge">📅 Anual</span>
                        <?php endif; ?>
                        <?php if ($h['user_id']): ?>
                          <span class="holiday-badge">👤 Personal</span>
                        <?php endif; ?>
                        <?php if ($h['is_own']): ?>
                          <div class="holiday-actions">
                            <button class="btn btn-sm" style="background: #28a745; color: white; padding: 0.3rem 0.6rem;" onclick="editHoliday('<?php echo htmlspecialchars($h['date']); ?>', '<?php echo htmlspecialchars($h['label'] ?? ''); ?>', '<?php echo htmlspecialchars($h['type']); ?>', <?php echo $h['annual'] ? 'true' : 'false'; ?>)">✏️ Editar</button>
                            <button class="btn btn-sm" style="background: #dc3545; color: white; padding: 0.3rem 0.6rem;" onclick="deleteHoliday('<?php echo htmlspecialchars($h['date']); ?>')">🗑️ Eliminar</button>
                          </div>
                        <?php endif; ?>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Modal para agregar/editar festivos -->
  <div id="holidayModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <h2 id="modalTitle">Agregar Festivo</h2>
        <button class="modal-close" onclick="closeModal()">&times;</button>
      </div>
      <form id="holidayForm" onsubmit="saveHoliday(event)">
        <div class="form-group">
          <label for="holidayDate">Fecha:</label>
          <input type="date" id="holidayDate" name="date" required>
        </div>
        <div class="form-group">
          <label for="holidayType">Tipo:</label>
          <select id="holidayType" name="type" required>
            <?php foreach ($holidayTypes as $type): ?>
              <option value="<?php echo htmlspecialchars($type['code']); ?>"><?php echo htmlspecialchars($type['label']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label for="holidayLabel">Descripción (opcional):</label>
          <input type="text" id="holidayLabel" name="label" placeholder="Ej: Día festivo especial">
        </div>
        <div class="form-group">
          <label style="display: flex; align-items: center; gap: 0.5rem;">
            <input type="checkbox" id="holidayAnnual" name="annual">
            <span>Repetir cada año (festivo anual)</span>
          </label>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancelar</button>
          <button type="submit" class="btn btn-primary">Guardar</button>
        </div>
      </form>
    </div>
  </div>

  <?php include __DIR__ . '/footer.php'; ?>

  <script>
    let editingDate = null;
    const yearFilter = document.getElementById('yearFilter');
    const filterAll = document.getElementById('filterAll');
    const typeFilters = document.querySelectorAll('.type-filter');
    const holidaysContainer = document.getElementById('holidaysContainer');
    const holidayModal = document.getElementById('holidayModal');
    const addHolidayBtn = document.getElementById('addHolidayBtn');
    
    addHolidayBtn?.addEventListener('click', function() {
      editingDate = null;
      document.getElementById('modalTitle').textContent = 'Agregar Festivo';
      document.getElementById('holidayForm').reset();
      document.getElementById('holidayDate').valueAsDate = new Date();
      holidayModal.classList.add('show');
    });

    function closeModal() {
      holidayModal.classList.remove('show');
      editingDate = null;
    }

    function editHoliday(date, label, type, annual) {
      editingDate = date;
      document.getElementById('modalTitle').textContent = 'Editar Festivo';
      document.getElementById('holidayDate').value = date;
      document.getElementById('holidayLabel').value = label;
      document.getElementById('holidayType').value = type;
      document.getElementById('holidayAnnual').checked = annual || false;
      holidayModal.classList.add('show');
    }

    function deleteHoliday(date) {
      if (confirm('¿Estás seguro de que quieres eliminar este festivo?')) {
        fetch('holidays.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
          },
          body: 'action=delete_holiday&date=' + encodeURIComponent(date)
        })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            window.location.reload();
          } else {
            alert('Error: ' + (data.error || 'No se pudo eliminar'));
          }
        })
        .catch(error => console.error('Error:', error));
      }
    }

    function saveHoliday(event) {
      event.preventDefault();
      const date = document.getElementById('holidayDate').value;
      const label = document.getElementById('holidayLabel').value;
      const type = document.getElementById('holidayType').value;
      const annual = document.getElementById('holidayAnnual').checked ? 'on' : '';
      const action = editingDate ? 'edit_holiday' : 'add_holiday';

      let bodyParams = `action=${action}&date=${encodeURIComponent(date)}&label=${encodeURIComponent(label)}&type=${encodeURIComponent(type)}`;
      if (annual) {
        bodyParams += `&annual=${encodeURIComponent(annual)}`;
      }

      fetch('holidays.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: bodyParams
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          window.location.reload();
        } else {
          alert('Error: ' + (data.error || 'No se pudo guardar'));
        }
      })
      .catch(error => console.error('Error:', error));
    }

    // Cerrar modal al hacer clic fuera
    holidayModal?.addEventListener('click', function(event) {
      if (event.target === this) {
        closeModal();
      }
    });

    yearFilter?.addEventListener('change', function() {
      const year = this.value;
      window.location.href = `holidays.php?year=${year}`;
    });

    filterAll.addEventListener('change', function() {
      if (this.checked) {
        typeFilters.forEach(cb => cb.checked = true);
      }
      updateDisplay();
    });

    typeFilters.forEach(checkbox => {
      checkbox.addEventListener('change', function() {
        if (!this.checked) {
          filterAll.checked = false;
        }
        const allChecked = Array.from(typeFilters).every(cb => cb.checked);
        if (allChecked) {
          filterAll.checked = true;
        }
        updateDisplay();
      });
    });

    function updateDisplay() {
      const selectedTypes = new Set();
      typeFilters.forEach(cb => {
        if (cb.checked) {
          selectedTypes.add(cb.value);
        }
      });

      const sections = document.querySelectorAll('.holiday-type-section');
      sections.forEach(section => {
        const type = section.dataset.type;
        if (selectedTypes.size === 0 || selectedTypes.has(type)) {
          section.classList.remove('hidden');
        } else {
          section.classList.add('hidden');
        }
      });
    }
  </script>
</body>
</html>
