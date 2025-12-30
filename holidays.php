<?php
require_once __DIR__ . '/auth.php';
require_admin();
require_once __DIR__ . '/db.php';

 $pdo = get_pdo();
if (!$pdo) { echo "DB connection required"; exit; }

// ensure table (add `type` column support)
$pdo->exec("CREATE TABLE IF NOT EXISTS holidays (
  id INT AUTO_INCREMENT PRIMARY KEY,
  year INT NOT NULL,
  date DATE NOT NULL,
  label VARCHAR(255) DEFAULT NULL,
  type VARCHAR(20) DEFAULT 'holiday',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY year_date (year,date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// migrate existing table to add `type` column if missing (safe to run)
try {
  $pdo->exec("ALTER TABLE holidays ADD COLUMN IF NOT EXISTS type VARCHAR(20) DEFAULT 'holiday'");
} catch (Throwable $e) {
  // older MySQL may not support IF NOT EXISTS — try a simple add and ignore errors
  try { $pdo->exec("ALTER TABLE holidays ADD COLUMN type VARCHAR(20) DEFAULT 'holiday'"); } catch (Throwable $e2) { /* ignore */ }
}

$selYear = intval($_GET['year'] ?? date('Y'));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  if ($_POST['action'] === 'add' && !empty($_POST['date'])) {
    $d = $_POST['date'];
    $y = intval(date('Y', strtotime($d)));
    $label = trim($_POST['label'] ?? '');
    $type = in_array($_POST['type'] ?? '', ['holiday','vacation','personal']) ? $_POST['type'] : 'holiday';
    $stmt = $pdo->prepare('REPLACE INTO holidays (year,date,label,type) VALUES (?,?,?,?)');
    $stmt->execute([$y, $d, $label, $type]);
    header('Location: holidays.php?year=' . urlencode($y)); exit;
  }
  if ($_POST['action'] === 'delete' && !empty($_POST['id'])) {
    $stmt = $pdo->prepare('DELETE FROM holidays WHERE id = ?');
    $stmt->execute([intval($_POST['id'])]);
    header('Location: holidays.php?year=' . urlencode($selYear)); exit;
  }
}

$stmt = $pdo->prepare('SELECT * FROM holidays WHERE year = ? ORDER BY date ASC');
$stmt->execute([$selYear]);
$rows = $stmt->fetchAll();
?>
<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Festivos — Configuración</title><link rel="stylesheet" href="styles.css"></head><body>
<?php include __DIR__ . '/header.php'; ?>
<div class="container">
  <div class="card">
    <h3>Festivos — Año <?php echo $selYear; ?></h3>

    <form method="get" style="margin-bottom:12px">
      <label class="small">Año
        <select name="year" onchange="this.form.submit()">
          <?php for($y = date('Y')-2; $y <= date('Y')+2; $y++): ?>
            <option value="<?php echo $y;?>" <?php if($y==$selYear) echo 'selected';?>><?php echo $y;?></option>
          <?php endfor; ?>
        </select>
      </label>
    </form>

    <form method="post" class="row-form">
      <input type="hidden" name="action" value="add">
      <label>Fecha <input type="date" name="date" required></label>
      <label>Descripción <input type="text" name="label" placeholder="Ej: Año Nuevo"></label>
      <label>Tipo
        <select name="type" class="form-select">
          <option value="holiday">Festivo</option>
          <option value="vacation">Vacaciones</option>
          <option value="personal">Asuntos propios</option>
        </select>
      </label>
      <button class="btn btn-primary" type="submit">Añadir</button>
    </form>

    <h4>Listado de festivos</h4>
    <div class="table-responsive">
      <table class="sheet compact">
        <thead>
          <tr><th>Fecha</th><th>Tipo</th><th>Descripción</th><th>Acciones</th></tr>
        </thead>
        <tbody>
        <?php foreach($rows as $r): ?>
          <tr class="<?php echo $r['type'] === 'vacation' ? 'vacation' : ($r['type'] === 'personal' ? 'personal' : 'holiday'); ?>">
            <td><?php echo $r['date']?></td>
            <td><?php echo $r['type']?></td>
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
