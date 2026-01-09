<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';
$current = null;
try { $current = current_user(); } catch (Throwable $e) { $current = null; }
$site_cfg = get_config();
$site_name = $site_cfg['site_name'] ?? 'GestionHoras';

// Layout header: no year selector and no "hide weekends" control
?>
<div class="app-container">
  <aside class="sidebar" id="mobileSidebar">
    <div class="sidebar-header">
      <div class="sidebar-brand-visual">
        <a class="sidebar-brand-logo logo" href="dashboard.php"><h1><?php echo htmlspecialchars($site_name); ?></h1></a>
      </div>
      <button class="mobile-menu-toggle" id="mobileMenuClose" aria-label="Cerrar menú">✕</button>
    </div>
    <nav class="sidebar-menu">
      <div class="menu-section">
        <?php if (!empty($current)): ?>
          <a class="menu-item" href="dashboard.php">Dashboard</a>
          <a class="menu-item" href="holidays.php">📅 Festivos y Ausencias</a>
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
              <a class="dropdown-item" href="profile.php">👤 Perfil</a>
              <a class="dropdown-item" href="holidays.php">📅 Festivos y Ausencias</a>
              <a class="dropdown-item" href="#" onclick="openScheduleSuggestions(event)">⚡ Sugerencias de Horario (Beta)</a>
              <a class="dropdown-item" href="import-calendar-beta.php">📅 Importar Calendario (Beta)</a>
              <a class="dropdown-item" href="data_quality.php">📊 Calidad de Datos</a>
              <a class="dropdown-item" href="chrome-addon-help.php">🧩 Extensión Chrome</a>
              <a class="dropdown-item" href="extension-tokens.php">🔐 Tokens</a>
              <a class="dropdown-item" href="logout.php">🚪 Salir</a>
            </div>
          </div>
        <?php else: ?>
          <a class="menu-item" href="login.php">Acceder</a>
        <?php endif; ?>
      </div>
    </nav>
  </aside>

  <div class="main-content">
    <?php if (empty($hidePageHeader)): ?>
      <header class="header">
        <button class="mobile-menu-toggle" id="mobileMenuOpen" aria-label="Abrir menú">☰</button>
        <div class="header-brand">
          <a class="header-brand-logo" href="dashboard.php"><!-- optional logo --></a>
        </div>
        <div class="header-actions">
        </div>
      </header>
    <?php else: ?>
      <button class="mobile-menu-toggle" id="mobileMenuOpen" aria-label="Abrir menú" style="position: fixed; top: 0.5rem; left: 0.5rem;">☰</button>
    <?php endif; ?>

    <script>
    document.addEventListener('DOMContentLoaded', function(){
      // Mobile menu toggle
      const sidebar = document.getElementById('mobileSidebar');
      const openBtn = document.getElementById('mobileMenuOpen');
      const closeBtn = document.getElementById('mobileMenuClose');
      
      if (!sidebar || !openBtn) return;
      
      // Open menu
      openBtn.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        sidebar.classList.add('open');
      });
      
      // Close menu
      if (closeBtn) {
        closeBtn.addEventListener('click', function(e) {
          e.preventDefault();
          sidebar.classList.remove('open');
        });
      }
      
      // Close sidebar when clicking a link
      const links = sidebar.querySelectorAll('a.menu-item');
      links.forEach(link => {
        link.addEventListener('click', function() {
          setTimeout(() => sidebar.classList.remove('open'), 100);
        });
      });
      
      // Close sidebar when clicking the overlay
      sidebar.addEventListener('click', function(e) {
        if (e.target === sidebar) {
          sidebar.classList.remove('open');
        }
      });
      
      // Handle menu-user dropdown
      document.addEventListener('click', function(e){
        const mu = document.querySelector('.menu-user');
        if(!mu) return;
        if (mu.contains(e.target)) {
          e.stopPropagation();
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
          sidebar.classList.remove('open');
        }
      });
    });
    </script>

