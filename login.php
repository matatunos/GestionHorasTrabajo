<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

$error = '';
$show_first_access_warning = false;

// Si el usuario ya tiene sesión activa, redirigir al dashboard
if (current_user()) {
    header('Location: dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST'){
    $u = $_POST['username'] ?? '';
    $p = $_POST['password'] ?? '';
    if (do_login($u, $p)){
      // Si el login fue exitoso, redirigir al dashboard
      header('Location: dashboard.php'); 
      exit;
    } else {
        $error = 'Credenciales inválidas';
    }
}

// Mostrar el aviso SOLO en el acceso inicial sin error (es decir, es GET y sin errores)
if (empty($error) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Detectar si es realmente primer acceso: si la contraseña de admin no ha sido cambiada
    $pdo = get_pdo();
    if ($pdo) {
        $stmt = $pdo->prepare('SELECT id, password FROM users WHERE username = ? LIMIT 1');
        $stmt->execute(['admin']);
        $admin = $stmt->fetch();
        
        // Si existe admin y su contraseña es la default (hash de "admin"), mostrar aviso
        if ($admin && password_verify('admin', $admin['password'])) {
            $show_first_access_warning = true;
        }
    }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Login</title>
  <link rel="icon" type="image/svg+xml" href="images/favicon.svg">
  <link rel="stylesheet" href="styles.css">
</head>
<body>
  <div class="login-container">
    <div class="login-card">
      <div class="login-header"><div class="logo"><h1>GestionHoras</h1></div></div>
      <h2>Acceso</h2>
      <?php if($error): ?><div class="alert"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
      <?php if($show_first_access_warning && !$error): ?>
      <div class="alert alert-info" style="background-color: #e3f2fd; border-color: #2196f3; color: #0d47a1;">
        <strong>⚠️ Primer acceso:</strong> Si instalaste la aplicación recientemente, usa:<br>
        <strong>Usuario:</strong> admin<br>
        <strong>Contraseña:</strong> admin<br>
        <em>Deberás cambiar la contraseña después del primer inicio de sesión.</em>
      </div>
      <?php endif; ?>
      <form method="post" id="loginForm">
        <div class="form-group"><label class="form-label">Usuario <input class="form-control" name="username" required></label></div>
        <div class="form-group"><label class="form-label">Contraseña <input class="form-control" type="password" name="password" required id="passwordInput"></label></div>
        <div class="actions"><button class="btn btn-primary" type="submit">Entrar</button></div>
      </form>
      <script>
        document.getElementById('passwordInput').addEventListener('keypress', function(e) {
          if (e.key === 'Enter') {
            document.getElementById('loginForm').submit();
          }
        });
      </script>
    </div>
  </div>
</body>
</html>
