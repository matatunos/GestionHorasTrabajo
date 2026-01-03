<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';
$current = null;
try { $current = current_user(); } catch (Throwable $e) { $current = null; }

// Layout header: no year selector and no "hide weekends" control
?>
<div class="app-container">
  <aside class="sidebar">
    <div class="sidebar-header">
      <div class="sidebar-brand-visual">
        <a class="sidebar-brand-logo logo" href="dashboard.php"><h1>GestionHoras</h1></a>
      </div>
    </div>
    <nav class="sidebar-menu">
      <div class="menu-section">
        <?php if (!empty($current)): ?>
          <a class="menu-item" href="dashboard.php">Dashboard</a>
        <?php endif; ?>
        <a class="menu-item" href="index.php">Registro horario</a>
        <!-- 'Años' link removed: management consolidated into settings.php -->
        <a class="menu-item" href="import.php">Importar Fichajes</a>
        <?php if (!empty($current) && $current['is_admin']): ?>
          <a class="menu-item" href="reports.php">Reportes</a>
          <a class="menu-item" href="settings.php">Configuración</a>
        <?php endif; ?>

        <?php if (!empty($current)): ?>
          <div class="menu-item menu-user" tabindex="0">
            <div class="user-avatar"><?php echo strtoupper(substr($current['username'],0,1)); ?></div>
            <span class="menu-user-name"><?php echo htmlspecialchars($current['username']); ?></span>
            <div class="menu-user-dropdown" role="menu">
              <a class="dropdown-item" href="profile.php">Perfil</a>
              <a class="dropdown-item" href="logout.php">Salir</a>
            </div>
          </div>
        <?php else: ?>
          <a class="menu-item" href="login.php">Acceder</a>
        <?php endif; ?>
      </div>
    </nav>
  </aside>

  <div class="main-content">
    <?php $site_cfg = get_config(); $site_name = $site_cfg['site_name'] ?? 'GestionHoras'; ?>
    <?php if (empty($hidePageHeader)): ?>
      <header class="header">
        <div class="header-brand">
          <a class="header-brand-logo" href="dashboard.php"><!-- optional logo --></a>
        </div>
        <div class="header-actions">
        </div>
      </header>
    <?php endif; ?>

    <script>
    (function(){
      // Handle menu-user dropdown
      document.addEventListener('click', function(e){
        const mu = document.querySelector('.menu-user');
        if(!mu) return;
        if (mu.contains(e.target)) {
          mu.classList.toggle('open');
        } else {
          mu.classList.remove('open');
        }
      });
      
      // Close menus on Escape
      document.addEventListener('keydown', function(e){
        if (e.key === 'Escape') {
          const mu = document.querySelector('.menu-user');
          if(mu) mu.classList.remove('open');
        }
      });
    })();
    </script>

