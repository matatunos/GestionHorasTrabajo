<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/db.php';

$user = current_user();
require_login();
$pdo = get_pdo();

// selected year from GET or default current year
$year = intval($_GET['year'] ?? date('Y'));
$config = get_year_config($year);

// filters from GET
$hideWeekends = !empty($_GET['hide_weekends']);
$hideHolidays = !empty($_GET['hide_holidays']);
$hideVacations = !empty($_GET['hide_vacations']);

// handle POST create/update entry for current user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['date'])) {
  $date = $_POST['date'];
  $data = [
    'start' => $_POST['start'] ?: null,
    'coffee_out' => $_POST['coffee_out'] ?: null,
    'coffee_in' => $_POST['coffee_in'] ?: null,
    'lunch_out' => $_POST['lunch_out'] ?: null,
    'lunch_in' => $_POST['lunch_in'] ?: null,
    'end' => $_POST['end'] ?: null,
    'note' => $_POST['note'] ?: '',
  ];
  // upsert by user_id+date
  $stmt = $pdo->prepare('SELECT id FROM entries WHERE user_id = ? AND date = ? LIMIT 1');
  $stmt->execute([$user['id'], $date]);
  $row = $stmt->fetch();
  if ($row){
    $stmt = $pdo->prepare('UPDATE entries SET start=?,coffee_out=?,coffee_in=?,lunch_out=?,lunch_in=?,end=?,note=? WHERE id=?');
    $stmt->execute([$data['start'],$data['coffee_out'],$data['coffee_in'],$data['lunch_out'],$data['lunch_in'],$data['end'],$data['note'],$row['id']]);
  } else {
    $stmt = $pdo->prepare('INSERT INTO entries (user_id,date,start,coffee_out,coffee_in,lunch_out,lunch_in,end,note) VALUES (?,?,?,?,?,?,?,?,?)');
    $stmt->execute([$user['id'],$date,$data['start'],$data['coffee_out'],$data['coffee_in'],$data['lunch_out'],$data['lunch_in'],$data['end'],$data['note']]);
  }
  // if AJAX request, return JSON success instead of redirect
  if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
  }
  header('Location: index.php?year=' . urlencode($year)); exit;
}

// load entries for the year and build a map by date
// load entries and holidays for the year and build maps by date
$stmt = $pdo->prepare('SELECT * FROM entries WHERE user_id = ? AND date BETWEEN ? AND ? ORDER BY date ASC');
$stmt->execute([$user['id'], "$year-01-01", "$year-12-31"]);
$rows = $stmt->fetchAll();
$entries = [];
foreach ($rows as $r) { $entries[$r['date']] = $r; }

