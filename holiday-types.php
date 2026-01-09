<?php
require_once __DIR__ . '/auth.php';
require_login();
require_admin(); // Solo admin puede gestionar tipos
require_once __DIR__ . '/db.php';

$pdo = get_pdo();
$user = current_user();
$message = '';
$error = '';

// Asegurar que la tabla existe
try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS holiday_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    label VARCHAR(100) NOT NULL,
    color VARCHAR(7) DEFAULT '#0f172a',
    sort_order INT DEFAULT 0,
    is_system TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {
  // ok
}

// Procesar acciones POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  
  if ($action === 'add' || $action === 'edit') {
    $id = $action === 'edit' ? (int)($_POST['id'] ?? 0) : 0;
    $code = trim($_POST['code'] ?? '');
    $label = trim($_POST['label'] ?? '');
    $color = trim($_POST['color'] ?? '#0f172a');
    $sort_order = (int)($_POST['sort_order'] ?? 0);
    
    // Validaciones
    if (empty($code) || empty($label)) {
      $error = 'El código y la etiqueta son requeridos.';
    } elseif (strlen($code) > 50 || strlen($label) > 100) {
      $error = 'El código no puede exceder 50 caracteres ni la etiqueta 100.';
    } elseif (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
      $error = 'Color inválido. Debe ser un código hexadecimal válido.';
    } else {
      try {
        if ($action === 'add') {
          // Verificar si el código ya existe
          $stmt = $pdo->prepare('SELECT id FROM holiday_types WHERE code = ?');
          $stmt->execute([$code]);
          if ($stmt->fetch()) {
            $error = 'El código ya existe. Usa uno diferente.';
          } else {
            $stmt = $pdo->prepare('INSERT INTO holiday_types (code, label, color, sort_order) VALUES (?, ?, ?, ?)');
            $stmt->execute([$code, $label, $color, $sort_order]);
            $message = '✓ Tipo de festivo creado exitosamente.';
          }
        } else {
          // Edit
          $stmt = $pdo->prepare('UPDATE holiday_types SET label = ?, color = ?, sort_order = ? WHERE id = ?');
          $stmt->execute([$label, $color, $sort_order, $id]);
          $message = '✓ Tipo de festivo actualizado.';
        }
      } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
          $error = 'El código ya existe.';
        } else {
          $error = 'Error: ' . $e->getMessage();
        }
      }
    }
  } elseif ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    try {
      // Verificar que no haya festivos usando este tipo
      $stmt = $pdo->prepare('SELECT COUNT(*) as cnt FROM holidays WHERE type = (SELECT code FROM holiday_types WHERE id = ?)');
      $stmt->execute([$id]);
      $result = $stmt->fetch();
      
      if ($result['cnt'] > 0) {
        $error = 'No se puede eliminar este tipo porque hay ' . $result['cnt'] . ' festivo(s) usándolo.';
      } else {
        $stmt = $pdo->prepare('DELETE FROM holiday_types WHERE id = ?');
        $stmt->execute([$id]);
        $message = '✓ Tipo de festivo eliminado.';
      }
    } catch (Exception $e) {
      $error = 'Error: ' . $e->getMessage();
    }
  }
}

