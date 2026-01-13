#!/bin/bash
# Script para aplicar migración de extension_tokens en servidor remoto

set -e

# Configuración
DB_HOST="${DB_HOST:-localhost}"
DB_USER="${DB_USER:-app_user}"
DB_NAME="${DB_NAME:-gestion_horas}"
MIGRATION_FILE="deploy/migration_extension_tokens.sql"

echo "=================================================="
echo "Migration: Tabla de Extension Tokens"
echo "=================================================="
echo "Servidor: $DB_HOST"
echo "Base de datos: $DB_NAME"
echo "Usuario: $DB_USER"
echo ""

# Verificar que el archivo existe
if [ ! -f "$MIGRATION_FILE" ]; then
    echo "❌ Error: Archivo de migración no encontrado: $MIGRATION_FILE"
    exit 1
fi

echo "📝 Ejecutando migración..."
echo ""

# Ejecutar migración
mysql -h "$DB_HOST" -u "$DB_USER" -p "$DB_NAME" < "$MIGRATION_FILE"

echo ""
echo "✅ Migración completada"
echo ""

# Verificar que la tabla fue creada
echo "🔍 Verificando tabla..."
TABLE_COUNT=$(mysql -h "$DB_HOST" -u "$DB_USER" -p "$DB_NAME" -sN -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$DB_NAME' AND TABLE_NAME = 'extension_tokens';")

if [ "$TABLE_COUNT" = "1" ]; then
    echo "✅ Tabla extension_tokens creada exitosamente"
    echo ""
    echo "Estructura de la tabla:"
    mysql -h "$DB_HOST" -u "$DB_USER" -p "$DB_NAME" -e "DESCRIBE extension_tokens;"
else
    echo "❌ Error: Tabla extension_tokens no fue creada"
    exit 1
fi

echo ""
echo "=================================================="
echo "✅ Deployment completado con éxito"
echo "=================================================="
echo ""
echo "Próximos pasos:"
echo "1. Acceder a http://localhost/extension-tokens.php"
echo "2. Verificar que la página carga sin errores"
echo "3. Descargar extensión desde http://localhost/profile.php"
echo ""
