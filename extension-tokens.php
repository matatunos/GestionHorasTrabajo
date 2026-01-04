<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib.php';
require_login();

$user = get_current_user();
$user_id = $user['id'];

// Obtener tokens del usuario
$tokens = get_user_extension_tokens($user_id);

// Manejar revocación de token
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['revoke_token_id'])) {
  $token_id = intval($_POST['revoke_token_id']);
  revoke_extension_token($token_id, $user_id, 'User revoked');
  header('Location: ' . $_SERVER['PHP_SELF']);
  exit;
}

require_once __DIR__ . '/header.php';
?>

<div class="container">
  <div class="card">
    <h2>🔐 Tokens de Extensión Chrome</h2>
    
    <p>Aquí puedes ver y gestionar los tokens de tu extensión Chrome. Cada token permite que una instalación de la extensión importe datos sin necesidad de estar logueado.</p>
    
    <div class="info-box" style="background: #e7f3ff; border-left: 3px solid #007bff; padding: 15px; margin: 20px 0;">
      <strong>ℹ️ ¿Cómo funcionan los tokens?</strong>
      <ul style="margin: 10px 0;">
        <li>Cada vez que descargas la extensión, se genera un token único</li>
        <li>El token se incrusta automáticamente en la descarga (config.js)</li>
        <li>La extensión usa el token para importar datos sin login</li>
        <li>Los tokens expiran en <strong>7 días</strong></li>
        <li><strong>No compartas el token</strong> - es responsabilidad tuya mantenerlo seguro</li>
      </ul>
    </div>

    <h3>📋 Tus tokens activos</h3>
    
    <?php if (empty($tokens)): ?>
      <p style="color: #666;">No tienes ningún token. Descarga la extensión desde tu perfil para crear uno.</p>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th>Nombre</th>
            <th>Creado</th>
            <th>Expira</th>
            <th>Último uso</th>
            <th>Estado</th>
            <th>Acción</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($tokens as $token): ?>
            <tr>
              <td>
                <strong><?php echo htmlspecialchars($token['name']); ?></strong>
              </td>
              <td>
                <small><?php echo date('d/m/Y H:i', strtotime($token['created_at'])); ?></small>
              </td>
              <td>
                <small>
                  <?php 
                    $expires = strtotime($token['expires_at']);
                    $now = time();
                    $days_left = ceil(($expires - $now) / 86400);
                    
                    if ($days_left > 0) {
                      echo date('d/m/Y', $expires);
                      echo '<br><span style="color: #666;">(' . $days_left . ' día' . ($days_left !== 1 ? 's' : '') . ')</span>';
                    } else {
                      echo '<span style="color: #dc3545;">Expirado</span>';
                    }
                  ?>
                </small>
              </td>
              <td>
                <small>
                  <?php 
                    if ($token['last_used_at']) {
                      echo date('d/m/Y H:i', strtotime($token['last_used_at']));
                    } else {
                      echo '<em style="color: #999;">Nunca</em>';
                    }
                  ?>
                </small>
              </td>
              <td>
                <?php if ($token['is_active']): ?>
                  <span style="color: #28a745; font-weight: bold;">✓ Activo</span>
                <?php else: ?>
                  <span style="color: #dc3545;">✗ Inactivo</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($token['is_active']): ?>
                  <form method="POST" style="display: inline;">
                    <input type="hidden" name="revoke_token_id" value="<?php echo $token['id']; ?>">
                    <button type="submit" class="btn btn-sm btn-danger" style="padding: 5px 10px; font-size: 12px;">Revocar</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <hr style="margin: 30px 0;">

    <h3>🆕 Crear nuevo token</h3>
    <p>Si necesitas otro token para una instalación adicional:</p>
    <a href="download-addon.php" class="btn btn-primary">📥 Descargar extensión (nuevo token)</a>

    <hr style="margin: 30px 0;">

    <h3>🔒 Seguridad</h3>
    <ul style="color: #666;">
      <li><strong>Tokens únicos:</strong> Cada descarga genera un token nuevo e independiente</li>
      <li><strong>Expiración automática:</strong> Los tokens expiran en 7 días para mayor seguridad</li>
      <li><strong>HTTPS obligatorio:</strong> Los tokens solo funcionan por conexiones HTTPS encriptadas</li>
      <li><strong>Revocación:</strong> Puedes revocar un token en cualquier momento desde esta página</li>
      <li><strong>Validación:</strong> Cada uso del token se registra en "Último uso"</li>
    </ul>

  </div>
</div>

<style>
  .info-box {
    border-radius: 5px;
  }
  
  .info-box ul {
    padding-left: 20px;
  }
  
  .info-box li {
    margin: 8px 0;
  }
  
  .table {
    width: 100%;
    border-collapse: collapse;
    margin: 15px 0;
  }
  
  .table th,
  .table td {
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid #ddd;
  }
  
  .table th {
    background: #f5f5f5;
    font-weight: bold;
  }
  
  .table tr:hover {
    background: #f9f9f9;
  }
  
  .btn {
    padding: 8px 16px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 14px;
    text-decoration: none;
    display: inline-block;
  }
  
  .btn-primary {
    background: #007bff;
    color: white;
  }
  
  .btn-primary:hover {
    background: #0056b3;
  }
  
  .btn-danger {
    background: #dc3545;
    color: white;
  }
  
  .btn-danger:hover {
    background: #c82333;
  }
  
  .btn-sm {
    padding: 5px 10px;
    font-size: 12px;
  }
  
  h3 {
    color: #333;
    margin-top: 25px;
    margin-bottom: 15px;
  }
</style>

<?php require_once __DIR__ . '/footer.php'; ?>
