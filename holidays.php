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
      $dates_bulk = $_POST['dates_bulk'] ?? '';
      $label = $_POST['label'] ?? '';
      $type = $_POST['type'] ?? 'holiday';
      $annual = isset($_POST['annual']) && $_POST['annual'] === 'on' ? 1 : 0;

      // If a bulk field is provided, accept multiple dates (one per line or comma-separated)
      $dates = [];
      if (!empty(trim($dates_bulk))) {
        $lines = preg_split('/[\r\n,;]+/', $dates_bulk);
        foreach ($lines as $ln) {
          $d = trim($ln);
          if ($d === '') continue;
          // Accept YYYY-MM-DD or DD/MM/YYYY
          if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            $dates[] = $d;
          } elseif (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $d)) {
            $parts = explode('/', $d);
            $dates[] = $parts[2] . '-' . $parts[1] . '-' . $parts[0];
          }
        }
      } elseif (!empty($date)) {
        $dates[] = $date;
      }

      if (empty($dates)) {
        http_response_code(400);
        echo json_encode(['error' => 'Fecha(s) requerida(s)']);
        exit;
      }

      $stmt = $pdo->prepare('INSERT INTO holidays (user_id, date, label, type, annual) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE label = ?, type = ?, annual = ?');
      $count = 0;
      foreach ($dates as $d) {
        try {
          $stmt->execute([$user['id'], $d, $label, $type, $annual, $label, $type, $annual]);
          $count++;
        } catch (Exception $e) {
          // skip invalid/duplicate errors for bulk insert
        }
      }

      echo json_encode(['success' => true, 'message' => 'Festivos agregados', 'count' => $count]);
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
      
      // Si el festivo es del sistema (user_id IS NULL), actualizar con IS NULL; si es propio, filtrar por user_id
      $check = $pdo->prepare('SELECT user_id FROM holidays WHERE date = ? AND (user_id IS NULL OR user_id = ?) LIMIT 1');
      $check->execute([$date, $user['id']]);
      $existing = $check->fetch(PDO::FETCH_ASSOC);
      if ($existing && $existing['user_id'] === null) {
        // Festivo de sistema: solo admin puede editarlo
        $stmt = $pdo->prepare('UPDATE holidays SET label = ?, type = ?, annual = ? WHERE user_id IS NULL AND date = ?');
        $stmt->execute([$label, $type, $annual, $date]);
      } else {
        $stmt = $pdo->prepare('UPDATE holidays SET label = ?, type = ?, annual = ? WHERE user_id = ? AND date = ?');
        $stmt->execute([$label, $type, $annual, $user['id'], $date]);
      }
      
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
      
      // Festivo sistema (user_id IS NULL) o festivo propio
      $chk = $pdo->prepare('SELECT user_id FROM holidays WHERE date = ? AND (user_id IS NULL OR user_id = ?) LIMIT 1');
      $chk->execute([$date, $user['id']]);
      $ex = $chk->fetch(PDO::FETCH_ASSOC);
      if ($ex && $ex['user_id'] === null) {
        $stmt = $pdo->prepare('DELETE FROM holidays WHERE user_id IS NULL AND date = ?');
        $stmt->execute([$date]);
      } else {
        $stmt = $pdo->prepare('DELETE FROM holidays WHERE user_id = ? AND date = ?');
        $stmt->execute([$user['id'], $date]);
      }

      echo json_encode(['success' => true, 'message' => 'Festivo eliminado']);
      exit;
    } catch (Exception $e) {
      http_response_code(500);
      echo json_encode(['error' => $e->getMessage()]);
      exit;
    }
  }
}

