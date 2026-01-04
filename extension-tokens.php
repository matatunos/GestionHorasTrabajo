<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib.php';
require_login();

$user = current_user();
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
    <div class="card-header">
      <h2 class="card-title">🔐 Tokens de Extensión Chrome</h2>
    </div>
    
    <div class="card-body">
      <p>Aquí puedes ver y gestionar los tokens de tu extensión Chrome. Cada token permite que una instalación de la extensión importe datos sin necesidad de estar logueado.</p>
      
      <div style="background: rgba(29, 209, 161, 0.1); border: 1px solid rgba(29, 209, 161, 0.3); border-left: 4px solid var(--brand-accent); border-radius: 6px; padding: 15px; margin: 20px 0;">
        <strong style="color: var(--brand-accent);">ℹ️ ¿Cómo funcionan los tokens?</strong>
        <ul style="margin: 10px 0 0 0; padding-left: 20px; color: var(--text-secondary);">
          <li>Cada vez que descargas la extensión, se genera un token único</li>
          <li>El token se incrusta automáticamente en la descarga (config.js)</li>
          <li>La extensión usa el token para importar datos sin login</li>
          <li>Los tokens expiran en <strong>7 días</strong></li>
          <li><strong>No compartas el token</strong> - es responsabilidad tuya mantenerlo seguro</li>
        </ul>
      </div>

      <h3 style="margin-top: 30px; margin-bottom: 15px;">📋 Tus tokens activos</h3>
      
      <?php if (empty($tokens)): ?>
        <p style="color: var(--text-secondary); padding: 15px; background: var(--bg-secondary); border-radius: 6px;">
          No tienes ningún token. Descarga la extensión desde tu perfil para crear uno.
        </p>
      <?php else: ?>
        <div style="overflow-x: auto;">
          <table style="width: 100%; border-collapse: collapse; margin: 15px 0;">
            <thead>
              <tr style="background: var(--bg-secondary); border-bottom: 1px solid var(--border-color);">
                <th style="padding: 12px; text-align: left; font-weight: 600; color: var(--text-primary);">Nombre</th>
                <th style="padding: 12px; text-align: left; font-weight: 600; color: var(--text-primary);">Creado</th>
                <th style="padding: 12px; text-align: left; font-weight: 600; color: var(--text-primary);">Expira</th>
                <th style="padding: 12px; text-align: left; font-weight: 600; color: var(--text-primary);">Último uso</th>
                <th style="padding: 12px; text-align: left; font-weight: 600; color: var(--text-primary);">Estado</th>
                <th style="padding: 12px; text-align: left; font-weight: 600; color: var(--text-primary);">Acción</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($tokens as $token): ?>
                <tr style="border-bottom: 1px solid var(--border-light);">
                  <td style="padding: 12px; color: var(--text-primary);"><strong><?php echo htmlspecialchars($token['name']); ?></strong></td>
                  <td style="padding: 12px; color: var(--text-secondary); font-size: 13px;"><?php echo date('d/m/Y H:i', strtotime($token['created_at'])); ?></td>
                  <td style="padding: 12px; color: var(--text-secondary); font-size: 13px;">
                    <?php 
                      $expires = strtotime($token['expires_at']);
                      $now = time();
                      $days_left = ceil(($expires - $now) / 86400);
                      
                      if ($days_left > 0) {
                        echo date('d/m/Y', $expires);
                        echo '<br><span style="color: var(--text-muted); font-size: 12px;">(' . $days_left . ' día' . ($days_left !== 1 ? 's' : '') . ')</span>';
                      } else {
                        echo '<span style="color: var(--danger-color);">Expirado</span>';
                      }
                    ?>
                  </td>
                  <td style="padding: 12px; color: var(--text-secondary); font-size: 13px;">
                    <?php 
                      if ($token['last_used_at']) {
                        echo date('d/m/Y H:i', strtotime($token['last_used_at']));
                      } else {
                        echo '<em style="color: var(--text-muted);">Nunca</em>';
                      }
                    ?>
                  </td>
                  <td style="padding: 12px;">
                    <?php if ($token['is_active']): ?>
                      <span style="color: var(--success-color); font-weight: bold;">✓ Activo</span>
                    <?php else: ?>
                      <span style="color: var(--danger-color);">✗ Inactivo</span>
                    <?php endif; ?>
                  </td>
                  <td style="padding: 12px;">
                    <?php if ($token['is_active']): ?>
                      <form method="POST" style="display: inline;">
                        <input type="hidden" name="revoke_token_id" value="<?php echo $token['id']; ?>">
                        <button type="submit" class="btn btn-sm" style="padding: 6px 12px; font-size: 12px; background: var(--danger-color); color: white; border: none; border-radius: 4px; cursor: pointer;">Revocar</button>
                      </form>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

      <hr style="margin: 30px 0; border: none; border-top: 1px solid var(--border-light);">

      <h3 style="margin-top: 30px; margin-bottom: 15px;">🆕 Crear nuevo token</h3>
      <p style="color: var(--text-secondary);">Si necesitas otro token para una instalación adicional:</p>
      <a href="download-addon.php" class="btn btn-primary" style="display: inline-flex;">📥 Descargar extensión (nuevo token)</a>

      <hr style="margin: 30px 0; border: none; border-top: 1px solid var(--border-light);">

      <h3 style="margin-top: 30px; margin-bottom: 15px;">🔒 Seguridad</h3>
      <ul style="color: var(--text-secondary); padding-left: 20px;">
        <li style="margin: 8px 0;"><strong>Tokens únicos:</strong> Cada descarga genera un token nuevo e independiente</li>
        <li style="margin: 8px 0;"><strong>Expiración automática:</strong> Los tokens expiran en 7 días para mayor seguridad</li>
        <li style="margin: 8px 0;"><strong>HTTPS obligatorio:</strong> Los tokens solo funcionan por conexiones HTTPS encriptadas</li>
        <li style="margin: 8px 0;"><strong>Revocación:</strong> Puedes revocar un token en cualquier momento desde esta página</li>
        <li style="margin: 8px 0;"><strong>Validación:</strong> Cada uso del token se registra en "Último uso"</li>
      </ul>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
