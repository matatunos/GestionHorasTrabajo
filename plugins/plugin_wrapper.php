<?php
// plugin_wrapper.php: ejecuta un plugin dentro del layout principal
$plugin = $_GET['plugin'] ?? '';
$plugin = preg_replace('/[^a-zA-Z0-9_\-]/', '', $plugin); // sanitiza
$plugin_dir = __DIR__ . '/' . $plugin;
$plugin_index = $plugin_dir . '/index.php';
if (!is_dir($plugin_dir) || !file_exists($plugin_index)) {
    http_response_code(404);
    echo 'Plugin no encontrado.';
    exit;
}
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../db.php';
$site_cfg = get_config();
?><!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title><?php echo htmlspecialchars($site_cfg['site_name'] ?? 'GestionHoras'); ?> - Plugin</title>
  <link rel="stylesheet" href="/styles.css">
  <?php if (file_exists($plugin_dir . '/style.css')): ?>
    <link rel="stylesheet" href="<?php echo 'plugins/' . htmlspecialchars($plugin) . '/style.css'; ?>">
  <?php endif; ?>
</head>
<body>
<?php include dirname(__DIR__) . '/header.php'; ?>
<div class="container">
  <div class="card">
    <?php include $plugin_index; ?>
  </div>
</div>
<?php include __DIR__ . '/../footer.php'; ?>
</body>
</html>
