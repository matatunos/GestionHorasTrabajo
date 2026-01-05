<?php
/**
 * Script para limpiar datos de entrada del usuario actual
 * Usa: php clean_entries.php
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

// Simular login si se ejecuta desde CLI
if (php_sapi_name() === 'cli') {
  // No hay usuario en CLI, usar ID 1 por defecto o especificado
  $userId = isset($argv[1]) ? intval($argv[1]) : 1;
  echo "Limpiando entries para user_id: $userId\n";
} else {
  // Desde web, usar usuario autenticado
  require_login();
  $user = current_user();
  $userId = $user['id'];
  echo "Limpiando entries para usuario: " . htmlspecialchars($user['username']) . "\n";
}

try {
  $pdo = get_pdo();
  
  // Primero, contar cuántos registros hay
  $countStmt = $pdo->prepare('SELECT COUNT(*) as total FROM entries WHERE user_id = ?');
  $countStmt->execute([$userId]);
  $countResult = $countStmt->fetch();
  $totalBefore = $countResult['total'] ?? 0;
  
  echo "Registros antes: $totalBefore\n";
  
  if ($totalBefore > 0) {
    // Preguntar confirmación si es web
    if (php_sapi_name() !== 'cli') {
      echo "<div style='background: #fff3cd; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
      echo "<strong>⚠️ ADVERTENCIA:</strong><br>";
      echo "Se van a eliminar <strong>$totalBefore registros</strong> del usuario.<br>";
      echo "<form method='post' style='margin-top: 15px;'>";
      echo "<input type='hidden' name='confirm_clean' value='1'>";
      echo "<button type='submit' class='btn btn-danger' onclick='return confirm(\"¿Estás seguro de que quieres eliminar todos los registros?\")'>Confirmar eliminación</button>";
      echo "</form>";
      echo "</div>";
      
      if (empty($_POST['confirm_clean'])) {
        exit;
      }
    }
    
    // Eliminar todos los registros
    $deleteStmt = $pdo->prepare('DELETE FROM entries WHERE user_id = ?');
    $deleteStmt->execute([$userId]);
    
    // Contar después
    $countStmt = $pdo->prepare('SELECT COUNT(*) as total FROM entries WHERE user_id = ?');
    $countStmt->execute([$userId]);
    $countResult = $countStmt->fetch();
    $totalAfter = $countResult['total'] ?? 0;
    
    echo "✓ Registros eliminados: " . ($totalBefore - $totalAfter) . "\n";
    echo "Registros después: $totalAfter\n";
    
    if (php_sapi_name() !== 'cli') {
      echo "<div style='background: #d4edda; padding: 15px; border-radius: 5px; margin: 20px 0; color: #155724;'>";
      echo "<strong>✓ Base de datos limpiada correctamente.</strong><br>";
      echo "Ya puedes ir a <a href='import.php'>importar los datos de nuevo</a>.";
      echo "</div>";
    }
  } else {
    echo "No hay registros para eliminar.\n";
  }
} catch (Exception $e) {
  echo "Error: " . $e->getMessage() . "\n";
  if (php_sapi_name() !== 'cli') {
    echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px; margin: 20px 0; color: #721c24;'>";
    echo "<strong>Error:</strong> " . htmlspecialchars($e->getMessage());
    echo "</div>";
  }
  exit(1);
}
?>
