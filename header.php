<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';
$current = null;
try { $current = current_user(); } catch (Throwable $e) { $current = null; }

// prepare year options from entries table (fallback to current year)
$years = [];
$selYear = intval($_GET['year'] ?? date('Y'));
try {
    $pdo = get_pdo();
    if ($pdo) {
        $rows = $pdo->query("SELECT DISTINCT YEAR(date) AS y FROM entries ORDER BY y DESC")->fetchAll();
        foreach ($rows as $r) $years[] = intval($r['y']);
    }
} catch (Throwable $e) { /* ignore */ }
if (empty($years)) { $years = [intval(date('Y'))]; }
?>
<div class="app-container">
  <aside class="sidebar">
    <div class="sidebar-header">
      <div class="sidebar-brand-visual">
        <div class="sidebar-brand-logo logo"><h1>GestionHoras</h1></div>
      </div>
    </div>
    <nav class="sidebar-menu">
      <div class="menu-section">
        <a class="menu-item" href="index.php">Inicio</a>
        <a class="menu-item" href="years.php">Años</a>
        <a class="menu-item" href="import.php">Importar Fichajes</a>
        <?php if (!empty($current) && $current['is_admin']): ?>
          <a class="menu-item" href="settings.php">Configuración</a>
          <a class="menu-item" href="users.php">Usuarios</a>
          <a class="menu-item" href="holidays.php">Festivos</a>
        <?php endif; ?>
      </div>
    </nav>
  </aside>

  <div class="main-content">
    <?php $site_cfg = get_config(); $site_name = $site_cfg['site_name'] ?? 'GestionHoras'; ?>
    <header class="header">
      <div class="header-brand">
        <div class="header-brand-logo"><!-- optional logo --></div>
        <div class="header-brand-text"><?php echo htmlspecialchars($site_name); ?></div>
      </div>
      <div class="header-actions">
        <?php if ($current): ?>
          <form method="get" action="index.php" style="display:inline;margin-right:8px;">
            <label class="small">Año
              <select name="year" onchange="this.form.submit()">
                <?php foreach($years as $y): ?>
                  <option value="<?php echo $y;?>" <?php if($y==$selYear) echo 'selected';?>><?php echo $y;?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="small" style="margin-left:8px">Ocultar fines de semana
              <input type="checkbox" name="hide_weekends" value="1" onchange="this.form.submit()" <?php if(!empty($_GET['hide_weekends'])) echo 'checked'; ?> />
            </label>
          </form>
          <div class="user-menu">
            <span class="small">Usuario: <?php echo htmlspecialchars($current['username']); ?></span>
            <a class="btn" href="logout.php">Salir</a>
          </div>
        <?php else: ?>
          <a class="btn" href="login.php">Acceder</a>
        <?php endif; ?>
      </div>
    </header>

