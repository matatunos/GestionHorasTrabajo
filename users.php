<?php
require_once __DIR__ . '/auth.php';
require_admin();
$pdo = get_pdo();

// add user
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['username'])){
    $u = $_POST['username'];
    $p = $_POST['password'];
    $is_admin = isset($_POST['is_admin']) ? 1 : 0;
    $hash = password_hash($p, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('INSERT INTO users (username,password,is_admin) VALUES (?,?,?)');
    $stmt->execute([$u,$hash,$is_admin]);
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
          <tr><th>ID</th><th>Usuario</th><th>Admin</th><th>Creado</th></tr>
        </thead>
        <tbody>
        <?php foreach($rows as $r): ?>
          <tr>
            <td><?php echo $r['id']?></td>
            <td><?php echo htmlspecialchars($r['username'])?></td>
            <td><?php echo $r['is_admin'] ? 'Sí' : '' ?></td>
            <td><?php echo $r['created_at']?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <h3>Añadir usuario</h3>
    <form method="post" class="form-wrapper">
      <div class="form-grid">
        <div class="form-group"><label class="form-label">Usuario</label><input class="form-control" name="username" required></div>
        <div class="form-group"><label class="form-label">Contraseña</label><input class="form-control" type="password" name="password" required></div>
        <div class="form-group"><label class="form-check"><input type="checkbox" name="is_admin"> Administrador</label></div>
      </div>
      <div class="form-actions" style="margin-top:12px;"><button class="btn-primary" type="submit">Añadir</button></div>
    </form>
    <p class="hint"><a href="index.php">Volver</a></p>
  </div>
</div>
<?php include __DIR__ . '/footer.php'; ?>
