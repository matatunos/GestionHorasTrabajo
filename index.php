<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/db.php';

$user = current_user();
require_login();
$pdo = get_pdo();

// selected year from GET or default current year
$year = intval($_GET['year'] ?? date('Y'));
$config = get_year_config($year, $user['id']);

// filters from GET
$hideWeekends = !empty($_GET['hide_weekends']);
$hideHolidays = !empty($_GET['hide_holidays']);
$hideVacations = !empty($_GET['hide_vacations']);

// handle POST create/update entry for current user
// handle inline delete via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_date'])) {
  $delDate = $_POST['delete_date'];
  try {
    $stmt = $pdo->prepare('DELETE FROM entries WHERE user_id = ? AND date = ?');
    $stmt->execute([$user['id'], $delDate]);
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
      header('Content-Type: application/json'); echo json_encode(['ok' => true]); exit;
    }
    header('Location: index.php?year=' . urlencode($year)); exit;
  } catch (Throwable $e) {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
      header('Content-Type: application/json'); echo json_encode(['ok' => false, 'error' => 'delete_failed']); exit;
    }
    // fallthrough to render page with error (not ideal)
  }
}

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
    $hstmt = $pdo->prepare('SELECT date,label,type,annual,user_id FROM holidays WHERE (YEAR(date) = ? OR annual = 1) AND (user_id IS NULL OR user_id = ?)');
    $hstmt->execute([$year, $user['id']]);
    foreach ($hstmt->fetchAll() as $h) {
      // if this is an annual holiday stored with original year, map it to the selected year for display
      $keyDate = $h['date'];
      if (!empty($h['annual'])) {
        $keyDate = sprintf('%04d-%s', $year, substr($h['date'],5));
      }
      $holidayMap[$keyDate] = ['label' => $h['label'], 'type' => $h['type']];
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
      <label class="form-label">Entrada <input class="form-control" type="text" name="start"></label>
      <label class="form-label">Salida café <input class="form-control" type="text" name="coffee_out"></label>
      <label class="form-label">Entrada café <input class="form-control" type="text" name="coffee_in"></label>
      <label class="form-label">Salida comida <input class="form-control" type="text" name="lunch_out"></label>
      <label class="form-label">Entrada comida <input class="form-control" type="text" name="lunch_in"></label>
      <label class="form-label">Hora salida <input class="form-control" type="text" name="end"></label>
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
        <th>Acciones</th>
      </tr>
    </thead>
    <?php
      $currentMonth = null;
      // iterate every day of the year so weekends are shown even when no entry exists
      $hideWeekends = !empty($_GET['hide_weekends']);
      $dt = new DateTimeImmutable("$year-01-01");
      $end = new DateTimeImmutable("$year-12-31");
      for ($cur = $dt; $cur <= $end; $cur = $cur->modify('+1 day')) {
        $d = $cur->format('Y-m-d');
        $month = strftime('%B', $cur->getTimestamp());
        $monthKey = $cur->format('Y-m');
        if ($currentMonth !== $month) {
          if ($currentMonth !== null) {
            echo "</tbody>";
          }
          $currentMonth = $month;
          echo "<tbody class=\"month-group\" data-month=\"".$monthKey."\">";
          echo "<tr class=\"month\"><td class=\"month-header\" data-month=\"".$monthKey."\" colspan=13><button class=\"month-toggle\" data-month=\"".$monthKey."\">−</button> ".htmlspecialchars($month)."</td></tr>";
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
            $extraClass = $t === 'vacation' ? ' vacation' : ($t === 'personal' ? ' personal' : ($t === 'enfermedad' ? ' illness' : ($t === 'permiso' ? ' permiso' : ' holiday')));
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
            <?php elseif ($ht === 'enfermedad'): ?>
              <span class="badge badge-warning"><?php echo $hlabel ?: 'Enfermedad'; ?></span>
            <?php elseif ($ht === 'permiso'): ?>
              <span class="badge badge-info"><?php echo $hlabel ?: 'Permiso'; ?></span>
            <?php else: ?>
              <span class="badge badge-danger"><?php echo $hlabel ?: 'Festivo'; ?></span>
            <?php endif; ?>
            <?php if (!empty($e['note'])): ?><div class="small mt-1"><?php echo htmlspecialchars($e['note']); ?></div><?php endif; ?>
          <?php else: ?>
            <?php echo htmlspecialchars($e['note'] ?? ''); ?>
          <?php endif; ?>
        </td>
        <td>
          <button class="btn btn-secondary edit-entry icon-btn" type="button" title="Editar" data-date="<?php echo $d; ?>" data-start="<?php echo htmlspecialchars($e['start'] ?? ''); ?>" data-coffee_out="<?php echo htmlspecialchars($e['coffee_out'] ?? ''); ?>" data-coffee_in="<?php echo htmlspecialchars($e['coffee_in'] ?? ''); ?>" data-lunch_out="<?php echo htmlspecialchars($e['lunch_out'] ?? ''); ?>" data-lunch_in="<?php echo htmlspecialchars($e['lunch_in'] ?? ''); ?>" data-end="<?php echo htmlspecialchars($e['end'] ?? ''); ?>" data-note="<?php echo htmlspecialchars($e['note'] ?? ''); ?>">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04a1.003 1.003 0 0 0 0-1.41l-2.34-2.34a1.003 1.003 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z" fill="currentColor"/></svg>
          </button>
          <button class="btn btn-danger delete-entry icon-btn" type="button" title="Borrar" data-date="<?php echo $d; ?>" onclick="(function(e){ e.stopPropagation(); handleDeleteDate('<?php echo $d; ?>'); })(event)">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M6 19a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z" fill="currentColor"/></svg>
          </button>
        </td>
      </tr>
    <?php } // end for each day
      if ($currentMonth !== null) echo "</tbody>";
    ?>
    </table>
    </div>

  </div>
</div>
    <!-- configuración link removed per UI decision -->
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

  // row actions: inline edit / delete (robust handler that works with clicks inside SVGs)
  // helper for inline delete invoked from button onclick; stops propagation in the inline handler
  window.handleDeleteDate = function(d){
    if (!d) return;
    if (!confirm('Confirma borrar la entrada de ' + d + '?')) return;
    const fd = new FormData(); fd.append('delete_date', d);
    fetch(location.pathname + location.search, { method: 'POST', body: fd, headers: {'X-Requested-With':'XMLHttpRequest'} })
      .then(r => r.json()).then(j => { if (j && j.ok) fetchTable(); else alert('Error borrando entrada'); })
      .catch(err => { console.error(err); alert('Error de red al borrar'); });
  };
  document.addEventListener('click', function(e){
    const target = e.target;
    const closestEl = function(el, sel){
      while(el){
        try { if (el.matches && el.matches(sel)) return el; } catch(err) {}
        el = el.parentNode;
      }
      return null;
    };

    // Edit
    const editBtn = closestEl(target, '.edit-entry');
    if (editBtn){
      const tr = closestEl(editBtn, 'tr');
      if (!tr || tr.classList.contains('editing')) return;
      tr.classList.add('editing');
      tr.dataset._orig = tr.innerHTML;
      const tds = tr.querySelectorAll('td');
      const date = editBtn.getAttribute('data-date') || '';
      function mkInput(type, value, name){
        if (type === 'time') return `<input class="form-control" type="text" name="${name}" value="${value}">`;
        return `<input class="form-control" type="text" name="${name}" value="${value}">`;
      }
      tds[1].innerHTML = mkInput('time', editBtn.getAttribute('data-start') || '', 'start');
      tds[2].innerHTML = mkInput('time', editBtn.getAttribute('data-coffee_out') || '', 'coffee_out');
      tds[3].innerHTML = mkInput('time', editBtn.getAttribute('data-coffee_in') || '', 'coffee_in');
      tds[5].innerHTML = mkInput('time', editBtn.getAttribute('data-lunch_out') || '', 'lunch_out');
      tds[6].innerHTML = mkInput('time', editBtn.getAttribute('data-lunch_in') || '', 'lunch_in');
      tds[8].innerHTML = mkInput('time', editBtn.getAttribute('data-end') || '', 'end');
      tds[11].innerHTML = mkInput('text', editBtn.getAttribute('data-note') || '', 'note');
      tds[12].innerHTML = '<button class="btn btn-primary save-entry" type="button">Guardar</button> <button class="btn btn-secondary cancel-entry" type="button">Cancelar</button>';
      tr.dataset._date = date;
      return;
    }

    // Save
    const saveBtn = closestEl(target, '.save-entry');
    if (saveBtn){
      const tr = closestEl(saveBtn, 'tr');
      if (!tr) return;
      const tds = tr.querySelectorAll('td');
      const date = tr.dataset._date || '';
      const fd = new FormData(); fd.append('date', date);
      const getVal = (idx, name) => { const inp = tds[idx].querySelector('input[name="' + name + '"]'); return inp ? inp.value : ''; };
      fd.append('start', getVal(1,'start'));
      fd.append('coffee_out', getVal(2,'coffee_out'));
      fd.append('coffee_in', getVal(3,'coffee_in'));
      fd.append('lunch_out', getVal(5,'lunch_out'));
      fd.append('lunch_in', getVal(6,'lunch_in'));
      fd.append('end', getVal(8,'end'));
      fd.append('note', getVal(11,'note'));
      fetch(location.pathname + location.search, { method: 'POST', body: fd, headers: {'X-Requested-With':'XMLHttpRequest'} })
        .then(r => r.json()).then(j => { if (j && j.ok){ tr.classList.remove('editing'); fetchTable(); } else { alert('Error guardando'); } })
        .catch(err => { console.error(err); alert('Error de red'); });
      return;
    }

    // Cancel
    const cancelBtn = closestEl(target, '.cancel-entry');
    if (cancelBtn){
      const tr = closestEl(cancelBtn, 'tr');
      if (!tr) return;
      if (tr.dataset._orig) tr.innerHTML = tr.dataset._orig;
      tr.classList.remove('editing');
      delete tr.dataset._orig; delete tr.dataset._date;
      return;
    }

    // Delete
    const delBtn = closestEl(target, '.delete-entry');
    if (delBtn){
      const d = delBtn.getAttribute('data-date');
      if (!d) return;
      if (!confirm('Confirma borrar la entrada de ' + d + '?')) return;
      const fd = new FormData(); fd.append('delete_date', d);
      fetch(location.pathname + location.search, { method: 'POST', body: fd, headers: {'X-Requested-With':'XMLHttpRequest'} })
        .then(r => r.json()).then(j => { if (j && j.ok) fetchTable(); else alert('Error borrando entrada'); })
        .catch(err => { console.error(err); alert('Error de red al borrar'); });
      return;
    }

    // More menu
    const moreBtn = closestEl(target, '.more-entry') || closestEl(target, 'button.more');
    if (moreBtn){
      document.querySelectorAll('.more-menu').forEach(m=>m.remove());
      const rect = moreBtn.getBoundingClientRect();
      const menu = document.createElement('div'); menu.className = 'more-menu';
      const itemEdit = document.createElement('div'); itemEdit.className = 'more-item'; itemEdit.textContent = 'Editar';
      const itemDelete = document.createElement('div'); itemDelete.className = 'more-item'; itemDelete.textContent = 'Borrar';
      menu.appendChild(itemEdit); menu.appendChild(itemDelete);
      document.body.appendChild(menu);
      menu.style.left = (rect.left + window.scrollX) + 'px';
      menu.style.top = (rect.bottom + window.scrollY + 6) + 'px';
      itemEdit.addEventListener('click', function(){ const tr = moreBtn.closest('tr'); if (tr) { const eb = tr.querySelector('.edit-entry'); if (eb) eb.click(); } menu.remove(); });
      itemDelete.addEventListener('click', function(){ const tr = moreBtn.closest('tr'); if (tr) { const db = tr.querySelector('.delete-entry'); if (db) db.click(); } menu.remove(); });
      setTimeout(()=>{ document.addEventListener('click', function _closer(ev){ if (!menu.contains(ev.target) && ev.target !== moreBtn){ menu.remove(); document.removeEventListener('click', _closer); } }); }, 10);
      return;
    }
  });

})();
</script>

<?php include __DIR__ . '/footer.php'; ?>

<!-- Debug indicator for month toggles (only visual, safe to remove) -->
<div id="month-debug" aria-hidden="true" style="display:none;" ></div>
