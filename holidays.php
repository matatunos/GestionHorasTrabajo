<?php
require_once __DIR__ . '/auth.php';
require_login();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib.php';

$pdo = get_pdo();
if (!$pdo) { echo "DB connection required"; exit; }

// ensure table (add `type` column support)
// Create table without a separate `year` column. Use YEAR(date) when querying.
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

// ensure `type` and `annual` columns exist (safe to run)
try {
  $pdo->exec("ALTER TABLE holidays ADD COLUMN IF NOT EXISTS type VARCHAR(20) DEFAULT 'holiday'");
} catch (Throwable $e) {
  try { $pdo->exec("ALTER TABLE holidays ADD COLUMN type VARCHAR(20) DEFAULT 'holiday'"); } catch (Throwable $e2) { /* ignore */ }
}
try {
  $pdo->exec("ALTER TABLE holidays ADD COLUMN IF NOT EXISTS annual TINYINT(1) DEFAULT 0");
} catch (Throwable $e) {
  try { $pdo->exec("ALTER TABLE holidays ADD COLUMN annual TINYINT(1) DEFAULT 0"); } catch (Throwable $e2) { /* ignore */ }
}

// If a legacy `year` column exists, drop it (best-effort, ignore errors)
try {
  $pdo->exec("ALTER TABLE holidays DROP COLUMN IF EXISTS year");
} catch (Throwable $e) {
  try { $pdo->exec("ALTER TABLE holidays DROP COLUMN year"); } catch (Throwable $e2) { /* ignore */ }
}
// Try to drop legacy key year_date if present
try { $pdo->exec("ALTER TABLE holidays DROP INDEX year_date"); } catch (Throwable $e) { /* ignore */ }

// ensure user_id column exists
try { $pdo->exec("ALTER TABLE holidays ADD COLUMN IF NOT EXISTS user_id INT DEFAULT NULL"); } catch (Throwable $e) { try { $pdo->exec("ALTER TABLE holidays ADD COLUMN user_id INT DEFAULT NULL"); } catch (Throwable $e2) { /* ignore */ } }

$user = current_user();
$selYear = intval($_GET['year'] ?? date('Y'));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add' && !empty($_POST['date'])) {
      $d = $_POST['date'];
      $y = intval(date('Y', strtotime($d))); // keep for redirect
      $label = trim($_POST['label'] ?? '');
      $type = in_array($_POST['type'] ?? '', ['holiday','vacation','personal','enfermedad','permiso']) ? $_POST['type'] : 'holiday';
      $annual = post_flag('annual');
      $is_global = (!empty($user) && !empty($user['is_admin']) && post_flag('global'));
      $uid = $is_global ? null : $user['id'];
      $stmt = $pdo->prepare('REPLACE INTO holidays (user_id,date,label,type,annual) VALUES (?,?,?,?,?)');
      $stmt->execute([$uid, $d, $label, $type, $annual]);
      if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest'){
        header('Content-Type: application/json'); echo json_encode(['ok'=>true]); exit;
      }
      header('Location: holidays.php?year=' . urlencode($y)); exit;
    }
    if ($_POST['action'] === 'delete' && !empty($_POST['id'])) {
        $stmt = $pdo->prepare('DELETE FROM holidays WHERE id = ?');
        $stmt->execute([intval($_POST['id'])]);
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest'){
            header('Content-Type: application/json'); echo json_encode(['ok'=>true]); exit;
        }
        header('Location: holidays.php?year=' . urlencode($selYear)); exit;
    }
}

$stmt = $pdo->prepare('SELECT * FROM holidays WHERE (YEAR(date) = ? OR annual = 1) AND (user_id IS NULL OR user_id = ?) ORDER BY date ASC');
$stmt->execute([$selYear, $user['id']]);
$rows = $stmt->fetchAll();
?>
<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Festivos — Configuración</title><link rel="stylesheet" href="styles.css"></head><body>
<?php include __DIR__ . '/header.php'; ?>
<div class="container">
  <div class="card">
    <h3>Festivos — Año <?php echo $selYear; ?></h3>

    <form method="get" style="margin-bottom:12px" class="form-wrapper">
      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Año</label>
          <select class="form-control" name="year" onchange="this.form.submit()">
            <?php for($y = date('Y')-2; $y <= date('Y')+2; $y++): ?>
              <option value="<?php echo $y;?>" <?php if($y==$selYear) echo 'selected';?>><?php echo $y;?></option>
            <?php endfor; ?>
          </select>
        </div>
      </div>
    </form>

    <form method="post" class="form-wrapper">
      <input type="hidden" name="action" value="add">
      <div class="form-grid">
        <div class="form-group"><label class="form-label">Fecha</label>
          <div style="display:flex;gap:8px;align-items:center;">
            <select id="hd_month" class="form-control" aria-label="Mes"></select>
            <select id="hd_day" class="form-control" aria-label="Día"></select>
            <input type="hidden" id="hd_date" name="date" required>
          </div>
        </div>
        <div class="form-group"><label class="form-label">Descripción</label><input class="form-control" type="text" name="label" placeholder="Ej: Año Nuevo"></div>
        <div class="form-group"><label class="form-label">Tipo</label>
          <select class="form-control" name="type">
            <option value="holiday">Festivo</option>
            <option value="vacation">Vacaciones</option>
            <option value="personal">Asuntos propios</option>
            <option value="enfermedad">Enfermedad</option>
            <option value="permiso">Permiso</option>
          </select>
        </div>
        <div class="form-group"><?php echo render_checkbox('annual', post_flag('annual'), 'Repite anualmente'); ?></div>
        <?php if (!empty($user) && !empty($user['is_admin'])): ?>
          <div class="form-group"><?php echo render_checkbox('global', 0, 'Visible a todos (global)'); ?></div>
        <?php endif; ?>
      </div>
      <div class="form-actions" style="margin-top:12px;"><button class="btn-primary" type="submit">Añadir</button></div>
    </form>

    <h4>Listado de festivos</h4>
    <?php /* debug removed */ ?>
    <div class="table-responsive">
      <table class="sheet compact">
        <thead>
          <tr><th>Fecha</th><th>Repite</th><th>Tipo</th><th>Descripción</th><th>Acciones</th></tr>
        </thead>
        <tbody>
        <?php foreach($rows as $r):
            // if holiday is annual, show date adjusted to selected year for display
            $displayDate = $r['date'];
            if (!empty($r['annual'])) {
                $displayDate = sprintf('%04d-%s', $selYear, substr($r['date'],5));
            }
        ?>
          <tr class="<?php echo $r['type'] === 'vacation' ? 'vacation' : ($r['type'] === 'personal' ? 'personal' : 'holiday'); ?>">
            <td><?php echo htmlspecialchars($displayDate)?></td>
            <td><?php echo !empty($r['annual']) ? '<span class="badge badge-primary">Anual</span>' : ''?></td>
            <td><?php echo ($r['type']==='vacation') ? 'Vacaciones' : (($r['type']==='personal') ? 'Asuntos propios' : (($r['type']==='enfermedad') ? 'Enfermedad' : (($r['type']==='permiso') ? 'Permiso' : 'Festivo'))); ?></td>
            <td><?php echo htmlspecialchars($r['label'])?></td>
            <td>
              <form method="post" style="display:inline">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?php echo $r['id']?>">
                <button class="btn btn-outline" type="submit">Eliminar</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php include __DIR__ . '/footer.php'; ?>
