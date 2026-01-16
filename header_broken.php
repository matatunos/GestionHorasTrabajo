<?php
// Forzar visualización de errores fatales y warnings
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Limpiar posibles BOM o caracteres extraños (asegúrate de guardar este archivo como UTF-8 sin BOM)

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';
error_log('header.php: INICIO DE EJECUCIÓN, antes de cualquier salida');
echo '<!-- HEADER.PHP OUTPUT TEST -->';
error_log('header.php: antes de current_user()');
error_log('header.php: antes del HTML principal');
$current = null;
try {
    $current = current_user();
    error_log('header.php: después de current_user(), valor: ' . var_export($current, true));
} catch (Throwable $e) {
    error_log('header.php: excepción en current_user(): ' . $e->getMessage());
    $current = null;
}
$site_cfg = get_config();
$site_name = $site_cfg['site_name'] ?? 'GestionHoras';
error_log('header.php: después de get_config()');
// Layout header: no year selector and no "hide weekends" control
error_log('header.php: antes de mobile-menu-toggle');
?>
<?php error_log('header.php: después de mobile-menu-toggle'); ?>
<div class="app-container">
<?php error_log('header.php: antes de nav.sidebar-menu'); ?>
  <aside class="sidebar" id="mobileSidebar">
<?php error_log('header.php: antes de menu-section'); ?>
    <div class="sidebar-header">
<?php error_log('header.php: antes de if current'); ?>
      <div class="sidebar-brand-visual">
<?php error_log('header.php: dentro de if current'); ?>
        <a class="sidebar-brand-logo logo" href="dashboard.php"><h1><?php echo htmlspecialchars($site_name); ?></h1></a>
      </div>
      <button class="mobile-menu-toggle" id="mobileMenuClose" aria-label="Cerrar menú">✕</button>
    </div>
<?php error_log('header.php: después de if current'); ?>
    <nav class="sidebar-menu">
      <div class="menu-section">
<?php error_log('header.php: dentro de if is_admin'); ?>
        <?php if (!empty($current)): ?>
          <a class="menu-item" href="dashboard.php">Dashboard</a>
          <a class="menu-item" href="index.php">Registro horario</a>
<?php error_log('header.php: después de if is_admin'); ?>
          <a class="menu-item" href="holidays.php">📅 Festivos y Ausencias</a>
        <?php endif; ?>
<?php error_log('header.php: dentro de if current (user menu)'); ?>
        <!-- 'Años' link removed: management consolidated into settings.php -->
        <?php if (!empty($current) && $current['is_admin']): ?>
          <a class="menu-item" href="reports.php">Reportes</a>
          <a class="menu-item" href="settings.php">Configuración</a>
        <?php endif; ?>
        error_log('header.php: TEST MINIMO EJECUTADO');
        echo '<!-- HEADER.PHP OUTPUT TEST MINIMO -->';
            <div class="menu-user-dropdown" role="menu">
              <a class="dropdown-item" href="profile.php">👤 Perfil</a>
              <a class="dropdown-item" href="import.php#importexport">🔁 Importar/Exportar</a>
<?php error_log('header.php: dentro de if current && is_admin (user menu)'); ?>
              <a class="dropdown-item" href="holidays.php">📅 Festivos y Ausencias</a>
              <a class="dropdown-item" href="#" onclick="openScheduleSuggestions(event)">⚡ Sugerencias de Horario (Beta)</a>
<?php error_log('header.php: después de if current && is_admin (user menu)'); ?>
              <a class="dropdown-item" href="import-calendar-beta.php">📅 Importar Calendario (Beta)</a>
              <a class="dropdown-item" href="data_quality.php">📊 Calidad de Datos</a>
              <a class="dropdown-item" href="chrome-addon-help.php">🧩 Extensión Chrome</a>
              <a class="dropdown-item" href="extension-tokens.php">🔐 Tokens</a>
<?php error_log('header.php: else de if current (user menu)'); ?>
              <?php if (!empty($current) && !empty($current['is_admin'])): ?>
                <a class="dropdown-item" href="admin-backup.php">🗄️ Backup</a>
<?php error_log('header.php: después de user menu'); ?>
              <?php endif; ?>
<?php error_log('header.php: después de menu-section'); ?>
              <a class="dropdown-item" href="logout.php">🚪 Salir</a>
<?php error_log('header.php: después de nav.sidebar-menu'); ?>
            </div>
<?php error_log('header.php: después de aside.sidebar'); ?>
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

      // Hide empty header on desktop: if header-brand and header-actions are empty, remove visual header to avoid blank bar
      (function(){
        try {
          const hdr = document.querySelector('.header');
          if (!hdr) return;
          const brand = hdr.querySelector('.header-brand');
          const actions = hdr.querySelector('.header-actions');
          const isDesktop = window.matchMedia && window.matchMedia('(min-width: 768px)').matches;
          const brandEmpty = brand && brand.innerText.trim() === '';
          const actionsEmpty = actions && actions.innerText.trim() === '';
          if (isDesktop && brandEmpty && actionsEmpty) {
            hdr.style.display = 'none';
          }
        } catch (e) { /* ignore */ }
      })();
    });
    </script>