// AJAX: get holidays and entries for a given month (JSON)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_month_data') {
  header('Content-Type: application/json');
  $year = intval($_GET['year'] ?? date('Y'));
  $month = intval($_GET['month'] ?? 0);
  if ($month < 1 || $month > 12) { http_response_code(400); echo json_encode(['error' => 'invalid_month']); exit; }
  try {
    $hstmt = $pdo->prepare('SELECT date,label,type,annual,user_id FROM holidays WHERE (user_id IS NULL OR user_id = ?) AND YEAR(date) = ? AND MONTH(date) = ?');
    $hstmt->execute([$user['id'], $year, $month]);
    $hols = $hstmt->fetchAll(PDO::FETCH_ASSOC);

    $est = $pdo->prepare('SELECT DISTINCT date FROM entries WHERE user_id = ? AND YEAR(date) = ? AND MONTH(date) = ?');
    $est->execute([$user['id'], $year, $month]);
    $ents = array_column($est->fetchAll(PDO::FETCH_ASSOC), 'date');

    echo json_encode(['holidays' => $hols, 'entries' => $ents]);
    exit;
  } catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
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
      ['guardia', 'Guardia', '#0284c7', 5, 1],
    ];
    $insertStmt = $pdo->prepare('INSERT INTO holiday_types (code, label, color, sort_order, is_system) VALUES (?, ?, ?, ?, ?)');
    foreach ($defaults as $def) {
      $insertStmt->execute($def);
    }
  }
  
  // Asegurar que el tipo "guardia" exista (para instalaciones existentes)
  $guardiaCheck = $pdo->prepare("SELECT COUNT(*) as cnt FROM holiday_types WHERE code = 'guardia'");
  $guardiaCheck->execute();
  if ($guardiaCheck->fetch()['cnt'] == 0) {
    $pdo->prepare('INSERT INTO holiday_types (code, label, color, sort_order, is_system) VALUES (?, ?, ?, ?, ?)')
        ->execute(['guardia', 'Guardia', '#0284c7', 5, 1]);
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
    'is_own' => ($user['is_admin'] || (!empty($h['user_id']) && $h['user_id'] == $user['id']))  // Mostrar botones si es admin o es festivo del usuario actual
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
    .holiday-item { display: flex; justify-content: space-between; align-items: center; padding: 0.75rem; border-bottom: 1px solid #eee; transition: background-color 0.2s; gap: 0.75rem; flex-wrap: wrap; }
    .holiday-item:last-child { border-bottom: none; }
    .holiday-item:hover { background-color: #f8f9fa; }
    .holiday-date { display: flex; flex-direction: column; gap: 0.25rem; flex-shrink: 0; }
    .holiday-date-main { font-weight: 600; color: #333; font-size: 1rem; }
    .holiday-date-day { font-size: 0.85rem; color: #666; }
    .holiday-label { flex: 1; min-width: 150px; color: #333; }
    .holiday-badge { display: inline-block; padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.8rem; color: #666; background: #e9ecef; white-space: nowrap; }
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
    #holidayModal { display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.5); }
    #holidayModal.show { display: flex !important; align-items: center; justify-content: center; }
    #holidayModal .modal-content { background-color: white; padding: 2rem; border-radius: 8px; box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3); max-width: 500px; width: 90%; max-height: 90vh; overflow-y: auto; }
    #holidayModal .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
    #holidayModal .modal-header h2 { margin: 0; }
    #holidayModal .modal-close { background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #666; }
    #holidayModal .modal-close:hover { color: #000; }
    .form-group { margin-bottom: 1rem; }
    .form-group label { display: block; margin-bottom: 0.5rem; font-weight: 500; }
    .form-group input, .form-group select { width: 100%; padding: 0.5rem; border: 1px solid #dee2e6; border-radius: 4px; font-size: 0.95rem; }
    .form-group input:focus, .form-group select:focus { outline: none; border-color: #0056b3; box-shadow: 0 0 0 3px rgba(0, 86, 179, 0.1); }
    .modal-footer { display: flex; gap: 1rem; justify-content: flex-end; margin-top: 1.5rem; }
    .modal-footer .btn { margin: 0; }
';
  // Calendar styles for modal
  $pageStyles .= '
  .holiday-calendar { margin-top: 8px; }
  .holiday-calendar .cal-header { display:flex; align-items:center; gap:8px; margin-bottom:6px; }
  .holiday-calendar .cal-grid { display:grid; grid-template-columns: repeat(7, 1fr); gap:4px; }
  .holiday-calendar .cal-cell { padding:8px; background:white; border:1px solid #e6e6e6; text-align:center; cursor:pointer; border-radius:4px; min-height:36px; position:relative; }
  .holiday-calendar .cal-cell.other-month { opacity:0.35; }
  .holiday-calendar .cal-cell.holiday { background: rgba(255,230,230,0.9); }
  .holiday-calendar .cal-cell.entry { box-shadow: inset 0 -3px 0 0 #34d399; }
  .holiday-calendar .cal-cell.selected { outline: 2px solid #f97316; }
  .holiday-calendar .cal-weekdays { display:grid; grid-template-columns: repeat(7, 1fr); gap:4px; margin-bottom:6px; }
  .holiday-calendar .cal-weekdays div { font-size:12px; color:#666; text-align:center; }
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
          <button class="btn btn-primary" id="addHolidayBtn" onclick="openAddModal()" style="white-space: nowrap;">➕ Agregar Ausencia</button>
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
                        <?php if (!$h['user_id'] && $h['annual']): ?>
                          <span class="holiday-badge">📅 Anual Sistema</span>
                        <?php elseif ($h['user_id'] && $h['annual']): ?>
                          <span class="holiday-badge">📅 Anual Personal</span>
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
        <h2 id="modalTitle">Agregar Ausencia</h2>
        <button class="modal-close" onclick="closeModal()">&times;</button>
      </div>
      <form id="holidayForm">
        <div class="form-group">
          <label>Selecciona fecha(s):</label>
          <div style="margin-top:0.5rem; color:#555; font-size:0.95rem;">Usa el calendario para seleccionar uno o varios días. Navega mes/año desde el calendario.</div>
          <div id="holidayCalendar" class="holiday-calendar" aria-hidden="false"></div>
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
    let calYear = null;
    let calMonth = null; // 1-12
    const availableYears = <?php echo json_encode($availableYears); ?>;

    function openAddModal() {
      console.log('openAddModal called');
      const holidayModal = document.getElementById('holidayModal');
      const holidayForm = document.getElementById('holidayForm');
      if (!holidayModal) { console.error('holidayModal not found'); return; }
      editingDate = null;
      document.getElementById('modalTitle').textContent = 'Agregar Ausencia';
      if (holidayForm) holidayForm.reset();
      const now = new Date(); calYear = now.getFullYear(); calMonth = now.getMonth()+1;
      holidayModal.classList.add('show');
      console.log('Modal show class added, classes:', holidayModal.className);
      setTimeout(renderCalendarForModal, 40);
    }

    document.addEventListener('DOMContentLoaded', function() {
      const yearFilter = document.getElementById('yearFilter');
      const filterAll = document.getElementById('filterAll');
      const typeFilters = document.querySelectorAll('.type-filter');
      const holidaysContainer = document.getElementById('holidaysContainer');
      const holidayModal = document.getElementById('holidayModal');
      const addHolidayBtn = document.getElementById('addHolidayBtn');
      const holidayForm = document.getElementById('holidayForm');
      
      if (addHolidayBtn) {
        addHolidayBtn.addEventListener('click', function(e) {
          e.preventDefault();
          console.log('addHolidayBtn clicked');
          editingDate = null;
          document.getElementById('modalTitle').textContent = 'Agregar Ausencia';
          holidayForm.reset();
          const now = new Date(); calYear = now.getFullYear(); calMonth = now.getMonth()+1;
          console.log('Adding show class to modal');
          holidayModal.classList.add('show');
          console.log('Modal classes:', holidayModal.className);
          setTimeout(renderCalendarForModal, 40);
        });
      } else {
        console.error('addHolidayBtn not found!');
      }

      if (holidayForm) {
        holidayForm.addEventListener('submit', saveHoliday);
      }

      if (holidayModal) {
        holidayModal.addEventListener('click', function(event) {
          if (event.target === this) {
            closeModal();
          }
        });
      }

      if (yearFilter) {
        yearFilter.addEventListener('change', function() {
          const year = this.value;
          window.location.href = `holidays.php?year=${year}`;
        });
      }

      if (filterAll) {
        filterAll.addEventListener('change', function() {
          if (this.checked) {
            typeFilters.forEach(cb => cb.checked = true);
          }
          updateDisplay();
        });
      }

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
    });

    function closeModal() {
      const holidayModal = document.getElementById('holidayModal');
      holidayModal.classList.remove('show');
      editingDate = null;
    }

    function editHoliday(date, label, type, annual) {
      const holidayModal = document.getElementById('holidayModal');
      editingDate = date;
      document.getElementById('modalTitle').textContent = 'Editar Festivo';
      document.getElementById('holidayLabel').value = label;
      document.getElementById('holidayType').value = type;
      document.getElementById('holidayAnnual').checked = annual || false;
      holidayModal.classList.add('show');
      try {
        const parts = date.split('-');
        if (parts.length === 3) { calYear = parseInt(parts[0],10); calMonth = parseInt(parts[1],10); }
      } catch(e){}
      try { renderCalendarForModal(function(){ setSelectedDates([date]); }); } catch(e){}
    }

    // Calendar helpers for modal: fetch month data and render small month view
    function fetchMonthData(year, month) {
      return fetch('holidays.php?action=get_month_data&year=' + encodeURIComponent(year) + '&month=' + encodeURIComponent(month))
        .then(r => r.json());
    }

    function renderCalendarForModal(callback){
      const cal = document.getElementById('holidayCalendar');
      if (!cal) return;
      if (!calYear || !calMonth) { const now = new Date(); calYear = now.getFullYear(); calMonth = now.getMonth()+1; }
      const year = calYear; const month = calMonth;
      fetchMonthData(year, month).then(data => {
        const monthNames = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
        cal.innerHTML = '';
        const hdr = document.createElement('div'); hdr.className = 'cal-header';
        const prev = document.createElement('button'); prev.type = 'button'; prev.className = 'btn btn-sm'; prev.textContent = '◀';
        const next = document.createElement('button'); next.type = 'button'; next.className = 'btn btn-sm'; next.textContent = '▶';
        const monthSel = document.createElement('select'); monthSel.className = '';
        monthNames.forEach((mname, idx) => { const opt = document.createElement('option'); opt.value = idx+1; opt.textContent = mname; if (idx+1===month) opt.selected = true; monthSel.appendChild(opt); });
        const yearSel = document.createElement('select');
        const yrs = (availableYears && availableYears.length) ? availableYears : (() => { const r=[]; for(let y=year-3;y<=year+3;y++) r.push(y); return r; })();
        yrs.forEach(yv => { const opt = document.createElement('option'); opt.value = yv; opt.textContent = yv; if (yv==year) opt.selected = true; yearSel.appendChild(opt); });
        hdr.appendChild(prev); hdr.appendChild(monthSel); hdr.appendChild(yearSel); hdr.appendChild(next);
        cal.appendChild(hdr);

        const weekdays = document.createElement('div'); weekdays.className = 'cal-weekdays';
        ['L','M','X','J','V','S','D'].forEach(w => { const d = document.createElement('div'); d.textContent = w; weekdays.appendChild(d); });
        cal.appendChild(weekdays);

        const grid = document.createElement('div'); grid.className = 'cal-grid';

        const first = new Date(year, month-1, 1);
        const startDow = (first.getDay() + 6) % 7;
        const daysInMonth = new Date(year, month, 0).getDate();

        const holMap = {};
        (data.holidays || []).forEach(h => { holMap[h.date] = h; });
        const entSet = new Set(data.entries || []);

        const prevMonthDays = startDow;
        const totalCells = Math.ceil((prevMonthDays + daysInMonth) / 7) * 7;
        for (let i=0;i<totalCells;i++){
          const cell = document.createElement('div'); cell.className = 'cal-cell';
          const dayNum = i - prevMonthDays + 1;
          if (dayNum < 1 || dayNum > daysInMonth) { cell.classList.add('other-month'); cell.textContent = ''; }
          else {
            const ymd = year + '-' + String(month).padStart(2,'0') + '-' + String(dayNum).padStart(2,'0');
            cell.textContent = dayNum;
            cell.dataset.date = ymd;
            if (holMap[ymd]) cell.classList.add('holiday');
            if (entSet.has(ymd)) cell.classList.add('entry');
            cell.addEventListener('click', function(){ if (cell.classList.contains('other-month')) return; cell.classList.toggle('selected'); });
          }
          grid.appendChild(cell);
        }
        cal.appendChild(grid);

        if (typeof callback === 'function') callback();

        prev.addEventListener('click', function(){ const nd = new Date(year, month-2, 1); calYear = nd.getFullYear(); calMonth = nd.getMonth()+1; renderCalendarForModal(); });
        next.addEventListener('click', function(){ const nd = new Date(year, month, 1); calYear = nd.getFullYear(); calMonth = nd.getMonth()+1; renderCalendarForModal(); });
        monthSel.addEventListener('change', function(){ calMonth = parseInt(this.value,10); renderCalendarForModal(); });
        yearSel.addEventListener('change', function(){ calYear = parseInt(this.value,10); renderCalendarForModal(); });
      }).catch(err => console.error('calendar fetch error', err));
    }

    function getSelectedDates(){ const picked=[]; document.querySelectorAll('#holidayCalendar .cal-cell.selected').forEach(c=>{ if (c.dataset && c.dataset.date) picked.push(c.dataset.date); }); return picked; }
    function setSelectedDates(dates){ const s = new Set(dates||[]); document.querySelectorAll('#holidayCalendar .cal-cell[data-date]').forEach(c=> c.classList.toggle('selected', s.has(c.dataset.date))); }

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
              refreshHolidays();
            } else {
              alert('Error: ' + (data.error || 'No se pudo eliminar'));
            }
          })
        .catch(error => console.error('Error:', error));
      }
    }

    function saveHoliday(event) {
      event.preventDefault();
      const label = document.getElementById('holidayLabel').value;
      const type = document.getElementById('holidayType').value;
      const annual = document.getElementById('holidayAnnual').checked ? 'on' : '';

      let bodyParams = '';
      if (editingDate) {
        // editing a single existing holiday (do not change date here)
        bodyParams = `action=edit_holiday&date=${encodeURIComponent(editingDate)}&label=${encodeURIComponent(label)}&type=${encodeURIComponent(type)}`;
        if (annual) bodyParams += `&annual=${encodeURIComponent(annual)}`;
      } else {
        const selected = getSelectedDates();
        if (!selected || selected.length === 0) { alert('Selecciona al menos una fecha en el calendario.'); return; }
        const datesBulk = selected.join('\n');
        bodyParams = `action=add_holiday&label=${encodeURIComponent(label)}&type=${encodeURIComponent(type)}&dates_bulk=${encodeURIComponent(datesBulk)}`;
        if (annual) bodyParams += `&annual=${encodeURIComponent(annual)}`;
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
          closeModal();
          refreshHolidays();
          if (data.count && data.count > 1) {
            alert('✓ Se añadieron ' + data.count + ' festivos.');
          }
        } else {
          alert('Error: ' + (data.error || 'No se pudo guardar'));
        }
      })
      .catch(error => console.error('Error:', error));
    }

    function updateDisplay() {
      const typeFilters = document.querySelectorAll('.type-filter');
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

    // Fetch updated HTML for current year and replace parts of the page
    function refreshHolidays(){
      try{
        const yearFilter = document.getElementById('yearFilter');
        const year = yearFilter ? yearFilter.value : '';
        const url = 'holidays.php' + (year ? ('?year=' + encodeURIComponent(year)) : '');
        fetch(url).then(r => r.text()).then(html => {
          const tmp = document.createElement('div'); tmp.innerHTML = html;
          const newStats = tmp.querySelector('.stats-summary');
          const newContainer = tmp.querySelector('#holidaysContainer');
          const curStats = document.querySelector('.stats-summary');
          const curContainer = document.getElementById('holidaysContainer');
          if (newStats && curStats) curStats.innerHTML = newStats.innerHTML;
          if (newContainer && curContainer) curContainer.innerHTML = newContainer.innerHTML;
          // Rebind handlers for edit/delete buttons inside the refreshed container
          // (they use inline onclick attributes, so they remain bound). 
          // Re-apply filtering to respect current checkbox state
          updateDisplay();
        }).catch(err => { console.error('refreshHolidays error', err); window.location.reload(); });
      }catch(e){ console.error('refreshHolidays', e); window.location.reload(); }
    }
  </script>
</body>
</html>
