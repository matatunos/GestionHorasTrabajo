#!/bin/bash
# reset-admin-password.sh
# Script para resetear la contraseña del usuario admin a "admin"

set -e

echo "╔════════════════════════════════════════════════════════════════╗"
echo "║     Reset de Contraseña del Usuario Admin                      ║"
echo "╚════════════════════════════════════════════════════════════════╝"

# Ir al directorio raíz del proyecto (padre de scripts/)
PROJECT_ROOT="$(dirname "$(dirname "$(readlink -f "$0")")")"
cd "$PROJECT_ROOT"

if [ ! -f .env ]; then
    echo "⚠️  Error: Archivo .env no existe"
    echo "   Crear desde .env.example: cp .env.example .env"
    exit 1
fi

echo ""
echo "Reseteando contraseña de admin a 'admin'..."
echo ""

php -r "
require 'db.php';
\$pdo = get_pdo();

if (!\$pdo) {
    echo '❌ Error: No se puede conectar a la BD' . PHP_EOL;
    exit(1);
}

// Hash para 'admin'
\$hash = password_hash('admin', PASSWORD_DEFAULT);

// Actualizar
\$stmt = \$pdo->prepare('UPDATE users SET password = ? WHERE username = ?');
\$stmt->execute([\$hash, 'admin']);

echo '✅ Contraseña reseteada' . PHP_EOL;
echo '   Usuario: admin' . PHP_EOL;
echo '   Contraseña: admin' . PHP_EOL;
echo '' . PHP_EOL;

// Verificar
\$stmt = \$pdo->prepare('SELECT id, username FROM users WHERE username = ?');
\$stmt->execute(['admin']);
\$user = \$stmt->fetch();

if (\$user) {
    echo '✅ Usuario verificado:' . PHP_EOL;
    echo '   ID: ' . \$user['id'] . PHP_EOL;
    echo '   Username: ' . \$user['username'] . PHP_EOL;
} else {
    echo '❌ Usuario admin no encontrado' . PHP_EOL;
    exit(1);
}
"

echo ""
echo "╔════════════════════════════════════════════════════════════════╗"
echo "║  ✅ Contraseña reseteada correctamente                         ║"
echo "╚════════════════════════════════════════════════════════════════╝"
echo ""
echo "Ahora puedes entrar con:"
echo "  Usuario: admin"
echo "  Contraseña: admin"
echo ""
echo "Se te pedirá cambiar la contraseña al primer acceso."