// load holidays for year
$holidayMap = [];
  try {
    $hstmt = $pdo->prepare('SELECT date,label,type FROM holidays WHERE year = ?');
    $hstmt->execute([$year]);
    foreach ($hstmt->fetchAll() as $h) {
      $holidayMap[$h['date']] = ['label' => $h['label'], 'type' => $h['type']];
    }
  } catch (Throwable $e) { /* ignore if table missing */ }
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Registro Horas</title>
  <link rel="stylesheet" href="styles.css">
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>
<div class="container">
  <div class="card">
    <h1>Registro de Horas — <?php echo $year; ?></h1>

    <form id="entry-form" method="post" class="row-form" style="gap:8px;align-items:center;">
      <label class="form-label">Fecha <input class="form-control" type="date" name="date" required value="<?php echo date('Y-m-d'); ?>"></label>
      <label class="form-label">Entrada <input class="form-control" type="time" name="start"></label>
      <label class="form-label">Salida café <input class="form-control" type="time" name="coffee_out"></label>
      <label class="form-label">Entrada café <input class="form-control" type="time" name="coffee_in"></label>
      <label class="form-label">Salida comida <input class="form-control" type="time" name="lunch_out"></label>
      <label class="form-label">Entrada comida <input class="form-control" type="time" name="lunch_in"></label>
      <label class="form-label">Hora salida <input class="form-control" type="time" name="end"></label>
      <label class="form-label">Nota <input class="form-control" type="text" name="note" style="min-width:150px"></label>
      <div class="actions"><button class="btn btn-primary" type="submit">Guardar</button></div>
    </form>

    <form id="filters-form" method="get" class="row-form" style="margin-top:10px;gap:12px;align-items:center;">
      <input type="hidden" name="year" value="<?php echo htmlspecialchars($year); ?>">
      <label><input type="checkbox" name="hide_weekends" value="1" <?php echo $hideWeekends ? 'checked' : ''; ?>> Ocultar fines de semana</label>
      <label><input type="checkbox" name="hide_holidays" value="1" <?php echo $hideHolidays ? 'checked' : ''; ?>> Ocultar festivos</label>
      <label><input type="checkbox" name="hide_vacations" value="1" <?php echo $hideVacations ? 'checked' : ''; ?>> Ocultar vacaciones</label>
      <button class="btn" type="submit">Aplicar filtros</button>
      <button id="toggle-all-months" class="btn" type="button">Plegar/Mostrar todo</button>
    </form>

    <div class="table-responsive">
    <table class="sheet compact">
    <thead>
      <tr>
        <th>Fecha</th>
        <th>Entrada</th>
        <th>Salida café</th>
        <th>Entrada café</th>
        <th>Saldo café</th>
        <th>Salida comida</th>
        <th>Entrada comida</th>
        <th>Saldo comida</th>
        <th>Hora salida</th>
        <th>Total h.</th>
        <th>Balance día</th>
        <th>Nota</th>
      </tr>
    </thead>
    <?php
      $currentMonth = null;
      // iterate every day of the year so weekends are shown even when no entry exists
      $startTs = strtotime("$year-01-01");
      $endTs = strtotime("$year-12-31");
      $hideWeekends = !empty($_GET['hide_weekends']);
      for ($ts = $startTs; $ts <= $endTs; $ts += 86400) {
        $d = date('Y-m-d', $ts);
        $month = strftime('%B', $ts);
        $monthKey = date('Y-m', $ts);
        if ($currentMonth !== $month) {
          if ($currentMonth !== null) {
            echo "</tbody>";
          }
          $currentMonth = $month;
          echo "<tbody class=\"month-group\" data-month=\"".$monthKey."\">";
          echo "<tr class=\"month\"><td class=\"month-header\" data-month=\"".$monthKey."\" colspan=12><button class=\"month-toggle\" data-month=\"".$monthKey."\">−</button> ".htmlspecialchars($month)."</td></tr>";
        }
          $dow = (int)date('N', $ts);
          $rowClass = ($dow >= 6) ? 'weekend' : '';
          if ($hideWeekends && $dow >= 6) continue;
          $e = isset($entries[$d]) ? $entries[$d] : ['date' => $d];
          if (isset($holidayMap[$d])) {
            $e['is_holiday'] = true;
            $e['special_type'] = $holidayMap[$d]['type'] ?? 'holiday';
          }
          // apply holiday/vacation filters
          if (isset($holidayMap[$d])) {
            $ht = $holidayMap[$d]['type'] ?? 'holiday';
            if ($hideHolidays && $ht === 'holiday') continue;
            if ($hideVacations && $ht === 'vacation') continue;
          }
          $calc = compute_day($e, $config);
    ?>
      <?php
        $extraClass = '';
        if (isset($holidayMap[$d])) {
            $t = $holidayMap[$d]['type'] ?? 'holiday';
            $extraClass = $t === 'vacation' ? ' vacation' : ($t === 'personal' ? ' personal' : ' holiday');
        }
      ?>
      <tr class="<?php echo $rowClass . $extraClass; ?>">
        <td><?php echo htmlspecialchars($d); ?></td>
        <td><?php echo htmlspecialchars($e['start'] ?? ''); ?></td>
        <td><?php echo htmlspecialchars($e['coffee_out'] ?? ''); ?></td>
        <td><?php echo htmlspecialchars($e['coffee_in'] ?? ''); ?></td>
        <td><?php echo $calc['coffee_balance_formatted']; ?></td>
        <td><?php echo htmlspecialchars($e['lunch_out'] ?? ''); ?></td>
        <td><?php echo htmlspecialchars($e['lunch_in'] ?? ''); ?></td>
        <td><?php echo $calc['lunch_balance_formatted']; ?></td>
        <td><?php echo htmlspecialchars($e['end'] ?? ''); ?></td>
        <td><?php echo $calc['worked_hours_formatted']; ?></td>
        <td><?php echo $calc['day_balance_formatted']; ?></td>
        <td>
          <?php if (isset($holidayMap[$d])): ?>
            <?php $ht = $holidayMap[$d]['type'] ?? 'holiday'; ?>
            <?php $hlabel = htmlspecialchars($holidayMap[$d]['label'] ?? ''); ?>
            <?php if ($ht === 'vacation'): ?>
              <span class="badge badge-primary"><?php echo $hlabel ?: 'Vacaciones'; ?></span>
            <?php elseif ($ht === 'personal'): ?>
              <span class="badge badge-success"><?php echo $hlabel ?: 'Asuntos propios'; ?></span>
            <?php else: ?>
              <span class="badge badge-danger"><?php echo $hlabel ?: 'Festivo'; ?></span>
            <?php endif; ?>
            <?php if (!empty($e['note'])): ?><div class="small mt-1"><?php echo htmlspecialchars($e['note']); ?></div><?php endif; ?>
          <?php else: ?>
            <?php echo htmlspecialchars($e['note'] ?? ''); ?>
          <?php endif; ?>
        </td>
      </tr>
    <?php } // end for each day
      if ($currentMonth !== null) echo "</tbody>";
    ?>
    </table>
    </div>

  </div>
