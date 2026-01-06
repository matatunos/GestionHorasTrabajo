<?php
require_once __DIR__ . '/auth.php';
require_admin();
$pdo = get_pdo();

// add user
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['username'])){
  require_once __DIR__ . '/lib.php';
  $u = $_POST['username'];
  $p = $_POST['password'];
  $is_admin = post_flag('is_admin');
  $hash = password_hash($p, PASSWORD_DEFAULT);
  $stmt = $pdo->prepare('INSERT INTO users (username,password,is_admin) VALUES (?,?,?)');
  $stmt->execute([$u,$hash,$is_admin]);
  header('Location: users.php'); exit;
}

// reset password
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['reset_user_id'])){
  require_once __DIR__ . '/lib.php';
  $user_id = $_POST['reset_user_id'];
  $new_password = $_POST['new_password'] ?? 'Temporal123!';
  $hash = password_hash($new_password, PASSWORD_DEFAULT);
  $stmt = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
  $stmt->execute([$hash, $user_id]);
  header('Location: users.php'); exit;
}

$rows = $pdo->query('SELECT id,username,is_admin,created_at FROM users ORDER BY id')->fetchAll();
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Usuarios</title>
  <link rel="icon" type="image/svg+xml" href="images/favicon.svg">
  <link rel="stylesheet" href="styles.css">
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>
<div class="container">
  <div class="card">
    <h2>Gestión de Usuarios</h2>
    <div class="table-responsive">
      <table class="sheet">
        <thead>
          <tr><th>ID</th><th>Usuario</th><th>Admin</th><th>Creado</th><th>Acciones</th></tr>
        </thead>
        <tbody>
        <?php foreach($rows as $r): ?>
          <tr>
            <td><?php echo $r['id']?></td>
            <td><?php echo htmlspecialchars($r['username'])?></td>
            <td><?php echo $r['is_admin'] ? 'Sí' : '' ?></td>
            <td><?php echo $r['created_at']?></td>
            <td><button class="btn btn-sm" type="button" onclick="openResetModal(<?php echo $r['id']; ?>, '<?php echo htmlspecialchars($r['username']); ?>')">Reset clave</button></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <button class="btn btn-primary" id="open-add-user-btn" type="button" style="margin-top: 1rem;">+ Añadir usuario</button>
  </div>
</div>

<!-- Modal for adding a user -->
<div id="userModalOverlay" class="modal-overlay" aria-hidden="true" style="display:none;">
  <div id="userModal" class="modal-dialog" role="dialog" aria-modal="true">
    <div class="modal-header">
      <h3 class="modal-title">Añadir usuario</h3>
    </div>
    <div class="modal-body">
      <form id="add-user-form" method="post" class="row-form">
        <div style="margin-bottom: 1rem;">
          <label class="form-label">Usuario</label>
          <input class="form-control" name="username" required>
        </div>
        <div style="margin-bottom: 1rem;">
          <label class="form-label">Contraseña</label>
          <input class="form-control" type="password" name="password" required>
        </div>
        <div style="margin-bottom: 1rem;">
          <label class="form-label"><input type="checkbox" name="is_admin" value="1"> Administrador</label>
        </div>
        <div class="form-actions modal-actions mt-2">
          <button class="btn btn-secondary" type="button" id="closeUserModal">Cancelar</button>
          <button class="btn btn-primary" type="submit">Añadir</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal for resetting password -->
<div id="resetModalOverlay" class="modal-overlay" aria-hidden="true" style="display:none;">
  <div id="resetModal" class="modal-dialog" role="dialog" aria-modal="true">
    <div class="modal-header">
      <h3 class="modal-title">Resetear contraseña</h3>
    </div>
    <div class="modal-body">
      <p>¿Resetear la contraseña de <strong id="resetUsername"></strong>?</p>
      <form id="reset-user-form" method="post" class="row-form">
        <input type="hidden" name="reset_user_id" id="resetUserId">
        <div style="margin-bottom: 1rem;">
          <label class="form-label">Nueva contraseña (dejar vacío para usar "Temporal123!")</label>
          <input class="form-control" type="password" name="new_password" placeholder="Dejar vacío para contraseña temporal">
        </div>
        <div class="form-actions modal-actions mt-2">
          <button class="btn btn-secondary" type="button" id="closeResetModal">Cancelar</button>
          <button class="btn btn-primary" type="submit">Resetear</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
  console.log('users.php script running');
  const userModalOverlay = document.getElementById('userModalOverlay');
  const closeUserModalBtn = document.getElementById('closeUserModal');
  const openAddUserBtn = document.getElementById('open-add-user-btn');
  const resetModalOverlay = document.getElementById('resetModalOverlay');
  const closeResetModalBtn = document.getElementById('closeResetModal');

  function openUserModal(){
    if (!userModalOverlay) return;
    console.log('opening add user modal');
    userModalOverlay.style.display = 'flex';
    userModalOverlay.setAttribute('aria-hidden', 'false');
  }
  function closeUserModal(){
    if (!userModalOverlay) return;
    userModalOverlay.style.display = 'none';
    userModalOverlay.setAttribute('aria-hidden', 'true');
  }
  function openResetModal(userId, username){
    if (!resetModalOverlay) return;
    console.log('opening reset modal for', userId, username);
    document.getElementById('resetUserId').value = userId;
    document.getElementById('resetUsername').textContent = username;
    resetModalOverlay.style.display = 'flex';
    resetModalOverlay.setAttribute('aria-hidden', 'false');
  }
  function closeResetModal(){
    if (!resetModalOverlay) return;
    resetModalOverlay.style.display = 'none';
    resetModalOverlay.setAttribute('aria-hidden', 'true');
  }

  if (openAddUserBtn) openAddUserBtn.addEventListener('click', openUserModal);
  if (closeUserModalBtn) closeUserModalBtn.addEventListener('click', closeUserModal);
  if (closeResetModalBtn) closeResetModalBtn.addEventListener('click', closeResetModal);
  if (userModalOverlay) userModalOverlay.addEventListener('click', function(e){
    if (e.target === userModalOverlay) closeUserModal();
  });
  if (resetModalOverlay) resetModalOverlay.addEventListener('click', function(e){
    if (e.target === resetModalOverlay) closeResetModal();
  });
</script>

<?php include __DIR__ . '/footer.php'; ?>