// Obtener todos los tipos
$stmt = $pdo->prepare('SELECT * FROM holiday_types ORDER BY sort_order, id');
$stmt->execute();
$types = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Gestión de Tipos de Festivos</title>
  <link rel="icon" type="image/svg+xml" href="images/favicon.svg">
  <link rel="stylesheet" href="styles.css">
  <style>
    .type-table { width: 100%; border-collapse: collapse; margin-top: 1rem; background: white; border: 1px solid #dee2e6; border-radius: 6px; overflow: hidden; }
    .type-table th, .type-table td { padding: 1rem 0.75rem; text-align: left; border-bottom: 1px solid #dee2e6; }
    .type-table th { background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); font-weight: 600; color: #333; }
    .type-table tbody tr:hover { background: #f8f9fa; }
    .type-table code { background: #f0f0f0; padding: 0.2rem 0.5rem; border-radius: 3px; font-family: 'Monaco', 'Courier New', monospace; }
    .color-swatch { width: 40px; height: 40px; border-radius: 6px; border: 2px solid #dee2e6; display: inline-block; vertical-align: middle; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
    .action-buttons { display: flex; gap: 0.5rem; }
    .btn { padding: 0.6rem 1.2rem; border: none; border-radius: 4px; font-size: 0.95rem; cursor: pointer; transition: all 0.25s ease; text-decoration: none; display: inline-block; font-weight: 500; }
    .btn-primary { background: #0056b3; color: white; box-shadow: 0 2px 4px rgba(0,86,179,0.2); }
    .btn-primary:hover { background: #004494; transform: translateY(-1px); box-shadow: 0 4px 8px rgba(0,86,179,0.3); }
    .btn-primary:active { transform: translateY(0); }
    .btn-secondary { background: #6c757d; color: white; }
    .btn-secondary:hover { background: #5a6268; }
    .btn-danger { background: #dc3545; color: white; }
    .btn-danger:hover { background: #c82333; }
    .btn-small { padding: 0.4rem 0.8rem; font-size: 0.85rem; }
    .btn-add-type { margin-bottom: 1.5rem; }
    .modal { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.6); z-index: 1000; align-items: center; justify-content: center; }
    .modal.active { display: flex; }
    .modal-content { background: white; padding: 2.5rem; border-radius: 12px; max-width: 500px; width: 90%; box-shadow: 0 20px 60px rgba(0,0,0,0.4); animation: slideUp 0.3s ease; }
    @keyframes slideUp { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
    .modal-header { font-size: 1.5rem; font-weight: 700; margin-bottom: 1.5rem; color: #333; }
    .modal-close { float: right; font-size: 1.8rem; cursor: pointer; background: none; border: none; color: #999; padding: 0; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 4px; transition: all 0.2s; }
    .modal-close:hover { background: #f0f0f0; color: #333; }
    .form-group { margin-bottom: 1.2rem; }
    .form-group label { display: block; margin-bottom: 0.6rem; font-weight: 600; color: #333; font-size: 0.95rem; }
    .form-group input, .form-group select { width: 100%; padding: 0.65rem 0.75rem; border: 1.5px solid #dee2e6; border-radius: 4px; font-size: 1rem; box-sizing: border-box; transition: all 0.2s; }
    .form-group input:focus, .form-group select:focus { border-color: #0056b3; outline: none; box-shadow: 0 0 0 4px rgba(0,86,179,0.1); background: white; }
    .form-group input:disabled { background: #f8f9fa; color: #666; }
    .color-input-wrapper { display: flex; gap: 0.75rem; align-items: center; }
    .color-input-wrapper input[type="color"] { width: 60px; height: 45px; cursor: pointer; border: 1.5px solid #dee2e6; border-radius: 4px; padding: 3px; }
    .color-input-wrapper input[type="color"]:focus { border-color: #0056b3; outline: none; }
    .color-input-wrapper input[type="text"] { flex: 1; font-family: 'Monaco', 'Courier New', monospace; font-size: 0.95rem; }
    .alert { padding: 1rem 1.2rem; border-radius: 6px; margin-bottom: 1.5rem; border-left: 4px solid; }
    .alert-success { background: #d4edda; color: #155724; border-left-color: #28a745; }
    .alert-error { background: #f8d7da; color: #721c24; border-left-color: #dc3545; }
    .stats { background: white; border: 1px solid #dee2e6; border-radius: 8px; padding: 1.5rem 2rem; margin-bottom: 2rem; }
    .stat-item { display: inline-block; margin-right: 2.5rem; }
    .stat-number { font-size: 2rem; font-weight: 700; color: #0056b3; }
    .stat-label { font-size: 0.9rem; color: #666; font-weight: 500; }
    .card { background: white; border: 1px solid #dee2e6; border-radius: 8px; padding: 1.5rem 2rem; margin-bottom: 1.5rem; }
    .card h1 { margin: 0; color: #333; }
    .card p { margin: 0; }
  </style>
</head>
<body class="page-holiday-types">
  <div class="app-container">
    <aside class="sidebar" id="mobileSidebar">
      <div class="sidebar-header">
        <div class="sidebar-brand-visual">
          <a class="sidebar-brand-logo logo" href="dashboard.php"><h1>GestionHoras</h1></a>
        </div>
        <button class="mobile-menu-toggle" id="mobileMenuClose" aria-label="Cerrar menú">✕</button>
      </div>
      <nav class="sidebar-menu">
        <div class="menu-section">
          <a class="menu-item" href="dashboard.php">Dashboard</a>
          <a class="menu-item" href="holidays.php">📅 Festivos y Ausencias</a>
          <a class="menu-item" href="index.php">Registro horario</a>
          <a class="menu-item" href="import.php">Importar Fichajes</a>
          <a class="menu-item" href="reports.php">Reportes</a>
          <a class="menu-item" href="settings.php">Configuración</a>
          
          <div class="menu-item menu-user" tabindex="0">
            <div class="user-avatar"><?php echo strtoupper(substr($user['username'],0,1)); ?></div>
            <span class="menu-user-name"><?php echo htmlspecialchars($user['username']); ?></span>
            <div class="menu-user-dropdown" role="menu">
              <a class="dropdown-item" href="profile.php">👤 Perfil</a>
              <a class="dropdown-item" href="data_quality.php">📊 Calidad de Datos</a>
              <a class="dropdown-item" href="logout.php">🚪 Salir</a>
            </div>
          </div>
        </div>
      </nav>
    </aside>

    <div class="main-content">
      <header class="header">
        <button class="mobile-menu-toggle" id="mobileMenuOpen" aria-label="Abrir menú">☰</button>
        <div class="header-brand"><a class="header-brand-logo" href="dashboard.php"></a></div>
        <div class="header-actions"></div>
      </header>

      <script>
        document.addEventListener('DOMContentLoaded', function(){
          const sidebar = document.getElementById('mobileSidebar');
          const openBtn = document.getElementById('mobileMenuOpen');
          const closeBtn = document.getElementById('mobileMenuClose');
          if (!sidebar || !openBtn) return;
          openBtn.addEventListener('click', function(e) { e.preventDefault(); e.stopPropagation(); sidebar.classList.add('open'); });
          if (closeBtn) closeBtn.addEventListener('click', function(e) { e.preventDefault(); sidebar.classList.remove('open'); });
          const links = sidebar.querySelectorAll('a.menu-item');
          links.forEach(link => { link.addEventListener('click', function() { setTimeout(() => sidebar.classList.remove('open'), 100); }); });
          sidebar.addEventListener('click', function(e) { if (e.target === sidebar) sidebar.classList.remove('open'); });
          document.addEventListener('click', function(e){
            const mu = document.querySelector('.menu-user');
            if(!mu) return;
            if (mu.contains(e.target)) { e.stopPropagation(); mu.classList.toggle('open'); }
            else { mu.classList.remove('open'); }
          });
          document.addEventListener('keydown', function(e){
            if (e.key === 'Escape') {
              const mu = document.querySelector('.menu-user');
              if(mu) mu.classList.remove('open');
              sidebar.classList.remove('open');
            }
          });
        });
      </script>
    
      <div class="container">
        <div class="card">
          <h1>🏷️ Gestión de Tipos de Festivos</h1>
          <p style="color: #666; margin-top: 0.5rem;">Crea, edita y elimina tipos de festivos del sistema</p>
        </div>

        <?php if ($message): ?>
          <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
          <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="stats">
          <div class="stat-item">
            <div class="stat-number"><?php echo count($types); ?></div>
            <div class="stat-label">Tipos definidos</div>
          </div>
          <div class="stat-item">
            <div class="stat-number">
              <?php 
                $stmt = $pdo->prepare('SELECT COUNT(*) as cnt FROM holidays');
                $stmt->execute();
                $holidays_count = $stmt->fetch()['cnt'];
                echo $holidays_count;
              ?>
            </div>
            <div class="stat-label">Festivos totales</div>
          </div>
        </div>

        <button class="btn btn-primary btn-add-type" onclick="openAddModal()">➕ Agregar nuevo tipo</button>

        <?php if (empty($types)): ?>
          <div style="text-align: center; padding: 2rem; color: #666;">
            <p>No hay tipos de festivos definidos. Crea uno para empezar.</p>
          </div>
        <?php else: ?>
          <table class="type-table">
            <thead>
              <tr>
                <th style="width: 50px;">Color</th>
                <th>Código</th>
                <th>Nombre</th>
                <th>Orden</th>
                <th style="width: 150px;">Acciones</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($types as $type): ?>
                <tr>
                  <td>
                    <span class="color-swatch" style="background-color: <?php echo htmlspecialchars($type['color']); ?>;"></span>
                  </td>
                  <td>
                    <code><?php echo htmlspecialchars($type['code']); ?></code>
                  </td>
                  <td><?php echo htmlspecialchars($type['label']); ?></td>
                  <td><?php echo $type['sort_order']; ?></td>
                  <td>
                    <div class="action-buttons">
                      <button class="btn btn-primary btn-small" onclick="openEditModal(<?php echo $type['id']; ?>, '<?php echo htmlspecialchars($type['code']); ?>', '<?php echo htmlspecialchars($type['label']); ?>', '<?php echo htmlspecialchars($type['color']); ?>', <?php echo $type['sort_order']; ?>)">✏️ Editar</button>
                      <button class="btn btn-danger btn-small" onclick="deleteType(<?php echo $type['id']; ?>)">🗑️ Eliminar</button>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

      <footer class="footer">
        <div class="container small muted">&copy; 2026 <a href="https://github.com/matatunos/GestionHorasTrabajo" target="_blank" rel="noopener noreferrer">GestionHoras</a></div>
      </footer>
    </div>
  </div>

  <!-- Modal para agregar/editar tipo -->
  <div id="typeModal" class="modal">
    <div class="modal-content">
      <button class="modal-close" onclick="closeModal()">✕</button>
      <div class="modal-header" id="modalTitle">Agregar nuevo tipo</div>
      <form id="typeForm" method="POST">
        <input type="hidden" name="action" id="formAction" value="add">
        <input type="hidden" name="id" id="formId" value="">

        <div class="form-group">
          <label for="code">Código (único, ej: "festivo", "vacaciones")</label>
          <input type="text" id="code" name="code" required maxlength="50">
        </div>

        <div class="form-group">
          <label for="label">Nombre (ej: "Día de Fiesta")</label>
          <input type="text" id="label" name="label" required maxlength="100">
        </div>

        <div class="form-group">
          <label>Color</label>
          <div class="color-input-wrapper">
            <input type="color" id="colorPicker" value="#0f172a">
            <input type="text" id="colorHex" name="color" value="#0f172a" placeholder="#000000" maxlength="7">
          </div>
        </div>

        <div class="form-group">
          <label for="sortOrder">Orden (para ordenar en la lista)</label>
          <input type="number" id="sortOrder" name="sort_order" value="0">
        </div>

        <div style="display: flex; gap: 0.5rem; justify-content: flex-end;">
          <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancelar</button>
          <button type="submit" class="btn btn-primary">Guardar</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    const modal = document.getElementById('typeModal');
    const colorPicker = document.getElementById('colorPicker');
    const colorHex = document.getElementById('colorHex');

    // Sincronizar color picker con input hex
    colorPicker.addEventListener('change', function() {
      colorHex.value = this.value;
    });

    colorHex.addEventListener('change', function() {
      if (/^#[0-9a-fA-F]{6}$/.test(this.value)) {
        colorPicker.value = this.value;
      }
    });

    function openAddModal() {
      document.getElementById('modalTitle').innerText = 'Agregar nuevo tipo';
      document.getElementById('formAction').value = 'add';
      document.getElementById('formId').value = '';
      document.getElementById('code').value = '';
      document.getElementById('label').value = '';
      document.getElementById('colorPicker').value = '#0f172a';
      document.getElementById('colorHex').value = '#0f172a';
      document.getElementById('sortOrder').value = '0';
      modal.classList.add('active');
    }

    function openEditModal(id, code, label, color, sortOrder) {
      document.getElementById('modalTitle').innerText = 'Editar tipo de festivo';
      document.getElementById('formAction').value = 'edit';
      document.getElementById('formId').value = id;
      document.getElementById('code').value = code;
      document.getElementById('code').disabled = true; // No editar código
      document.getElementById('label').value = label;
      document.getElementById('colorPicker').value = color;
      document.getElementById('colorHex').value = color;
      document.getElementById('sortOrder').value = sortOrder;
      modal.classList.add('active');
    }

    function closeModal() {
      document.getElementById('code').disabled = false;
      modal.classList.remove('active');
    }

    function deleteType(id) {
      if (confirm('¿Seguro que deseas eliminar este tipo de festivo?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' + id + '">';
        document.body.appendChild(form);
        form.submit();
      }
    }

    // Cerrar modal al hacer click fuera
    modal.addEventListener('click', function(e) {
      if (e.target === modal) {
        closeModal();
      }
    });
  </script>
</body>
</html>
