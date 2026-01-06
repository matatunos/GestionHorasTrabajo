<?php
require_once __DIR__ . '/auth.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST'){
    $u = $_POST['username'] ?? '';
    $p = $_POST['password'] ?? '';
    if (do_login($u, $p)){
      header('Location: dashboard.php'); exit;
    } else {
        $error = 'Credenciales inválidas';
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