</body></html>
<script>
(function(){
  const containerSel = '.table-responsive';
  // date picker without year: populate month/day selects and keep hidden date field
  (function(){
    const monthSel = document.getElementById('hd_month');
    const daySel = document.getElementById('hd_day');
    const hidden = document.getElementById('hd_date');
    const selYear = <?php echo intval($selYear); ?>;
    if (monthSel && daySel && hidden) {
      // populate months
      for (let m=1;m<=12;m++){ const v = String(m).padStart(2,'0'); const o=document.createElement('option'); o.value=v; o.textContent=v; monthSel.appendChild(o);} 
      // populate days default 31
      function setDays(n){ daySel.innerHTML=''; for(let d=1;d<=n;d++){ const v=String(d).padStart(2,'0'); const o=document.createElement('option'); o.value=v; o.textContent=v; daySel.appendChild(o);} }
      setDays(31);
      function updateHidden(){ hidden.value = selYear + '-' + monthSel.value + '-' + daySel.value; }
      monthSel.addEventListener('change', function(){ const m=parseInt(this.value,10); const nd = new Date(2000,m,0).getDate(); setDays(nd); if (daySel.options.length<1) setDays(31); if (+daySel.value>nd) daySel.value=String(nd).padStart(2,'0'); updateHidden(); });
      daySel.addEventListener('change', updateHidden);
      // initialize
      monthSel.value = String((new Date()).getMonth()+1).padStart(2,'0');
      daySel.value = String((new Date()).getDate()).padStart(2,'0');
      updateHidden();
      // ensure hidden is set before submit
      document.addEventListener('submit', function(e){ const f = e.target; if (!(f instanceof HTMLFormElement)) return; if (f.querySelector('#hd_date')) updateHidden(); }, true);
    }
  })();
  async function refreshList(){
    try {
      const res = await fetch(location.pathname + location.search, { headers: {'X-Requested-With':'XMLHttpRequest'} });
      const text = await res.text();
      const tmp = document.createElement('div'); tmp.innerHTML = text;
      const newTable = tmp.querySelector(containerSel);
      const cur = document.querySelector(containerSel);
      if (newTable && cur) cur.innerHTML = newTable.innerHTML;
    } catch(e){ console.error('refreshList error', e); }
  }

  document.addEventListener('submit', function(e){
    const form = e.target;
    if (!(form instanceof HTMLFormElement)) return;
    const fd = new FormData(form);
    const action = fd.get('action');
    if (action !== 'add' && action !== 'delete') return;
    e.preventDefault();
    const submitBtn = form.querySelector('[type="submit"]');
    let origText;
    if (submitBtn) { origText = submitBtn.innerText; submitBtn.disabled = true; submitBtn.innerText = 'Enviando...'; }
    fetch(location.pathname + location.search, { method: 'POST', body: fd, headers: {'X-Requested-With':'XMLHttpRequest'} })
      .then(async r => {
        const ct = r.headers.get('Content-Type') || '';
        let data;
        if (ct.indexOf('application/json') !== -1) {
          data = await r.json();
        } else {
          const text = await r.text();
          try { data = JSON.parse(text); } catch(err) { console.warn('Non-JSON response', text); data = { ok: false, text }; }
        }
        if (submitBtn) { submitBtn.disabled = false; submitBtn.innerText = origText; }
        if (data && data.ok) {
          if (action === 'add') form.reset();
          refreshList();
        } else {
          alert('Error al procesar la solicitud');
        }
      }).catch(err => { console.error(err); if (submitBtn){ submitBtn.disabled = false; submitBtn.innerText = origText; } alert('Error de red'); });
  }, false);
})();
</script>
