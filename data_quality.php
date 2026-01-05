<?php
require_once __DIR__ . '/auth.php';
require_login();
require_once __DIR__ . '/db.php';

$user = current_user();
$pdo = get_pdo();

// Parámetros de análisis
$minHoursPerDay = 7.0;  // Mínimo de horas esperadas por día
$maxHoursPerDay = 10.0; // Máximo de horas esperadas por día

// Obtener años disponibles en BD
$yearQuery = 'SELECT DISTINCT YEAR(date) as year FROM entries WHERE user_id = ? ORDER BY year DESC';
$yearStmt = $pdo->prepare($yearQuery);
$yearStmt->execute([$user['id']]);
$availableYears = $yearStmt->fetchAll(PDO::FETCH_COLUMN);

if (empty($availableYears)) {
  $availableYears = [intval(date('Y'))];
}

// Siempre permitir cambiar de año
$selectedYear = isset($_GET['year']) ? intval($_GET['year']) : $availableYears[0];

$startDate = new DateTime($selectedYear . '-01-01');
$endDate = new DateTime($selectedYear . '-12-31');

// Consultar todos los registros del usuario en el período
$query = 'SELECT date, start, coffee_out, coffee_in, lunch_out, lunch_in, end 
          FROM entries 
          WHERE user_id = ? AND date >= ? AND date <= ?
          ORDER BY date ASC';
$stmt = $pdo->prepare($query);
$stmt->execute([$user['id'], $startDate->format('Y-m-d'), $endDate->format('Y-m-d')]);
$entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Helpers
function timeToMinutes($time) {
  if (!$time) return null;
  $parts = explode(':', $time);
  if (count($parts) !== 2) return null;
  return intval($parts[0]) * 60 + intval($parts[1]);
}

function minutesToTime($minutes) {
  if ($minutes === null) return '';
  $h = intval($minutes / 60);
  $m = $minutes % 60;
  return str_pad($h, 2, '0', STR_PAD_LEFT) . ':' . str_pad($m, 2, '0', STR_PAD_LEFT);
}

// Analizar cada entrada y mapear problemas por fecha
$issuesByDate = [];
$entryDates = [];
$totalProblems = 0;

foreach ($entries as $entry) {
  $entryDates[$entry['date']] = $entry;
  
  $startMin = timeToMinutes($entry['start']);
  $endMin = timeToMinutes($entry['end']);
  
  if ($startMin === null || $endMin === null) continue;
  
  $totalMinutes = $endMin - $startMin;
  
  // Restar descansos
  if ($entry['coffee_out'] && $entry['coffee_in']) {
    $coffeOutMin = timeToMinutes($entry['coffee_out']);
    $coffeeInMin = timeToMinutes($entry['coffee_in']);
    if ($coffeOutMin !== null && $coffeeInMin !== null) {
      $totalMinutes -= ($coffeeInMin - $coffeOutMin);
    }
  }
  
  if ($entry['lunch_out'] && $entry['lunch_in']) {
    $lunchOutMin = timeToMinutes($entry['lunch_out']);
    $lunchInMin = timeToMinutes($entry['lunch_in']);
    if ($lunchOutMin !== null && $lunchInMin !== null) {
      $totalMinutes -= ($lunchInMin - $lunchOutMin);
    }
  }
  
  $hoursWorked = $totalMinutes / 60;
  $issues = [];
  $severity = 'ok';
  
  if ($endMin < (16 * 60)) {
    $issues[] = 'Salida muy temprana (' . minutesToTime($endMin) . ')';
    $severity = 'danger';
  } elseif ($endMin > (21 * 60)) {
    $issues[] = 'Salida muy tardía (' . minutesToTime($endMin) . ')';
    $severity = 'danger';
  }
  
  if ($hoursWorked < $minHoursPerDay) {
    $issues[] = sprintf('%.1f h', $hoursWorked);
    if ($severity !== 'danger') $severity = 'warning';
  } elseif ($hoursWorked > $maxHoursPerDay) {
    $issues[] = sprintf('%.1f h', $hoursWorked);
    if ($severity !== 'danger') $severity = 'warning';
  }
  
  if (!empty($issues)) {
    $issuesByDate[$entry['date']] = [
      'issues' => $issues,
      'severity' => $severity,
      'hoursWorked' => $hoursWorked,
      'entry' => $entry
    ];
    $totalProblems++;
  }
}

