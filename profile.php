<?php
require_once __DIR__ . '/auth.php';
require_login();
require_once __DIR__ . '/db.php';

$user = current_user();
$pdo = get_pdo();
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
    $old = $_POST['old_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    if (empty($old) || empty($new) || empty($confirm)) {
        $err = 'Rellena todos los campos.';
    } elseif ($new !== $confirm) {
        $err = 'La nueva contraseña y la confirmación no coinciden.';
    } else {
        $stmt = $pdo->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$user['id']]);
        $row = $stmt->fetch();
        if (!$row || !password_verify($old, $row['password'])) {
            $err = 'Contraseña actual incorrecta.';
        } else {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $ust = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
            $ust->execute([$hash, $user['id']]);
            $msg = 'Contraseña actualizada correctamente.';
        }
    }
}
?>
<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Perfil</title><link rel="stylesheet" href="styles.css"></head><body>
<?php include __DIR__ . '/header.php'; ?>
<div class="container">
  <div class="card">
    <h3>Perfil de usuario</h3>
    <p>Usuario: <strong><?php echo htmlspecialchars($user['username']); ?></strong></p>
    
    <div style="background: #e7f3ff; border: 1px solid #007bff; border-radius: 5px; padding: 15px; margin-bottom: 20px;">
      <h4 style="margin-top: 0;">🧩 Extensión Chrome</h4>
      <p style="margin-bottom: 10px;">Descarga nuestra extensión de Chrome para importar datos de fichajes con un solo click.</p>
      <div style="display: flex; gap: 10px;">
        <a href="download-addon.php" class="btn btn-primary" download>📥 Descargar extensión</a>
        <a href="chrome-addon-help.php" class="btn btn-secondary">📖 Ver instrucciones</a>
        <a href="extension-tokens.php" class="btn btn-secondary">🔐 Gestionar tokens</a>
      </div>
      <style>
        .btn-secondary {
          background: #6c757d;
          color: white;
          padding: 8px 16px;
          border: none;
          border-radius: 4px;
          text-decoration: none;
          cursor: pointer;
          display: inline-block;
        }
        .btn-secondary:hover {
          background: #5a6268;
          text-decoration: none;
        }
      </style>
    </div>

    <?php if ($msg): ?><div class="alert alert-success"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger"><?php echo htmlspecialchars($err); ?></div><?php endif; ?>

    <form method="post" class="form-wrapper">
      <input type="hidden" name="action" value="change_password">
      <div class="form-grid single-column">
        <div class="form-group"><label class="form-label">Contraseña actual</label><input class="form-control" type="password" name="old_password" required></div>
        <div class="form-group"><label class="form-label">Nueva contraseña</label><input class="form-control" type="password" name="new_password" required></div>
        <div class="form-group"><label class="form-label">Confirmar nueva contraseña</label><input class="form-control" type="password" name="confirm_password" required></div>
      </div>
      <div class="form-actions mt-2"><button class="btn btn-primary" type="submit">Cambiar contraseña</button></div>
    </form>
  </div>
</div>
<?php include __DIR__ . '/footer.php'; ?>
</body></html>
