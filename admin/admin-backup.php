<?php
require_once __DIR__ . '/auth.php';
require_login();
$user = current_user();
if (empty($user) || empty($user['is_admin'])) {
    header('HTTP/1.1 403 Forbidden');
    echo 'Acceso denegado';
    exit;
}
require_once __DIR__ . '/db.php';
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Backup - Administración</title>
  <link rel="stylesheet" href="styles.css">
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>
<div class="container">
  <div class="card">
    <h3>Herramientas de Backup y Restauración</h3>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 2rem; margin: 1.5rem 0;">
      <div style="border: 1px solid #dee2e6; border-radius: 8px; padding: 1.5rem; background: #f8f9fa;">
        <h4 style="margin-top: 0; color: #0056b3;">📥 Descargar Backup</h4>
        <p style="color: #666; margin: 0 0 1rem 0;">Crea una copia completa de la base de datos para guardarla de forma segura.</p>
        <button class="btn btn-primary" onclick="downloadBackup(this);" style="width: 100%;">Descargar Backup Full</button>
      </div>
      <div style="border: 1px solid #dee2e6; border-radius: 8px; padding: 1.5rem; background: #f8f9fa;">
        <h4 style="margin-top: 0; color: #0056b3;">📤 Restaurar Backup</h4>
        <p style="color: #666; margin: 0 0 1rem 0;">Restaura la base de datos desde un archivo de backup anterior.</p>
        <input type="file" id="backupFile" accept=".sql" style="margin-bottom: 1rem; display: block; width: 100%; padding: 0.5rem; border: 1px solid #ccc; border-radius: 4px;">
        <button class="btn btn-warning" onclick="uploadBackup(this);" style="width: 100%;">Restaurar Backup</button>
      </div>
    </div>
  </div>
</div>

<script>
function downloadBackup(el) {
  const btn = el || (typeof event !== 'undefined' ? event.target : null);
  if (btn) { btn.disabled = true; btn.textContent = 'Descargando...'; }
  const a = document.createElement('a');
  a.href = 'backup_handler.php?action=export';
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => {
    if (btn) { btn.disabled = false; btn.textContent = 'Descargar Backup Full'; }
  }, 2000);
}

function uploadBackup(el) {
  const fileInput = document.getElementById('backupFile');
  const btn = el || (typeof event !== 'undefined' ? event.target : null);
  if (!fileInput.files.length) {
    alert('Por favor selecciona un archivo de backup');
    return;
  }
  const file = fileInput.files[0];
  if (!file.name.endsWith('.sql')) {
    alert('Por favor selecciona un archivo .sql válido');
    return;
  }
  if (!confirm('⚠️ ADVERTENCIA: Esta acción reemplazará TODA la base de datos actual.\n¿Estás seguro de que deseas continuar?')) {
    return;
  }
  if (btn) { btn.disabled = true; btn.textContent = 'Restaurando...'; }
  const formData = new FormData();
  formData.append('backup_file', file);
  fetch('backup_handler.php?action=import', { method: 'POST', body: formData })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        alert('✓ Backup restaurado exitosamente. La página se recargará en 2 segundos.');
        setTimeout(() => location.reload(), 2000);
      } else {
        alert('✗ Error al restaurar: ' + (data.error || 'Error desconocido'));
        if (btn) { btn.disabled = false; btn.textContent = 'Restaurar Backup'; }
      }
    })
    .catch(error => {
      alert('✗ Error de red: ' + error.message);
      if (btn) { btn.disabled = false; btn.textContent = 'Restaurar Backup'; }
    });
}
</script>

<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