// Detectar laborables sin fichajes
$currentDate = clone $startDate;
$missingWorkdays = 0;
while ($currentDate <= $endDate) {
  $dayOfWeek = $currentDate->format('N');
  $dateStr = $currentDate->format('Y-m-d');
  
  if ($dayOfWeek <= 5 && !isset($entryDates[$dateStr])) {
    $issuesByDate[$dateStr] = [
      'issues' => ['Sin fichajes'],
      'severity' => 'danger',
      'hoursWorked' => 0,
      'entry' => null
    ];
    $missingWorkdays++;
    $totalProblems++;
  }
  
  $currentDate->modify('+1 day');
}

// Manejo de correcciones
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  $action = $_POST['action'];
  
  if ($action === 'fix_hours' && isset($_POST['date'])) {
    $date = $_POST['date'];
    $start = $_POST['start'] ?? null;
    $end = $_POST['end'] ?? null;
    $coffee_out = $_POST['coffee_out'] ?? null;
    $coffee_in = $_POST['coffee_in'] ?? null;
    $lunch_out = $_POST['lunch_out'] ?? null;
    $lunch_in = $_POST['lunch_in'] ?? null;
    
    $updateQuery = 'UPDATE entries SET start = ?, end = ?, coffee_out = ?, coffee_in = ?, lunch_out = ?, lunch_in = ? WHERE user_id = ? AND date = ?';
    $updateStmt = $pdo->prepare($updateQuery);
    $updateStmt->execute([$start, $end, $coffee_out, $coffee_in, $lunch_out, $lunch_in, $user['id'], $date]);
    
    header('Location: data_quality.php?year=' . $selectedYear . '&fixed=' . urlencode($date));
    exit;
  }
  
  if ($action === 'add_missing_day' && isset($_POST['date'])) {
    $date = $_POST['date'];
    $start = $_POST['start'] ?? null;
    $end = $_POST['end'] ?? null;
    $coffee_out = $_POST['coffee_out'] ?? null;
    $coffee_in = $_POST['coffee_in'] ?? null;
    $lunch_out = $_POST['lunch_out'] ?? null;
    $lunch_in = $_POST['lunch_in'] ?? null;
    
    $insertQuery = 'INSERT INTO entries (user_id, date, start, end, coffee_out, coffee_in, lunch_out, lunch_in, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)';
    $insertStmt = $pdo->prepare($insertQuery);
    $insertStmt->execute([$user['id'], $date, $start, $end, $coffee_out, $coffee_in, $lunch_out, $lunch_in, 'Añadido manualmente']);
    
    header('Location: data_quality.php?year=' . $selectedYear . '&added=' . urlencode($date));
    exit;
  }
}

