<?php
require_once __DIR__ . '/auth.php';
require_login();

$user = current_user();
$error = '';
$success = '';

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    if (empty($newPassword)) {
        $error = 'La nueva contraseña no puede estar vacía';
    } elseif (strlen($newPassword) < 4) {
        $error = 'La contraseña debe tener al menos 4 caracteres';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'Las contraseñas no coinciden';
    } elseif ($newPassword === 'admin') {
        $error = 'No puedes usar "admin" como contraseña';
    } else {
        // Verify current password
        $pdo = get_pdo();
        $stmt = $pdo->prepare('SELECT password FROM users WHERE id = ?');
        $stmt->execute([$user['id']]);
        $userData = $stmt->fetch();
        
        if (!password_verify($currentPassword, $userData['password'])) {
            $error = 'La contraseña actual es incorrecta';
        } else {
            // Update password
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
            $stmt->execute([$hashedPassword, $user['id']]);
            
            // Clear the force password change flag
            clear_password_change_flag();
            
            $success = 'Contraseña cambiada exitosamente';
            header('Refresh: 2; url=dashboard.php');
        }
    }
}

$forceChange = needs_password_change();
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Cambiar Contraseña</title>
  <link rel="icon" type="image/svg+xml" href="images/favicon.svg">
  <link rel="stylesheet" href="styles.css">
  <style>
    .change-password-container {
      display: flex;
      justify-content: center;
      align-items: center;
      min-height: 100vh;
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      padding: 1rem;
    }
    .change-password-card {
      background: white;
      border-radius: 12px;
      box-shadow: 0 10px 40px rgba(0,0,0,0.2);
      padding: 2rem;
      width: 100%;
      max-width: 500px;
    }
    .warning-box {
      background-color: #fff3cd;
      border: 2px solid #ffc107;
      border-radius: 8px;
      padding: 1rem;
      margin-bottom: 1.5rem;
    }
    .warning-box h3 {
      margin-top: 0;
      color: #856404;
    }
    .alert-success {
      background-color: #d4edda;
      border-color: #c3e6cb;
      color: #155724;
      padding: 1rem;
      border-radius: 8px;
      margin-bottom: 1rem;
    }
  </style>
</head>
<body>
  <div class="change-password-container">
    <div class="change-password-card">
      <h2>🔐 Cambiar Contraseña</h2>
      
      <?php if ($forceChange): ?>
      <div class="warning-box">
        <h3>⚠️ Cambio de contraseña obligatorio</h3>
        <p>Estás usando la contraseña por defecto. Por razones de seguridad, debes cambiarla antes de continuar.</p>
      </div>
      <?php endif; ?>
      
      <?php if ($error): ?>
        <div class="alert"><?php echo htmlspecialchars($error); ?></div>
      <?php endif; ?>
      
      <?php if ($success): ?>
        <div class="alert-success">
          <?php echo htmlspecialchars($success); ?>
          <br>Redirigiendo al dashboard...
        </div>
      <?php endif; ?>
      
      <form method="post">
        <div class="form-group">
          <label class="form-label">
            Contraseña actual
            <input class="form-control" type="password" name="current_password" required autofocus>
          </label>
        </div>
        
        <div class="form-group">
          <label class="form-label">
            Nueva contraseña (mínimo 4 caracteres)
            <input class="form-control" type="password" name="new_password" required minlength="4">
          </label>
        </div>
        
        <div class="form-group">
          <label class="form-label">
            Confirmar nueva contraseña
            <input class="form-control" type="password" name="confirm_password" required minlength="4">
          </label>
        </div>
        
        <div class="actions">
          <button class="btn btn-primary" type="submit">Cambiar Contraseña</button>
          <?php if (!$forceChange): ?>
          <a href="dashboard.php" class="btn">Cancelar</a>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>
</body>
</html>
