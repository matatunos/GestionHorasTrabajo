<?php
require_once __DIR__ . '/auth.php';
require_login();
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
				<a class="sidebar-brand-logo logo" href="/dashboard.php"><h1><?php echo htmlspecialchars($site_name); ?></h1></a>
			</div>
			<button class="mobile-menu-toggle" id="mobileMenuClose" aria-label="Cerrar menú">✕</button>
		</div>
		<nav class="sidebar-menu">
			<div class="menu-section">
				<?php if (!empty($current)): ?>
					<a class="menu-item" href="/dashboard.php">🏠 Dashboard</a>
					<a class="menu-item" href="/index.php">🕒 Registro horario</a>
					<a class="menu-item" href="/holidays.php">📅 Festivos y Ausencias</a>
                                        <div class="menu-item menu-guardias-dropdown" tabindex="0" style="padding-left:0; position:relative; cursor:pointer;">
                                                <span style="font-size:1.2em; margin-right:7px;">🛡️</span>
                                                <span>Guardias ▼</span>
                                                <ul class="guardias-dropdown-list" style="display:none; position:absolute; left:0; top:100%; background:#1a2639; border:1px solid #2a3f5f; border-radius:8px; min-width:220px; z-index:1000; list-style:none; padding:8px 0; margin:0; box-shadow:0 4px 16px rgba(0,0,0,0.18);">
                                                        <li style="margin:0; padding:0;"><a href="/guardias-nomina.php" style="display:block; color:#eaf1fb; text-decoration:none; font-weight:500; padding:10px 18px; border-radius:4px; transition:background 0.15s;" onmouseover="this.style.background='#22304a'" onmouseout="this.style.background='none'">🛡️ Guardias vs. Nómina</a></li>
                                                        <li style="margin:0; padding:0;"><a href="/guardias-compensacion.php" style="display:block; color:#eaf1fb; text-decoration:none; font-weight:500; padding:10px 18px; border-radius:4px; transition:background 0.15s;" onmouseover="this.style.background='#22304a'" onmouseout="this.style.background='none'">⚖️ Compensación Guardias</a></li>
                                                        <li style="margin:0; padding:0;"><a href="/vacaciones.php" style="display:block; color:#eaf1fb; text-decoration:none; font-weight:500; padding:10px 18px; border-radius:4px; transition:background 0.15s;" onmouseover="this.style.background='#22304a'" onmouseout="this.style.background='none'">🏖️ Vacaciones</a></li>
                                                </ul>
                                        </div>
                                        <script>
                                        document.addEventListener('DOMContentLoaded', function() {
                                          var guardiasMenu = document.querySelector('.menu-guardias-dropdown');
                                          var guardiasDropdown = guardiasMenu && guardiasMenu.querySelector('.guardias-dropdown-list');
                                          if (guardiasMenu && guardiasDropdown) {
                                                guardiasMenu.addEventListener('click', function(e) {
                                                  e.stopPropagation();
                                                  guardiasDropdown.style.display = guardiasDropdown.style.display === 'block' ? 'none' : 'block';
                                                });
                                                document.addEventListener('click', function() {
                                                  guardiasDropdown.style.display = 'none';
                                                });
                                          }
                                        });
                                        </script>
					<?php
					require_once __DIR__ . '/plugins/plugin_loader.php';
					$plugins = get_plugins_list(__DIR__ . '/plugins');
					if (!empty($plugins)) : ?>
						<div class="menu-item menu-plugins-dropdown" tabindex="0" style="padding-left:0; position:relative; cursor:pointer;">
							<span style="font-size:1.2em; margin-right:7px;">🧩</span>
							<span>Plugins ▼</span>
							<ul class="plugins-dropdown-list" style="display:none; position:absolute; left:0; top:100%; background:#1a2639; border:1px solid #2a3f5f; border-radius:8px; min-width:180px; z-index:1000; list-style:none; padding:8px 0; margin:0; box-shadow:0 4px 16px rgba(0,0,0,0.18);">
							<?php foreach ($plugins as $plugin): ?>
								<li style="margin:0; padding:0;">
									<a href="/plugins/plugin_wrapper.php?plugin=<?php echo urlencode($plugin['dir']); ?>" style="display:block; color:#eaf1fb; text-decoration:none; font-weight:500; padding:8px 18px; border-radius:4px; transition:background 0.15s;" onmouseover="this.style.background='#22304a'" onmouseout="this.style.background='none'">
										<?php echo htmlspecialchars($plugin['name']); ?>
									</a>
								</li>
							<?php endforeach; ?>
							</ul>
						</div>
						<script>
						document.addEventListener('DOMContentLoaded', function() {
						  var pluginMenu = document.querySelector('.menu-plugins-dropdown');
						  var dropdown = pluginMenu && pluginMenu.querySelector('.plugins-dropdown-list');
						  if(pluginMenu && dropdown) {
							pluginMenu.addEventListener('click', function(e) {
							  e.stopPropagation();
							  dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
							});
							document.addEventListener('click', function() {
							  dropdown.style.display = 'none';
							});
						  }
						});
						</script>
					<?php endif; ?>
				<?php endif; ?>
				<!-- 'Años' link removed: management consolidated into settings.php -->
				<?php if (!empty($current) && $current['is_admin']): ?>
					<a class="menu-item" href="/settings.php">⚙️ Configuración</a>
				<?php endif; ?>

				<?php if (!empty($current)): ?>
					<div class="menu-item menu-user" tabindex="0">
						<div class="user-avatar"><?php echo strtoupper(substr($current['username'],0,1)); ?></div>
						<span class="menu-user-name"><?php echo htmlspecialchars($current['username']); ?></span>
						<div class="menu-user-dropdown" role="menu">
							<a class="dropdown-item" href="/profile.php">👤 Perfil</a>
							<a class="dropdown-item" href="/import.php#importexport">🔁 Importar/Exportar</a>
							<a class="dropdown-item" href="/holidays.php">📅 Festivos y Ausencias</a>
							<a class="dropdown-item" href="#" onclick="openScheduleSuggestions(event)">⚡ Sugerencias de Horario (Beta)</a>
							<a class="dropdown-item" href="/import-calendar-beta.php">📅 Importar Calendario (Beta)</a>
							<a class="dropdown-item" href="/data_quality.php">📊 Calidad de Datos</a>
							<a class="dropdown-item" href="/chrome-addon-help.php">🧩 Extensión Chrome</a>
							<a class="dropdown-item" href="/firefox-addon-help.php">🧩 Extensión Firefox</a>
							<a class="dropdown-item" href="/extension-tokens.php">🔐 Tokens</a>
							<?php if (!empty($current) && !empty($current['is_admin'])): ?>
								<a class="dropdown-item" href="/reports.php">📊 Reportes</a>
								<a class="dropdown-item" href="/admin-backup.php">🗄️ Backup</a>
							<?php endif; ?>
							<a class="dropdown-item" href="/logout.php">🚪 Salir</a>
						</div>
					</div>
				<?php else: ?>
					<a class="menu-item" href="/login.php">Acceder</a>
				<?php endif; ?>
			</div>
		</nav>
	</aside>

	<div class="main-content">
		<?php if (empty($hidePageHeader)): ?>
			<header class="header">
				<button class="mobile-menu-toggle" id="mobileMenuOpen" aria-label="Abrir menú">☰</button>
				<div class="header-brand">
					<a class="header-brand-logo" href="/dashboard.php"><!-- optional logo --></a>
				</div>
				<div class="header-actions">
				</div>
			</header>
		<?php else: ?>
			<button class="mobile-menu-toggle" id="mobileMenuOpen" aria-label="Abrir menú" style="position: fixed; top: 0.5rem; left: 0.5rem;">☰</button>
		<?php endif; ?>


		<script>
		document.addEventListener('DOMContentLoaded', function(){
			// Mostrar/ocultar menú usuario (perfil)
			var userMenu = document.querySelector('.menu-user');
			if (userMenu) {
				userMenu.addEventListener('click', function(e) {
					e.stopPropagation();
					userMenu.classList.toggle('open');
				});
				// Cerrar si se hace clic fuera
				document.addEventListener('click', function(e) {
					if (!userMenu.contains(e.target)) {
						userMenu.classList.remove('open');
					}
				});
				// Accesibilidad: cerrar con Escape
				document.addEventListener('keydown', function(e) {
					if (e.key === 'Escape') userMenu.classList.remove('open');
				});
			}
			// header siempre visible (topbar horizontal)
		});
		</script>

