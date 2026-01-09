<?php
// Test simple de extension-tokens.php sin necesidad de HTTP

echo "Test de Extension Tokens\n";
echo "========================\n\n";

// Configurar ambiente
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['PHP_SELF'] = '/extension-tokens.php';
$_SESSION = [];

// Cargar archivos
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib.php';

echo "✅ Archivos cargados\n";

// Test 1: Generar token
echo "\n1️⃣  Generando token...\n";
$token = generate_extension_token();
echo "✅ Token generado: " . substr($token, 0, 16) . "... (64 chars: " . strlen($token) . ")\n";

// Test 2: Crear token en BD
echo "\n2️⃣  Creando token en BD para usuario 1...\n";
$result = create_extension_token(1, 'Test Token', 7);
if ($result) {
    echo "✅ Token creado\n";
    echo "   Expires: {$result['expires_at']}\n";
    echo "   Token: " . substr($result['token'], 0, 16) . "...\n";
} else {
    echo "❌ Error al crear token\n";
}

// Test 3: Obtener tokens del usuario
echo "\n3️⃣  Obteniendo tokens del usuario 1...\n";
$tokens = get_user_extension_tokens(1);
echo "✅ Tokens encontrados: " . count($tokens) . "\n";

if (count($tokens) > 0) {
    echo "\nDetalle de tokens:\n";
    foreach ($tokens as $t) {
        echo "  - ID: {$t['id']}, Nombre: {$t['name']}, Activo: " . ($t['is_active'] ? 'Sí' : 'No') . "\n";
    }
}

// Test 4: Validar token
echo "\n4️⃣  Validando token...\n";
if (count($tokens) > 0) {
    $valid_user_id = validate_extension_token($tokens[0]['token']);
    if ($valid_user_id) {
        echo "✅ Token válido, user_id: $valid_user_id\n";
    } else {
        echo "❌ Token inválido\n";
    }
}

// Test 5: Revocar token
echo "\n5️⃣  Revocando primer token...\n";
if (count($tokens) > 0) {
    $revoked = revoke_extension_token($tokens[0]['id'], 1, 'Test revoke');
    if ($revoked) {
        echo "✅ Token revocado\n";
    } else {
        echo "❌ Error al revocar\n";
    }
}

echo "\n========================\n";
echo "✅ Todos los tests completados\n";