</div>
    <p class="hint">Configuración: <a href="config.php">ver</a></p>
  </div>
</div>
<script>
// AJAX helpers: submit entry via fetch and update table fragment; apply filters without full reload
(function(){
  const filtersForm = document.getElementById('filters-form');
  const entryForm = document.getElementById('entry-form');
  const tableContainerSelector = '.table-responsive';
  function fetchTable(){
    let qs = '';
    try { qs = filtersForm ? new URLSearchParams(new FormData(filtersForm)).toString() : ''; } catch(e){ qs = ''; }
    fetch(location.pathname + (qs ? ('?' + qs) : ''))
      .then(r => r.text())
      .then(html => {
        const tmp = document.createElement('div'); tmp.innerHTML = html;
        const newTable = tmp.querySelector(tableContainerSelector);
        const cur = document.querySelector(tableContainerSelector);
        if (newTable && cur) cur.innerHTML = newTable.innerHTML;
      }).catch(err=>{ console.error('fetchTable error', err); });
  }

  if (filtersForm){
    filtersForm.addEventListener('submit', function(e){
      e.preventDefault(); fetchTable();
      const qs = new URLSearchParams(new FormData(filtersForm)).toString();
      history.replaceState(null, '', location.pathname + (qs ? ('?' + qs) : ''));
    });
  }

  if (entryForm){
    entryForm.addEventListener('submit', function(e){
      e.preventDefault();
      const fd = new FormData(entryForm);
      fetch(location.pathname + location.search, {
        method: 'POST', body: fd, headers: {'X-Requested-With':'XMLHttpRequest'}
      }).then(r => r.text()).then(text => {
        try { const json = JSON.parse(text);
          if (json && json.ok){
            fetchTable();
            const btn = entryForm.querySelector('button[type=submit]');
            if (btn){ btn.textContent = 'Guardado'; setTimeout(()=> btn.textContent = 'Guardar', 1200); }
          } else { console.warn('save returned', json); alert('Error guardando entrada'); }
        } catch(e) { console.warn('Non-JSON response', text); alert('Respuesta inesperada al guardar'); }
      }).catch(err=>{ console.error(err); alert('Error de red'); });
    });
  }

  // Collapsible months: clickable month header cells, toggle-all button, remember state in localStorage
  (function(){
    const containerSelector = tableContainerSelector;
    const debugEl = (function(){ try { const el = document.getElementById('month-debug'); if (el){ el.style.display='block'; el.textContent = 'month-js: loaded'; return el; } } catch(e){} return null; })();
    function toggleMonthRowsFromHeaderTd(headerTd, collapse){
      const tbody = closestAncestorBySelector(headerTd, 'tbody.month-group');
      if (!tbody) return;
      tbody.classList.toggle('collapsed', collapse);
      if (headerTd) headerTd.classList.toggle('collapsed', collapse);
      if (debugEl) debugEl.textContent = 'month-js: toggled ' + (tbody.getAttribute('data-month')||'') + (collapse? ' collapsed' : ' expanded');
    }
    function setHeaderStateFromTd(headerTd, collapsed){
      const key = headerTd.getAttribute('data-month');
      const btn = headerTd.querySelector('.month-toggle');
      if (btn){ btn.setAttribute('data-collapsed', collapsed ? '1' : '0'); btn.textContent = collapsed ? '+' : '−'; }
      try { localStorage.setItem('month_collapsed_'+key, collapsed ? '1' : '0'); } catch(e){}
    }
    function initHeaders(root){
      root.querySelectorAll('tbody.month-group').forEach(function(tbody){
        const key = tbody.getAttribute('data-month');
        const td = tbody.querySelector('td.month-header');
        const btn = td ? td.querySelector('.month-toggle') : null;
        try {
          const st = localStorage.getItem('month_collapsed_'+key);
          const collapsed = st === '1';
          if (btn){ btn.setAttribute('data-collapsed', collapsed ? '1' : '0'); btn.textContent = collapsed ? '+' : '−'; }
          tbody.classList.toggle('collapsed', collapsed);
          if (td) td.classList.toggle('collapsed', collapsed);
        } catch(e){}
      });
    }

    const toggling = new Set();
    function closestAncestorBySelector(el, selector){
      if (!el) return null;
      if (el.closest) return el.closest(selector);
      while(el){ if (el.matches && el.matches(selector)) return el; el = el.parentElement; } return null;
    }
    document.addEventListener('click', function(e){
      const td = closestAncestorBySelector(e.target, 'td.month-header');
      if (!td) return;
      if (closestAncestorBySelector(e.target, 'a, input, select, textarea, button')) return;
      if (debugEl) debugEl.textContent = 'month-js: header clicked ' + (td.getAttribute('data-month')||'');
      const key = td.getAttribute('data-month') || '__nomonth__';
      if (toggling.has(key)) { if (debugEl) debugEl.textContent = 'month-js: reentrant ' + key; console.warn('toggle in progress for', key); return; }
      toggling.add(key);
      try {
        const btn = td.querySelector('.month-toggle');
        const collapsed = btn && btn.getAttribute('data-collapsed') === '1';
        // perform toggle in next animation frame to avoid jank
        requestAnimationFrame(function(){
          try {
            setHeaderStateFromTd(td, !collapsed);
            toggleMonthRowsFromHeaderTd(td, !collapsed);
          } catch(err){ console.error('toggle error', err); if (debugEl) debugEl.textContent = 'month-js: toggle error'; }
          toggling.delete(key);
        });
      } catch(err){ console.error('click handler error', err); if (debugEl) debugEl.textContent = 'month-js: click error'; toggling.delete(key); }
    }, false);

    const toggleAllBtn = document.getElementById('toggle-all-months');
    function setAll(collapsed){
      const container = document.querySelector(containerSelector);
      if (!container) return;
      container.querySelectorAll('tbody.month-group').forEach(function(tbody){
        const td = tbody.querySelector('td.month-header');
        if (td) setHeaderStateFromTd(td, collapsed);
        tbody.querySelectorAll('tr').forEach(function(tr){ if (tr.classList.contains('month')) return; tr.style.display = collapsed ? 'none' : ''; });
      });
      try { localStorage.setItem('months_all_collapsed', collapsed ? '1' : '0'); } catch(e){}
      if (toggleAllBtn) toggleAllBtn.textContent = collapsed ? 'Mostrar todo' : 'Plegar todo';
    }
    if (toggleAllBtn){
      toggleAllBtn.addEventListener('click', function(){ 
        const st = localStorage.getItem('months_all_collapsed') === '1';
        setAll(!st);
      });
    }

    const container = document.querySelector(containerSelector);
    if (container) initHeaders(container);
    try {
      const allSt = localStorage.getItem('months_all_collapsed');
      if (allSt === '1') setAll(true);
    } catch(e){}

    let initScheduled = false;
    const mo = new MutationObserver(function(muts){
      if (initScheduled) return;
      initScheduled = true;
      setTimeout(function(){
        initScheduled = false;
        const c = document.querySelector(containerSelector);
        if (c) initHeaders(c);
      }, 60);
    });
    if (container) mo.observe(container, {childList:true, subtree:true});
  })();

})();
</script>

<?php include __DIR__ . '/footer.php'; ?>

<!-- Debug indicator for month toggles (only visual, safe to remove) -->
<div id="month-debug" aria-hidden="true" style="display:none;" ></div>
