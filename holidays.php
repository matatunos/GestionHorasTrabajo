<?php
require_once __DIR__ . '/auth.php';
require_login();
require_once __DIR__ . '/db.php';

$pdo = get_pdo();
$user = current_user();

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
$yearStmt = $pdo->prepare($yearQuery);
$yearStmt->execute();
$availableYears = $yearStmt->fetchAll(PDO::FETCH_COLUMN);

if (empty($availableYears)) {
  $availableYears = [intval(date('Y'))];
}

$selectedYear = isset($_GET['year']) ? intval($_GET['year']) : $availableYears[0];

$typesQuery = 'SELECT code, label, color FROM holiday_types ORDER BY sort_order';
$typesStmt = $pdo->prepare($typesQuery);
$typesStmt->execute();
$holidayTypes = $typesStmt->fetchAll(PDO::FETCH_ASSOC);

$query = 'SELECT h.id, h.date, h.label, h.type, h.annual, h.user_id, 
                 COALESCE(ht.label, h.type) as type_label,
                 COALESCE(ht.color, "#0f172a") as type_color
          FROM holidays h
          LEFT JOIN holiday_types ht ON h.type = ht.code
          WHERE (h.user_id IS NULL OR h.user_id = ?)
          ORDER BY h.date ASC';

$stmt = $pdo->prepare($query);
$stmt->execute([$user['id']]);
$allHolidays = $stmt->fetchAll(PDO::FETCH_ASSOC);

$holidays = [];
foreach ($allHolidays as $h) {
  $hDate = $h['date'];
  $hYear = intval(substr($hDate, 0, 4));
  
  if (!empty($h['annual'])) {
    $hMonth = intval(substr($hDate, 5, 2));
    $hDay = intval(substr($hDate, 8, 2));
    $displayDate = sprintf('%04d-%02d-%02d', $selectedYear, $hMonth, $hDay);
  } else {
    if ($hYear !== $selectedYear) continue;
    $displayDate = $hDate;
  }
  
  $holidays[] = [
    'id' => $h['id'],
    'date' => $displayDate,
    'originalDate' => $h['date'],
    'label' => $h['label'],
    'type' => $h['type'],
    'type_label' => $h['type_label'],
    'type_color' => $h['type_color'],
    'annual' => $h['annual'],
    'user_id' => $h['user_id']
  ];
}

$holidaysByType = [];
foreach ($holidays as $h) {
  $type = $h['type'] ?? 'holiday';
  if (!isset($holidaysByType[$type])) {
    $holidaysByType[$type] = [];
  }
  $holidaysByType[$type][] = $h;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Festivos y Ausencias</title>
  <link rel="icon" type="image/svg+xml" href="images/favicon.svg">
  <link rel="stylesheet" href="styles.css">
  <style>
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
  </style>
</head>
<body class="page-holidays">
  <div class="app-container">
    <aside class="sidebar" id="mobileSidebar">
      <div class="sidebar-header">
        <div class="sidebar-brand-visual">
          <a class="sidebar-brand-logo logo" href="dashboard.php"><h1>GestionHoras</h1></a>
        </div>
        <button class="mobile-menu-toggle" id="mobileMenuClose" aria-label="Cerrar menú">✕</button>
      </div>
      <nav class="sidebar-menu">
        <div class="menu-section">
          <a class="menu-item" href="dashboard.php">Dashboard</a>
          <a class="menu-item" href="holidays.php">📅 Festivos y Ausencias</a>
          <a class="menu-item" href="index.php">Registro horario</a>
          <a class="menu-item" href="import.php">Importar Fichajes</a>
          <a class="menu-item" href="reports.php">Reportes</a>
          <a class="menu-item" href="settings.php">Configuración</a>
          <a class="menu-item" href="holiday-types.php">🏷️ Tipos de Festivos</a>
          
          <div class="menu-item menu-user" tabindex="0">
            <div class="user-avatar"><?php echo strtoupper(substr($user['username'],0,1)); ?></div>
            <span class="menu-user-name"><?php echo htmlspecialchars($user['username']); ?></span>
            <div class="menu-user-dropdown" role="menu">
              <a class="dropdown-item" href="profile.php">👤 Perfil</a>
              <a class="dropdown-item" href="holidays.php">📅 Festivos y Ausencias</a>
              <a class="dropdown-item" href="data_quality.php">📊 Calidad de Datos</a>
              <a class="dropdown-item" href="logout.php">🚪 Salir</a>
            </div>
          </div>
        </div>
      </nav>
    </aside>

    <div class="main-content">
      <header class="header">
        <button class="mobile-menu-toggle" id="mobileMenuOpen" aria-label="Abrir menú">☰</button>
        <div class="header-brand"><a class="header-brand-logo" href="dashboard.php"></a></div>
        <div class="header-actions"></div>
      </header>

      <script>
        document.addEventListener('DOMContentLoaded', function(){
          const sidebar = document.getElementById('mobileSidebar');
          const openBtn = document.getElementById('mobileMenuOpen');
          const closeBtn = document.getElementById('mobileMenuClose');
          if (!sidebar || !openBtn) return;
          openBtn.addEventListener('click', function(e) { e.preventDefault(); e.stopPropagation(); sidebar.classList.add('open'); });
          if (closeBtn) closeBtn.addEventListener('click', function(e) { e.preventDefault(); sidebar.classList.remove('open'); });
          const links = sidebar.querySelectorAll('a.menu-item');
          links.forEach(link => { link.addEventListener('click', function() { setTimeout(() => sidebar.classList.remove('open'), 100); }); });
          sidebar.addEventListener('click', function(e) { if (e.target === sidebar) sidebar.classList.remove('open'); });
          document.addEventListener('click', function(e){
            const mu = document.querySelector('.menu-user');
            if(!mu) return;
            if (mu.contains(e.target)) { e.stopPropagation(); mu.classList.toggle('open'); }
            else { mu.classList.remove('open'); }
          });
          document.addEventListener('keydown', function(e){
            if (e.key === 'Escape') {
              const mu = document.querySelector('.menu-user');
              if(mu) mu.classList.remove('open');
              sidebar.classList.remove('open');
            }
          });
        });
      </script>
    
      <div class="container">
        <div class="card">
          <div class="holidays-header">
            <div><h1>📅 Festivos y Ausencias</h1></div>
            <div style="display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;">
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

      <footer class="footer">
        <div class="container small muted">&copy; 2026 <a href="https://github.com/matatunos/GestionHorasTrabajo" target="_blank" rel="noopener noreferrer">GestionHoras</a></div>
      </footer>
    </div>
  </div>

  <script>
    const yearFilter = document.getElementById('yearFilter');
    const filterAll = document.getElementById('filterAll');
    const typeFilters = document.querySelectorAll('.type-filter');
    const holidaysContainer = document.getElementById('holidaysContainer');

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
