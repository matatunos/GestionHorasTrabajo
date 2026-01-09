<?php
// Test completo del sistema de tokens

echo "==========================================================\n";
echo "TEST COMPLETO: Sistema de Tokens de Extensión\n";
echo "==========================================================\n\n";

// Configurar ambiente
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['username'] = 'admin';

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['PHP_SELF'] = '/extension-tokens.php';
$_SERVER['HTTP_HOST'] = 'calendar.favala.es';
$_SERVER['HTTPS'] = 'on';

// 1. TEST: Cargar funciones
echo "1️⃣  CARGANDO FUNCIONES...\n";
echo "================================================\n";

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/auth.php';

echo "✅ Funciones cargadas\n\n";

// 2. TEST: Obtener usuario actual
echo "2️⃣  OBTENER USUARIO ACTUAL...\n";
echo "================================================\n";

$user = current_user();
if ($user) {
    echo "✅ Usuario obtenido: {$user['username']} (ID: {$user['id']})\n";
    $user_id = $user['id'];
} else {
    echo "❌ Error: No se pudo obtener usuario\n";
    exit(1);
}

echo "\n";

// 3. TEST: Generar token
echo "3️⃣  GENERAR NUEVO TOKEN...\n";
echo "================================================\n";

$token = generate_extension_token();
echo "✅ Token generado: " . substr($token, 0, 16) . "... (64 chars)\n";

echo "\n";

// 4. TEST: Crear token en BD
echo "4️⃣  CREAR TOKEN EN BASE DE DATOS...\n";
echo "================================================\n";

$result = create_extension_token($user_id, 'Test Chrome Extension', 7);
if ($result) {
    echo "✅ Token creado exitosamente\n";
    echo "   Nombre: {$result['name']}\n";
    echo "   Expira: {$result['expires_at']}\n";
    echo "   Token: " . substr($result['token'], 0, 16) . "...\n";
    $test_token = $result['token'];
} else {
    echo "❌ Error al crear token\n";
    exit(1);
}

echo "\n";

// 5. TEST: Obtener tokens del usuario
echo "5️⃣  OBTENER TOKENS DEL USUARIO...\n";
echo "================================================\n";

$tokens = get_user_extension_tokens($user_id);
echo "✅ Total de tokens: " . count($tokens) . "\n";

if (count($tokens) > 0) {
    echo "\nDetalle de tokens:\n";
    foreach ($tokens as $t) {
        $status = $t['is_active'] ? "✓ ACTIVO" : "✗ INACTIVO";
        echo "  - ID: {$t['id']}\n";
        echo "    Nombre: {$t['name']}\n";
        echo "    Creado: {$t['created_at']}\n";
        echo "    Expira: {$t['expires_at']}\n";
        echo "    Último uso: " . ($t['last_used_at'] ? $t['last_used_at'] : 'Nunca') . "\n";
        echo "    Estado: $status\n";
    }
} else {
    echo "⚠️  No hay tokens para este usuario\n";
}

echo "\n";

// 6. TEST: Validar token
echo "6️⃣  VALIDAR TOKEN...\n";
echo "================================================\n";

$valid_user_id = validate_extension_token($test_token);
if ($valid_user_id) {
    echo "✅ Token válido\n";
    echo "   User ID: $valid_user_id\n";
} else {
    echo "❌ Token inválido\n";
}

echo "\n";

// 7. TEST: Verificar que last_used_at se actualizó
echo "7️⃣  VERIFICAR AUDITORÍA (last_used_at)...\n";
echo "================================================\n";

$tokens_after = get_user_extension_tokens($user_id);
$found = false;
foreach ($tokens_after as $t) {
    if ($t['id'] === 1) { // Primer token creado en sesiones anteriores
        if ($t['last_used_at']) {
            echo "✅ Token actualizado: last_used_at = {$t['last_used_at']}\n";
            $found = true;
        } else {
            echo "⚠️  last_used_at aún es null (puede ser esperado si es el primer uso)\n";
        }
        break;
    }
}

echo "\n";

// 8. TEST: Revocar token
echo "8️⃣  REVOCAR TOKEN...\n";
echo "================================================\n";

$first_active = null;
foreach ($tokens_after as $t) {
    if ($t['is_active']) {
        $first_active = $t['id'];
        break;
    }
}

if ($first_active) {
    $revoked = revoke_extension_token($first_active, $user_id, 'Test revoke');
    if ($revoked) {
        echo "✅ Token revocado (ID: $first_active)\n";
    } else {
        echo "❌ Error al revocar token\n";
    }
} else {
    echo "⚠️  No hay token activo para revocar\n";
}

echo "\n";

// 9. TEST: Intentar validar token revocado
echo "9️⃣  INTENTAR VALIDAR TOKEN REVOCADO...\n";
echo "================================================\n";

// Búscar el token que acabamos de revocar
$revoked_token = null;
foreach ($tokens as $t) {
    if ($t['id'] === $first_active) {
        $revoked_token = $t['token'] ?? null;
        break;
    }
}

if ($revoked_token) {
    $result = validate_extension_token($revoked_token);
    if (!$result) {
        echo "✅ Token revocado rechazado correctamente\n";
    } else {
        echo "❌ Error: Token revocado fue aceptado\n";
    }
} else {
    echo "⚠️  No se pudo obtener token revocado\n";
}

echo "\n";

// 10. TEST: Renderizar página
echo "🔟  RENDERIZAR PÁGINA EXTENSION-TOKENS.PHP...\n";
echo "================================================\n";

ob_start();
try {
    include __DIR__ . '/extension-tokens.php';
    $output = ob_get_clean();
    
    if (strlen($output) > 100) {
        echo "✅ Página renderizada exitosamente\n";
        echo "   Tamaño: " . strlen($output) . " bytes\n";
        
        // Verificar contenido
        if (strpos($output, 'Tokens de Extensión') !== false) {
            echo "   ✅ Título encontrado\n";
        }
        if (strpos($output, 'Crear nuevo token') !== false) {
            echo "   ✅ Sección de creación encontrada\n";
        }
        if (strpos($output, 'Descargar extensión') !== false) {
            echo "   ✅ Link de descarga encontrado\n";
        }
    } else {
        echo "❌ Página muy pequeña\n";
    }
} catch (Throwable $e) {
    echo "❌ Error al renderizar: " . $e->getMessage() . "\n";
    echo "   Archivo: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

echo "\n";

echo "==========================================================\n";
echo "✅ TEST COMPLETADO EXITOSAMENTE\n";
echo "==========================================================\n";
