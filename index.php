<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/db.php';

$user = current_user();
require_login();
$pdo = get_pdo();

// selected year from GET or default current year
$year = intval($_GET['year'] ?? date('Y'));
$initialDate = $_GET['date'] ?? date('Y-m-d');
if (!is_string($initialDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $initialDate)) {
  $initialDate = date('Y-m-d');
}
$openAdd = !empty($_GET['open_add']);
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
    'absence_type' => $_POST['absence_type'] ?: null,
  ];
  // upsert by user_id+date
  $stmt = $pdo->prepare('SELECT id FROM entries WHERE user_id = ? AND date = ? LIMIT 1');
  $stmt->execute([$user['id'], $date]);
  $row = $stmt->fetch();
  if ($row){
    $stmt = $pdo->prepare('UPDATE entries SET start=?,coffee_out=?,coffee_in=?,lunch_out=?,lunch_in=?,end=?,note=?,absence_type=? WHERE id=?');
    $stmt->execute([$data['start'],$data['coffee_out'],$data['coffee_in'],$data['lunch_out'],$data['lunch_in'],$data['end'],$data['note'],$data['absence_type'],$row['id']]);
  } else {
    $stmt = $pdo->prepare('INSERT INTO entries (user_id,date,start,coffee_out,coffee_in,lunch_out,lunch_in,end,note,absence_type) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([$user['id'],$date,$data['start'],$data['coffee_out'],$data['coffee_in'],$data['lunch_out'],$data['lunch_in'],$data['end'],$data['note'],$data['absence_type']]);
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
<body class="page-index">
<?php $hidePageHeader = true; include __DIR__ . '/header.php'; ?>
<div class="container">
  <div class="card">
    <!-- Controles globales: selector de fecha, selector de año y ocultador de fines de semana -->
    <div id="global-controls" style="display:flex;gap:12px;align-items:center;margin-bottom:10px;">
      <label class="form-label">Fecha global <input id="global-date" class="form-control" type="date" value="<?php echo htmlspecialchars($initialDate); ?>"></label>
      <label class="form-label">Año <select id="entry-year" class="form-control">
        <?php
          // Años disponibles para este usuario (solo entries)
          $years = [];
          try {
            $ystmt = $pdo->prepare('SELECT DISTINCT YEAR(date) AS y FROM entries WHERE user_id = ? AND date IS NOT NULL ORDER BY y DESC');
            $ystmt->execute([$user['id']]);
            foreach ($ystmt->fetchAll() as $r) { if (!empty($r['y'])) $years[] = intval($r['y']); }
          } catch (Throwable $e) { /* ignore */ }
          $years = array_values(array_unique(array_filter($years)));
          rsort($years);
          if (empty($years)) $years = [intval(date('Y'))];
          if (!in_array(intval($year), $years, true)) array_unshift($years, intval($year));
          foreach ($years as $y){
            $sel = ($y === intval($year)) ? ' selected' : '';
            echo "<option value=\"$y\"$sel>$y</option>";
          }
        ?>
      </select></label>
      <label class="form-check form-label"><input id="global-hide-weekends" type="checkbox" <?php echo $hideWeekends ? 'checked' : ''; ?>><span>Ocultar fines de semana</span></label>
    </div>

    <div style="display:flex;gap:8px;align-items:center;margin-bottom:10px;">
      <button class="btn btn-primary" type="button" id="openAddEntryBtn">Añadir</button>
    </div>

    <!-- Modal for adding a work entry (mirrors settings.php behavior) -->
    <div id="entryModalOverlay" class="modal-overlay" aria-hidden="true" style="display:none;">
      <div id="entryModal" class="modal-dialog entry-modal" role="dialog" aria-modal="true">
        <div class="modal-header">
          <h3 class="modal-title">Añadir fichaje</h3>
        </div>
        <div class="modal-body">
        <form id="entry-form" method="post" class="row-form entry-form">
          <label class="form-label">Fecha <input id="entry-date" class="form-control" type="date" name="date" required value="<?php echo htmlspecialchars($initialDate); ?>"></label>
          <label class="form-label">Tipo de día <select class="form-control" id="entry-absence-type" name="absence_type" onchange="document.getElementById('entry-times').style.display = this.value ? 'none' : 'block';">
            <option value="">Día normal (con fichaje)</option>
            <option value="vacation">Vacaciones</option>
            <option value="illness">Enfermedad</option>
            <option value="permit">Permiso</option>
            <option value="other">Otro (especificar)</option>
          </select></label>
          <div id="entry-times">
            <label class="form-label">Entrada <input class="form-control time-input" type="text" name="start"></label>
            <label class="form-label">Salida café <input class="form-control time-input" type="text" name="coffee_out"></label>
            <label class="form-label">Entrada café <input class="form-control time-input" type="text" name="coffee_in"></label>
            <label class="form-label">Salida comida <input class="form-control time-input" type="text" name="lunch_out"></label>
            <label class="form-label">Entrada comida <input class="form-control time-input" type="text" name="lunch_in"></label>
            <label class="form-label">Hora salida <input class="form-control time-input" type="text" name="end"></label>
          </div>
          <label class="form-label">Nota <input class="form-control" type="text" name="note"></label>
          <div class="form-actions modal-actions mt-2">
            <button class="btn btn-secondary" type="button" id="closeEntryModal">Cancelar</button>
            <button class="btn btn-primary" type="submit">Guardar</button>
          </div>
        </form>
        </div>
      </div>
    </div>

    <form id="filters-form" method="get" class="row-form mt-2">
      <!-- keep server-side checkbox in sync with global control (hidden to avoid duplicate UI) -->
      <label style="display:none;"><input type="checkbox" name="hide_weekends" value="1" <?php echo $hideWeekends ? 'checked' : ''; ?>> Ocultar fines de semana</label>
      <label class="form-check"><input type="checkbox" name="hide_holidays" value="1" <?php echo $hideHolidays ? 'checked' : ''; ?>><span>Ocultar festivos</span></label>
      <label class="form-check"><input type="checkbox" name="hide_vacations" value="1" <?php echo $hideVacations ? 'checked' : ''; ?>><span>Ocultar vacaciones</span></label>
      <button class="btn" type="submit">Aplicar filtros</button>
      <button id="toggle-all-months" class="btn" type="button">Plegar/Mostrar todo</button>
      <button class="btn btn-secondary" id="export-csv-btn" type="button">📥 Descargar CSV</button>
    </form>

    <div class="table-responsive">
    <table class="sheet compact">
    <?php
      $currentMonth = null;
      $monthStats = null;
      $monthNameForStats = null;
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
            // month summary row
            if (is_array($monthStats)) {
              $mExp = intval($monthStats['expected_minutes'] ?? 0);
              $mWork = intval($monthStats['worked_minutes'] ?? 0);
              $mBal = $mWork - $mExp;
              $mBalClass = ($mBal > 0) ? 'balance--good' : (($mBal < 0) ? 'balance--bad' : 'balance--ok');

              $dietas = intval($monthStats['dietas'] ?? 0);
              $coffeeExCount = intval($monthStats['coffee_excess_days'] ?? 0);
              $coffeeExAvg = ($coffeeExCount > 0) ? intdiv(intval($monthStats['coffee_excess_total'] ?? 0), $coffeeExCount) : 0;
              $missing = intval($monthStats['missing_workdays'] ?? 0);
              $workdays = intval($monthStats['workdays'] ?? 0);

              echo '<tr class="month-summary">';
              echo '<td colspan="13">';
              echo '<div class="month-summary-row">';
              echo '<span class="month-summary-title">Resumen '.htmlspecialchars($monthNameForStats ?? $currentMonth).'</span>';
              echo '<span class="pill '.$mBalClass.'"><span class="pill-icon" aria-hidden="true">'.(($mBal>0)?'↑':(($mBal<0)?'↓':'•')).'</span><span class="pill-value">Balance '.htmlspecialchars(minutes_to_hours_formatted($mBal)).'</span></span>';
              echo '<span class="pill balance--ok"><span class="pill-icon" aria-hidden="true">⏱</span><span class="pill-value">Esperadas '.htmlspecialchars(minutes_to_hours_formatted($mExp)).'</span></span>';
              echo '<span class="pill balance--ok"><span class="pill-icon" aria-hidden="true">✓</span><span class="pill-value">Hechas '.htmlspecialchars(minutes_to_hours_formatted($mWork)).'</span></span>';
              echo '<span class="pill balance--ok"><span class="pill-icon" aria-hidden="true">🍽</span><span class="pill-value">Dietas '.$dietas.'</span></span>';
              if ($coffeeExCount > 0) {
                echo '<span class="pill balance--bad"><span class="pill-icon" aria-hidden="true">☕</span><span class="pill-value">Café exceso medio '.htmlspecialchars(minutes_to_hours_formatted($coffeeExAvg)).'</span></span>';
              } else {
                echo '<span class="pill balance--missing"><span class="pill-icon" aria-hidden="true">☕</span><span class="pill-value">Café exceso medio —</span></span>';
              }
              echo '<span class="month-summary-meta">Días con datos '.intval($monthStats['days_with_worked'] ?? 0).'/'.$workdays.($missing>0 ? ' · Incompletos '.$missing : '').'</span>';
              echo '</div>';
              echo '</td>';
              echo '</tr>';
            }

            echo "</tbody>";
          }
          $currentMonth = $month;
          $monthNameForStats = $month;
          $monthStats = [
            'expected_minutes' => 0,
            'worked_minutes' => 0,
            'workdays' => 0,
            'days_with_worked' => 0,
            'missing_workdays' => 0,
            'dietas' => 0,
            'coffee_excess_total' => 0,
            'coffee_excess_days' => 0,
          ];
          echo "<tbody class=\"month-group\" data-month=\"".$monthKey."\">";
          echo "<tr class=\"month\"><td class=\"month-header\" data-month=\"".$monthKey."\" colspan=13><button class=\"month-toggle\" data-month=\"".$monthKey."\">−</button> ".htmlspecialchars($month)."</td></tr>";
          // insert a header row at the top of each month for quick reference
          echo "<tr class=\"month-columns\">";
          echo "<th>Fecha</th>";
          echo "<th>Entrada</th>";
          echo "<th>Salida café</th>";
          echo "<th>Entrada café</th>";
          echo "<th>Saldo café</th>";
          echo "<th>Salida comida</th>";
          echo "<th>Entrada comida</th>";
          echo "<th>Saldo comida</th>";
          echo "<th>Hora salida</th>";
          echo "<th>Total h.</th>";
          echo "<th>Balance día</th>";
          echo "<th>Nota</th>";
          echo "<th>Acciones</th>";
          echo "</tr>";
        }
          // get day-of-week from current DateTimeImmutable
          $dow = (int)$cur->format('N');
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

              // Avoid showing "No café / Sin comida / Sin dietas" labels on non-working days
              // (weekends, holidays, vacations, etc.). If data is missing there, keep it neutral.
              $isNonWorkingDay = ($dow >= 6) || isset($holidayMap[$d]);

              $coffeeBal = $calc['coffee_balance'] ?? null;
              $coffeeCellClass = ($coffeeBal === null) ? ' balance--missing' : (($coffeeBal > 0) ? ' balance--bad' : (($coffeeBal < 0) ? ' balance--good' : ' balance--ok'));
              $lunchBal = $calc['lunch_balance'] ?? null;
              $lunchCellClass = ($lunchBal === null) ? ' balance--missing' : (($lunchBal > 0) ? ' balance--bad' : (($lunchBal < 0) ? ' balance--good' : ' balance--ok'));

              // Daily balance: positive is good (exceso), negative is bad (defecto)
              $dayBal = $calc['day_balance'] ?? null;
              $dayCellClass = ($dayBal === null) ? ' balance--missing' : (($dayBal > 0) ? ' balance--good' : (($dayBal < 0) ? ' balance--bad' : ' balance--ok'));

              // Aggregate per month (for summary row)
              if (is_array($monthStats)) {
                $exp = intval($calc['expected_minutes'] ?? 0);
                if ($exp > 0) {
                  $monthStats['expected_minutes'] += $exp;
                  $monthStats['workdays'] += 1;
                  if ($calc['worked_minutes'] === null) {
                    $monthStats['missing_workdays'] += 1;
                  }
                }
                if ($calc['worked_minutes'] !== null) {
                  $monthStats['worked_minutes'] += intval($calc['worked_minutes']);
                  $monthStats['days_with_worked'] += 1;
                }
                // Keep definition consistent with dashboard: lunch_balance >= 60
                $lb = $calc['lunch_balance'] ?? null;
                if ($lb !== null && intval($lb) >= 60) $monthStats['dietas'] += 1;

                $cb = $calc['coffee_balance'] ?? null;
                if ($cb !== null && intval($cb) > 0) {
                  $monthStats['coffee_excess_total'] += intval($cb);
                  $monthStats['coffee_excess_days'] += 1;
                }
              }
    ?>
      <?php
        $extraClass = '';
        if (isset($holidayMap[$d])) {
            $t = $holidayMap[$d]['type'] ?? 'holiday';
            $extraClass = $t === 'vacation' ? ' vacation' : ($t === 'personal' ? ' personal' : ($t === 'enfermedad' ? ' illness' : ($t === 'permiso' ? ' permiso' : ' holiday')));
        }
      ?>
      <tr class="<?php echo $rowClass . $extraClass; ?>">
        <?php
          $dateLabel = htmlspecialchars($d);
          $isWeekend = ($dow >= 6);
          if ($dow === 6) $dateLabel = 'Sabado';
          elseif ($dow === 7) $dateLabel = 'Domingo';
        ?>
        <td class="date-cell<?php echo $isWeekend ? ' center' : ''; ?>"><?php echo $dateLabel; ?></td>
        <td><?php echo htmlspecialchars($e['start'] ?? ''); ?></td>
        <td><?php echo htmlspecialchars($e['coffee_out'] ?? ''); ?></td>
        <td><?php echo htmlspecialchars($e['coffee_in'] ?? ''); ?></td>
        <td class="balance-cell<?php echo $coffeeCellClass; ?>">
          <?php if (($calc['coffee_taken'] ?? false) === false): ?>
            <?php if ($isNonWorkingDay): ?>
              <span class="muted">—</span>
            <?php else: ?>
              <span class="muted">No café</span>
            <?php endif; ?>
          <?php else: ?>
            <?php echo $calc['coffee_balance_formatted']; ?>
          <?php endif; ?>
        </td>
        <td><?php echo htmlspecialchars($e['lunch_out'] ?? ''); ?></td>
        <td><?php echo htmlspecialchars($e['lunch_in'] ?? ''); ?></td>
        <td class="balance-cell<?php echo $lunchCellClass; ?>">
          <?php if (($calc['lunch_taken'] ?? false) === false): ?>
            <?php if ($isNonWorkingDay): ?>
              <span class="muted">—</span>
            <?php else: ?>
              <span class="muted">Sin comida</span>
              <div class="muted">Sin dietas</div>
            <?php endif; ?>
          <?php else: ?>
            <?php echo $calc['lunch_balance_formatted']; ?>
          <?php endif; ?>
        </td>
        <td><?php echo htmlspecialchars($e['end'] ?? ''); ?></td>
        <td>
          <?php if ($calc['worked_minutes'] === null): ?>
            <span class="muted">—</span>
          <?php else: ?>
            <?php echo $calc['worked_hours_formatted']; ?>
          <?php endif; ?>
        </td>
        <td class="balance-cell<?php echo $dayCellClass; ?>">
          <?php if ($calc['day_balance'] === null): ?>
            <span class="muted">—</span>
          <?php else: ?>
            <?php $pillClass = trim($dayCellClass); $db = intval($calc['day_balance']); ?>
            <span class="pill <?php echo htmlspecialchars($pillClass); ?>">
              <span class="pill-icon" aria-hidden="true"><?php echo ($db > 0) ? '↑' : (($db < 0) ? '↓' : '•'); ?></span>
              <span class="pill-value"><?php echo $calc['day_balance_formatted']; ?></span>
            </span>
          <?php endif; ?>
        </td>
        <td>
          <?php if (!empty($e['absence_type'])): ?>
            <?php $absenceLabels = ['vacation' => '🏖️ Vacaciones', 'illness' => '🤒 Enfermedad', 'permit' => '📋 Permiso', 'other' => '📌 Otro']; ?>
            <span class="badge badge-primary"><?php echo htmlspecialchars($absenceLabels[$e['absence_type']] ?? $e['absence_type']); ?></span>
          <?php elseif (isset($holidayMap[$d])): ?>
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
          <button class="btn btn-secondary edit-entry icon-btn" type="button" title="Editar" data-date="<?php echo $d; ?>" data-start="<?php echo htmlspecialchars($e['start'] ?? ''); ?>" data-coffee_out="<?php echo htmlspecialchars($e['coffee_out'] ?? ''); ?>" data-coffee_in="<?php echo htmlspecialchars($e['coffee_in'] ?? ''); ?>" data-lunch_out="<?php echo htmlspecialchars($e['lunch_out'] ?? ''); ?>" data-lunch_in="<?php echo htmlspecialchars($e['lunch_in'] ?? ''); ?>" data-end="<?php echo htmlspecialchars($e['end'] ?? ''); ?>" data-note="<?php echo htmlspecialchars($e['note'] ?? ''); ?>" data-absence_type="<?php echo htmlspecialchars($e['absence_type'] ?? ''); ?>">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04a1.003 1.003 0 0 0 0-1.41l-2.34-2.34a1.003 1.003 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z" fill="currentColor"/></svg>
          </button>
          <button class="btn btn-danger delete-entry icon-btn" type="button" title="Borrar" data-date="<?php echo $d; ?>" onclick="(function(e){ e.stopPropagation(); handleDeleteDate('<?php echo $d; ?>'); })(event)">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M6 19a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z" fill="currentColor"/></svg>
          </button>
        </td>
      </tr>
    <?php } // end for each day
      if ($currentMonth !== null) {
        // final month summary row
        if (is_array($monthStats)) {
          $mExp = intval($monthStats['expected_minutes'] ?? 0);
          $mWork = intval($monthStats['worked_minutes'] ?? 0);
          $mBal = $mWork - $mExp;
          $mBalClass = ($mBal > 0) ? 'balance--good' : (($mBal < 0) ? 'balance--bad' : 'balance--ok');

          $dietas = intval($monthStats['dietas'] ?? 0);
          $coffeeExCount = intval($monthStats['coffee_excess_days'] ?? 0);
          $coffeeExAvg = ($coffeeExCount > 0) ? intdiv(intval($monthStats['coffee_excess_total'] ?? 0), $coffeeExCount) : 0;
          $missing = intval($monthStats['missing_workdays'] ?? 0);
          $workdays = intval($monthStats['workdays'] ?? 0);

          echo '<tr class="month-summary">';
          echo '<td colspan="13">';
          echo '<div class="month-summary-row">';
          echo '<span class="month-summary-title">Resumen '.htmlspecialchars($monthNameForStats ?? $currentMonth).'</span>';
          echo '<span class="pill '.$mBalClass.'"><span class="pill-icon" aria-hidden="true">'.(($mBal>0)?'↑':(($mBal<0)?'↓':'•')).'</span><span class="pill-value">Balance '.htmlspecialchars(minutes_to_hours_formatted($mBal)).'</span></span>';
          echo '<span class="pill balance--ok"><span class="pill-icon" aria-hidden="true">⏱</span><span class="pill-value">Esperadas '.htmlspecialchars(minutes_to_hours_formatted($mExp)).'</span></span>';
          echo '<span class="pill balance--ok"><span class="pill-icon" aria-hidden="true">✓</span><span class="pill-value">Hechas '.htmlspecialchars(minutes_to_hours_formatted($mWork)).'</span></span>';
          echo '<span class="pill balance--ok"><span class="pill-icon" aria-hidden="true">🍽</span><span class="pill-value">Dietas '.$dietas.'</span></span>';
          if ($coffeeExCount > 0) {
            echo '<span class="pill balance--bad"><span class="pill-icon" aria-hidden="true">☕</span><span class="pill-value">Café exceso medio '.htmlspecialchars(minutes_to_hours_formatted($coffeeExAvg)).'</span></span>';
          } else {
            echo '<span class="pill balance--missing"><span class="pill-icon" aria-hidden="true">☕</span><span class="pill-value">Café exceso medio —</span></span>';
          }
          echo '<span class="month-summary-meta">Días con datos '.intval($monthStats['days_with_worked'] ?? 0).'/'.$workdays.($missing>0 ? ' · Incompletos '.$missing : '').'</span>';
          echo '</div>';
          echo '</td>';
          echo '</tr>';
        }
        echo "</tbody>";
      }
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
  const entryModalOverlay = document.getElementById('entryModalOverlay');
  const openAddEntryBtn = document.getElementById('openAddEntryBtn');
  const closeEntryModalBtn = document.getElementById('closeEntryModal');
  // Global controls sync: date picker and hide-weekends toggle
  const globalDate = document.getElementById('global-date');
  const entryDateInput = document.getElementById('entry-date') || (entryForm ? entryForm.querySelector('input[name="date"]') : null);
  if (globalDate && entryDateInput) {
    try { entryDateInput.value = entryDateInput.value || globalDate.value; globalDate.value = entryDateInput.value; } catch(e){}
    globalDate.addEventListener('change', function(){ try{ entryDateInput.value = this.value; }catch(e){} });
  }

  // Modal open/close for adding entries
  function openEntryModal(){
    if (!entryModalOverlay) return;
    entryModalOverlay.style.display = 'flex';
    entryModalOverlay.setAttribute('aria-hidden', 'false');
    try {
      // keep date synced with global date
      if (globalDate && entryDateInput) entryDateInput.value = globalDate.value;
      const first = entryForm ? (entryForm.querySelector('input[name="start"]') || entryForm.querySelector('input,select,textarea')) : null;
      if (first) first.focus();
    } catch(e){}
  }
  function closeEntryModal(){
    if (!entryModalOverlay) return;
    entryModalOverlay.style.display = 'none';
    entryModalOverlay.setAttribute('aria-hidden', 'true');
  }
  if (openAddEntryBtn) openAddEntryBtn.addEventListener('click', openEntryModal);
  if (closeEntryModalBtn) closeEntryModalBtn.addEventListener('click', closeEntryModal);
  if (entryModalOverlay) entryModalOverlay.addEventListener('click', function(e){ if (e.target === entryModalOverlay) closeEntryModal(); });

  // Dashboard quick action: ?date=YYYY-MM-DD&open_add=1
  const shouldOpenAdd = <?php echo $openAdd ? 'true' : 'false'; ?>;
  if (shouldOpenAdd) {
    openEntryModal();
    try {
      const params = new URLSearchParams(location.search);
      params.delete('open_add');
      history.replaceState(null, '', location.pathname + (params.toString() ? ('?' + params.toString()) : ''));
    } catch (e) {}
  }

  const globalHide = document.getElementById('global-hide-weekends');
  const filtersHideInput = filtersForm ? filtersForm.querySelector('input[name="hide_weekends"]') : null;
  const entryYear = document.getElementById('entry-year');
  function applyHideWeekendsClient(checked){ document.querySelectorAll('tr.weekend').forEach(function(tr){ tr.style.display = checked ? 'none' : ''; }); }
  function buildQueryString(){
    const params = new URLSearchParams(location.search);
    if (!filtersForm) return params.toString();
    const keys = ['hide_weekends','hide_holidays','hide_vacations'];
    keys.forEach(function(k){
      const inp = filtersForm.querySelector('input[name="' + k + '"]');
      if (inp && inp.checked) params.set(k, '1');
      else params.delete(k);
    });
    if (entryYear && entryYear.value) params.set('year', entryYear.value);
    return params.toString();
  }

  if (entryYear){
    entryYear.addEventListener('change', function(){
      try {
        const qs = buildQueryString();
        // Full reload: year change affects server-side config & full dataset
        window.location.href = location.pathname + (qs ? ('?' + qs) : '');
      } catch(e){ console.error('year change error', e); }
    });
  }
  if (globalHide){
    try{ if (filtersHideInput) filtersHideInput.checked = globalHide.checked; applyHideWeekendsClient(globalHide.checked); }catch(e){}
    globalHide.addEventListener('change', function(){
      try{
        if (filtersHideInput) filtersHideInput.checked = this.checked;
        applyHideWeekendsClient(this.checked);
        if (filtersForm){
          fetchTable();
          const qs = buildQueryString();
          history.replaceState(null, '', location.pathname + (qs ? ('?' + qs) : ''));
        }
      }catch(e){}
    });
  }
  // Note: year is chosen in dashboard; index preserves `year` via URL.
  // No JS needed for sticky headers - CSS handles it now

  // Export CSV functionality
  document.getElementById('export-csv-btn').addEventListener('click', function(){
    try {
      const rows = [];
      const table = document.querySelector('.sheet');
      
      // Headers
      rows.push('Fecha,Entrada,Salida,Nota,Saldo');
      
      // Data rows
      document.querySelectorAll('.sheet tbody tr').forEach(function(tr){
        if (tr.classList.contains('month') || tr.classList.contains('month-summary') || tr.classList.contains('month-columns')) return;
        const cells = tr.querySelectorAll('td');
        if (cells.length < 2) return;
        
        const date = cells[0].textContent.trim();
        const entrada = cells[2] ? cells[2].textContent.trim() : '';
        const salida = cells[3] ? cells[3].textContent.trim() : '';
        const nota = cells[5] ? cells[5].textContent.trim() : '';
        const saldo = cells[6] ? cells[6].textContent.trim() : '';
        
        rows.push(`"${date}","${entrada}","${salida}","${nota}","${saldo}"`);
      });
      
      const csv = rows.join('\n');
      const blob = new Blob([csv], {type: 'text/csv;charset=utf-8;'});
      const link = document.createElement('a');
      const url = URL.createObjectURL(blob);
      link.setAttribute('href', url);
      link.setAttribute('download', 'entradas-' + new Date().toISOString().split('T')[0] + '.csv');
      link.style.visibility = 'hidden';
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      URL.revokeObjectURL(url);
    } catch(err) {
      console.error('Error exporting CSV:', err);
      alert('Error al descargar CSV');
    }
  });

  function fetchTable(){
    try { var qs = buildQueryString(); } catch(e){ var qs = ''; }
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
      e.preventDefault();
      fetchTable();
      const qs = buildQueryString();
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
            // reset times/note but keep date
            try {
              const keepDate = entryForm.querySelector('input[name="date"]')?.value || '';
              entryForm.reset();
              const dInp = entryForm.querySelector('input[name="date"]');
              if (dInp && keepDate) dInp.value = keepDate;
            } catch(e){}
            closeEntryModal();
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
      // Also update inline display style when toggling individual months
      tbody.querySelectorAll('tr').forEach(function(tr){
        if (tr.classList.contains('month')) return;
        if (tr.classList.contains('month-summary')) return;
        tr.style.display = collapse ? 'none' : '';
      });
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
        tbody.querySelectorAll('tr').forEach(function(tr){
          if (tr.classList.contains('month')) return;
          if (tr.classList.contains('month-summary')) return;
          tr.style.display = collapsed ? 'none' : '';
        });
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
      // Add absence_type to form data
      tr.dataset._absence_type = editBtn.getAttribute('data-absence_type') || '';
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
      if (tr.dataset._absence_type) fd.append('absence_type', tr.dataset._absence_type);
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