$fixedDate = isset($_GET['fixed']) ? $_GET['fixed'] : null;
$addedDate = isset($_GET['added']) ? $_GET['added'] : null;
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Calidad de Datos</title>
  <link rel="stylesheet" href="styles.css">
  <style>
    .quality-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 20px;
      gap: 20px;
      flex-wrap: wrap;
    }
    
    .year-selector {
      display: flex;
      gap: 10px;
      align-items: center;
    }
    
    .year-selector select {
      padding: 8px 12px;
      border: 1px solid #ddd;
      border-radius: 4px;
      font-size: 1em;
    }
    
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 15px;
      margin-bottom: 30px;
    }
    
    .stat-box {
      background: white;
      border: 1px solid #dee2e6;
      padding: 15px;
      border-radius: 8px;
      text-align: center;
    }
    
    .stat-value { font-size: 2.5em; font-weight: bold; color: #007bff; }
    .stat-label { color: #6c757d; font-size: 0.9em; margin-top: 5px; }
    
    .calendar-grid {
      display: grid;
      grid-template-columns: repeat(7, 1fr);
      gap: 8px;
      margin-bottom: 15px;
      padding: 15px;
      background: white;
      border-radius: 8px;
      border: 1px solid #dee2e6;
    }
    
    .calendar-month {
      margin-bottom: 30px;
      padding: 15px;
      background: white;
      border-radius: 8px;
      border: 1px solid #dee2e6;
    }
    
    .calendar-month-title {
      font-size: 1.1em;
      font-weight: bold;
      color: #333;
      margin-bottom: 12px;
      text-align: center;
      padding-bottom: 8px;
      border-bottom: 2px solid #dee2e6;
    }
    
    .calendar-grid {
      display: grid;
      grid-template-columns: repeat(7, 1fr);
      gap: 6px;
    }
    
    .calendar-header {
      grid-column: 1 / -1;
      display: grid;
      grid-template-columns: repeat(7, 1fr);
      gap: 6px;
      margin-bottom: 8px;
      font-weight: bold;
      text-align: center;
      font-size: 0.85em;
    }
    
    .calendar-header div { color: #666; }
    
    .calendar-day {
      aspect-ratio: 1;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      border: 1px solid #dee2e6;
      border-radius: 4px;
      cursor: pointer;
      transition: all 0.2s;
      font-size: 0.85em;
      padding: 4px;
      text-align: center;
      background: #f8f9fa;
      min-height: 50px;
    }
    
    .calendar-day:hover { transform: scale(1.05); box-shadow: 0 2px 6px rgba(0,0,0,0.15); }
    
    .calendar-day.other-month { opacity: 0; pointer-events: none; }
    .calendar-day.ok { background: #d4edda; border-color: #28a745; border-width: 2px; }
    .calendar-day.warning { background: #fff3cd; border-color: #ffc107; border-width: 2px; }
    .calendar-day.danger { background: #f8d7da; border-color: #dc3545; border-width: 2px; }
    .calendar-day.today { box-shadow: inset 0 0 0 2px #007bff; }
    
    .calendar-day-number { font-weight: bold; font-size: 1em; }
    .calendar-day-issues { font-size: 0.65em; color: #666; margin-top: 2px; }
    
    .calendar-legend {
      background: #f8f9fa;
      border: 1px solid #dee2e6;
      border-radius: 8px;
      padding: 15px;
      margin-bottom: 25px;
    }
    
    .calendar-legend-title {
      font-weight: bold;
      margin-bottom: 10px;
      color: #333;
    }
    
    .legend-items {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 12px;
    }
    
    .legend-item {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 0.9em;
    }
    
    .legend-dot {
      width: 24px;
      height: 24px;
      border-radius: 4px;
      border: 2px solid;
      flex-shrink: 0;
    }
    
    .legend-dot.ok { background: #d4edda; border-color: #28a745; }
    .legend-dot.warning { background: #fff3cd; border-color: #ffc107; }
    .legend-dot.danger { background: #f8d7da; border-color: #dc3545; }
    .legend-dot.empty { background: #f8f9fa; border-color: #999; opacity: 0.5; }
    
    .problems-list {
      margin-top: 30px;
    }
    
    .problem-card {
      background: white;
      border-left: 4px solid #dc3545;
      padding: 15px;
      margin-bottom: 15px;
      border-radius: 4px;
      cursor: pointer;
      transition: all 0.2s;
    }
    
    .problem-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
    .problem-card.warning { border-left-color: #ffc107; }
    .problem-card.info { border-left-color: #17a2b8; }
    
    .modal-overlay {
      display: none;
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0,0,0,0.5);
      z-index: 999;
    }
    
    .modal-overlay.active { display: flex; justify-content: center; align-items: center; }
    
    .modal-content {
      background: white;
      padding: 25px;
      border-radius: 8px;
      max-width: 500px;
      width: 90%;
      max-height: 90vh;
      overflow-y: auto;
    }
    
    .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
    .form-grid.full { grid-template-columns: 1fr; }
    
    .form-actions { display: flex; gap: 10px; margin-top: 20px; }
    
    @media (max-width: 768px) {
      .stats-grid { grid-template-columns: repeat(2, 1fr); }
      .calendar-grid { padding: 10px; gap: 4px; }
      .calendar-day { font-size: 0.7em; padding: 2px; }
    }
  </style>
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>

<div class="container">
  <div class="card">
    <h1>📊 Análisis de Calidad de Datos</h1>
    
    <?php if ($fixedDate): ?>
      <div class="alert alert-success">✓ Registro de <?php echo htmlspecialchars($fixedDate); ?> actualizado.</div>
    <?php endif; ?>
    
    <?php if ($addedDate): ?>
      <div class="alert alert-success">✓ Fichajes para <?php echo htmlspecialchars($addedDate); ?> añadidos.</div>
    <?php endif; ?>
    
    <!-- Header con selector de año -->
    <div class="quality-header">
      <h2 style="margin: 0;">Año <?php echo $selectedYear; ?></h2>
      <div class="year-selector">
        <label for="yearSelect">Cambiar año:</label>
        <select id="yearSelect" onchange="window.location.href = 'data_quality.php?year=' + this.value">
          <?php foreach (array_reverse($availableYears) as $y): ?>
            <option value="<?php echo $y; ?>" <?php echo $y === $selectedYear ? 'selected' : ''; ?>>
              <?php echo $y; ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    
    <!-- Estadísticas -->
    <div class="stats-grid">
      <div class="stat-box">
        <div class="stat-value"><?php echo count($entryDates); ?></div>
        <div class="stat-label">Días registrados</div>
      </div>
      <div class="stat-box">
        <div class="stat-value"><?php echo $totalProblems; ?></div>
        <div class="stat-label">Problemas detectados</div>
      </div>
      <div class="stat-box">
        <div class="stat-value"><?php echo $missingWorkdays; ?></div>
        <div class="stat-label">Laborables sin fichajes</div>
      </div>
      <div class="stat-box">
        <div class="stat-value"><?php echo intval((count($entryDates) / 251) * 100); ?>%</div>
        <div class="stat-label">Cobertura</div>
      </div>
    </div>
    
    <!-- Calendario visual -->
    <h2>📅 Calendario de Problemas</h2>
    
    <!-- Leyenda de colores -->
    <div class="calendar-legend">
      <div class="calendar-legend-title">Leyenda de colores:</div>
      <div class="legend-items">
        <div class="legend-item">
          <div class="legend-dot ok"></div>
          <span>Sin problemas</span>
        </div>
        <div class="legend-item">
          <div class="legend-dot warning"></div>
          <span>Advertencia (horas atípicas)</span>
        </div>
        <div class="legend-item">
          <div class="legend-dot danger"></div>
          <span>Problema grave (sin fichajes, salida muy temprana/tardía)</span>
        </div>
        <div class="legend-item">
          <div class="legend-dot empty"></div>
          <span>Fin de semana o mes anterior/siguiente</span>
        </div>
      </div>
    </div>
    
    <!-- Calendarios por mes -->
    <?php
    $currentDate = clone $startDate;
    $monthCount = 0;
    
    while ($currentDate->format('Y-m-d') <= $endDate->format('Y-m-d')) {
      $currentMonth = $currentDate->format('m');
      $monthYear = $currentDate->format('Y-m');
      $monthName = $currentDate->format('F Y');
      
      // Localizar nombre del mes en español
      $months = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 
                 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
      $monthName = $months[intval($currentDate->format('m'))] . ' ' . $currentDate->format('Y');
      
      echo '<div class="calendar-month">';
      echo '<div class="calendar-month-title">' . htmlspecialchars($monthName) . '</div>';
      echo '<div class="calendar-grid">';
      
      // Headers días de la semana
      echo '<div class="calendar-header">';
      echo '<div>Lun</div><div>Mar</div><div>Mié</div><div>Jue</div><div>Vie</div><div>Sab</div><div>Dom</div>';
      echo '</div>';
      
      // Primer día del mes
      $firstDay = clone $currentDate;
      $firstDay->setDate($currentDate->format('Y'), $currentDate->format('m'), 1);
      $dayOfWeek = intval($firstDay->format('N'));
      
      // Espacios vacíos antes del primer día
      for ($i = 1; $i < $dayOfWeek; $i++) {
        echo '<div class="calendar-day other-month"></div>';
      }
      
      // Días del mes
      $day = clone $firstDay;
      $lastDay = clone $firstDay;
      $lastDay->modify('last day of this month');
      
      while ($day <= $lastDay) {
        $dateStr = $day->format('Y-m-d');
        $dayNum = $day->format('d');
        
        $status = 'ok';
        $issueText = '';
        
        if (isset($issuesByDate[$dateStr])) {
          $issue = $issuesByDate[$dateStr];
          $status = $issue['severity'];
          $issueText = implode(' | ', $issue['issues']);
        } elseif (isset($entryDates[$dateStr])) {
          $status = 'ok';
        } else {
          $dayOfWeek = intval($day->format('N'));
          if ($dayOfWeek <= 5) {
            $status = 'danger';
            $issueText = 'Sin fichajes';
          }
        }
        
        $today = date('Y-m-d') === $dateStr ? ' today' : '';
        $classStr = 'calendar-day ' . $status . $today;
        
        echo '<div class="' . $classStr . '" onclick="openDetailModal(\'' . htmlspecialchars($dateStr) . '\')" title="' . htmlspecialchars($issueText) . '">';
        echo '<div class="calendar-day-number">' . $dayNum . '</div>';
        if ($issueText && $status !== 'ok') {
          $preview = substr($issueText, 0, 15);
          if (strlen($issueText) > 15) $preview .= '...';
          echo '<div class="calendar-day-issues">' . htmlspecialchars($preview) . '</div>';
        }
        echo '</div>';
        
        $day->modify('+1 day');
      }
      
      // Espacios vacíos al final del mes
      $lastDayOfWeek = intval($lastDay->format('N'));
      for ($i = $lastDayOfWeek; $i < 7; $i++) {
        echo '<div class="calendar-day other-month"></div>';
      }
      
      echo '</div>';
      echo '</div>';
      
      // Pasar al siguiente mes
      $currentDate->modify('first day of next month');
    }
    ?>
    
    <!-- Problemas listados -->
    <?php if ($totalProblems > 0): ?>
      <div class="problems-list">
        <h2>⚠️ Problemas Detectados</h2>
        
        <?php
        // Ordenar problemas por severidad
        $sortedIssues = [];
        foreach ($issuesByDate as $date => $data) {
          $sortedIssues[$date] = $data;
        }
        krsort($sortedIssues);
        
        foreach (array_slice($sortedIssues, 0, 20) as $date => $data):
        ?>
          <div class="problem-card <?php echo $data['severity']; ?>" onclick="openDetailModal('<?php echo htmlspecialchars($date); ?>')">
            <h4 style="margin-top: 0;">
              <?php echo date('d/m/Y (l)', strtotime($date)); ?>
            </h4>
            <div style="margin-bottom: 10px;">
              <?php foreach ($data['issues'] as $issue): ?>
                <span style="display: inline-block; background: #f0f0f0; padding: 4px 8px; border-radius: 3px; margin-right: 5px; margin-bottom: 5px; font-size: 0.9em;">
                  <?php echo htmlspecialchars($issue); ?>
                </span>
              <?php endforeach; ?>
            </div>
            <?php if ($data['entry']): ?>
              <small style="color: #666;">
                <?php echo $data['entry']['start'] ?? '-'; ?> - <?php echo $data['entry']['end'] ?? '-'; ?>
              </small>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
        
        <?php if ($totalProblems > 20): ?>
          <p class="muted">Mostrando 20 de <?php echo $totalProblems; ?> problemas.</p>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="alert alert-success" style="margin-top: 20px;">
        ✓ No se encontraron problemas en <?php echo $selectedYear; ?>.
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Modal de detalle y corrección -->
<div class="modal-overlay" id="detailModal" onclick="if(event.target === this) closeDetailModal()">
  <div class="modal-content">
    <h3 id="modalTitle" style="margin-top: 0;"></h3>
    
    <div id="modalBody" style="margin-bottom: 20px;"></div>
    
    <form method="post" id="modalForm">
      <input type="hidden" name="action" id="modalAction" value="">
      <input type="hidden" name="date" id="modalDate" value="">
      
      <div class="form-grid">
        <div class="form-group">
          <label>Entrada</label>
          <input type="time" name="start" id="modalStart" class="form-control">
        </div>
        <div class="form-group">
          <label>Salida</label>
          <input type="time" name="end" id="modalEnd" class="form-control">
        </div>
      </div>
      
      <div class="form-grid">
        <div class="form-group">
          <label>Café - Salida</label>
          <input type="time" name="coffee_out" id="modalCoffeeOut" class="form-control">
        </div>
        <div class="form-group">
          <label>Café - Entrada</label>
          <input type="time" name="coffee_in" id="modalCoffeeIn" class="form-control">
        </div>
      </div>
      
      <div class="form-grid">
        <div class="form-group">
          <label>Comida - Salida</label>
          <input type="time" name="lunch_out" id="modalLunchOut" class="form-control">
        </div>
        <div class="form-group">
          <label>Comida - Entrada</label>
          <input type="time" name="lunch_in" id="modalLunchIn" class="form-control">
        </div>
      </div>
      
      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Guardar cambios</button>
        <button type="button" class="btn btn-secondary" onclick="closeDetailModal()">Cancelar</button>
      </div>
    </form>
  </div>
</div>

<script>
function openDetailModal(date) {
  const issues = <?php echo json_encode($issuesByDate); ?>;
  const issue = issues[date] || { issues: [], severity: 'info', entry: null };
  const entry = issue.entry || {};
  
  document.getElementById('modalDate').value = date;
  document.getElementById('modalTitle').textContent = new Date(date + 'T00:00:00').toLocaleDateString('es-ES', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
  
  let body = '<div style="margin-bottom: 15px;">';
  issue.issues.forEach(i => {
    body += '<div style="background: #f0f0f0; padding: 8px; border-radius: 4px; margin-bottom: 5px; font-size: 0.9em;">' + i + '</div>';
  });
  body += '</div>';
  document.getElementById('modalBody').innerHTML = body;
  
  if (entry && entry.start) {
    document.getElementById('modalAction').value = 'fix_hours';
    document.getElementById('modalStart').value = entry.start || '';
    document.getElementById('modalEnd').value = entry.end || '';
    document.getElementById('modalCoffeeOut').value = entry.coffee_out || '';
    document.getElementById('modalCoffeeIn').value = entry.coffee_in || '';
    document.getElementById('modalLunchOut').value = entry.lunch_out || '';
    document.getElementById('modalLunchIn').value = entry.lunch_in || '';
  } else {
    document.getElementById('modalAction').value = 'add_missing_day';
    document.getElementById('modalStart').value = '08:00';
    document.getElementById('modalEnd').value = '17:00';
    document.getElementById('modalCoffeeOut').value = '10:30';
    document.getElementById('modalCoffeeIn').value = '10:50';
    document.getElementById('modalLunchOut').value = '13:00';
    document.getElementById('modalLunchIn').value = '14:00';
  }
  
  document.getElementById('detailModal').classList.add('active');
}

function closeDetailModal() {
  document.getElementById('detailModal').classList.remove('active');
}
</script>

<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
