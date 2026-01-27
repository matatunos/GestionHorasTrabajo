#!/bin/bash
# audit-dependencies.sh
# Script para auditar y verificar dependencias del proyecto

set -e

echo "╔════════════════════════════════════════════════════════════════╗"
echo "║          🔍 Auditoría de Dependencias                          ║"
echo "╚════════════════════════════════════════════════════════════════╝"

# Colores
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Función para imprimir estado
check() {
  if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓${NC} $1"
  else
    echo -e "${RED}✗${NC} $1"
    exit 1
  fi
}

echo ""
echo "📦 DEPENDENCIAS DE COMPOSER"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

# Verificar que composer.json existe
test -f composer.json
check "composer.json existe"

# Mostrar dependencias
echo ""
echo "Dependencias requeridas:"
grep -A 2 '"require"' composer.json

# Verificar si composer.lock existe
if [ -f composer.lock ]; then
  echo -e "\n${GREEN}✓${NC} composer.lock existe (dependencias pinned)"
else
  echo -e "\n${YELLOW}⚠${NC}  composer.lock no existe (instalar con 'composer install')"
fi

# Verificar vendor
if [ -d vendor ]; then
  echo -e "${GREEN}✓${NC} vendor/ directorio existe"
  VENDOR_SIZE=$(du -sh vendor/ | cut -f1)
  echo "  Tamaño: $VENDOR_SIZE"
else
  echo -e "${YELLOW}⚠${NC}  vendor/ no existe - ejecutar 'composer install'"
fi

echo ""
echo "🔐 SEGURIDAD"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

# Verificar composer.lock en .gitignore
grep -q "composer.lock" .gitignore 2>/dev/null
check ".gitignore ignora composer.lock"

# Verificar vendor en .gitignore
grep -q "^vendor/" .gitignore 2>/dev/null
check ".gitignore ignora vendor/"

echo ""
echo "📋 REQUISITOS DEL PROYECTO"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

# Verificar PHP version
PHP_VERSION=$(php -v | head -1 | awk '{print $2}')
echo "PHP version: $PHP_VERSION"

if php -v | grep -q "^PHP 8\.[1-9]"; then
  echo -e "${GREEN}✓${NC} PHP 8.1+ requerido"
else
  echo -e "${YELLOW}⚠${NC}  PHP 8.1+ requerido (actual: $PHP_VERSION)"
fi

# Verificar extensiones PHP
echo ""
echo "Extensiones PHP requeridas:"

REQUIRED_EXTENSIONS=(
  "PDO" "pdo_mysql" "json" "mbstring" "fileinfo"
)

for ext in "${REQUIRED_EXTENSIONS[@]}"; do
  if php -m | grep -qi "$ext" || php -r "extension_loaded('$ext') and die(0);" 2>/dev/null; then
    echo -e "  ${GREEN}✓${NC} $ext"
  else
    echo -e "  ${YELLOW}⚠${NC}  $ext no disponible"
  fi
done

echo ""
echo "🧪 VERIFICACIONES ADICIONALES"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

# Verificar autoloading
if [ -f vendor/autoload.php ]; then
  echo -e "${GREEN}✓${NC} Autoloader disponible"
  
  # Intentar cargar
  if php -r "require 'vendor/autoload.php';" 2>/dev/null; then
    echo -e "${GREEN}✓${NC} Autoloader funciona"
  else
    echo -e "${RED}✗${NC} Error al cargar autoloader"
    exit 1
  fi
else
  echo -e "${YELLOW}⚠${NC}  vendor/autoload.php no existe"
fi

# Verificar librerías críticas
echo ""
echo "Librerías del proyecto:"

REQUIRED_LIBS=(
  "lib/JWTHelper.php" "lib/SecurityHeaders.php" "lib/LogAnalytics.php"
)

for lib in "${REQUIRED_LIBS[@]}"; do
  if [ -f "$lib" ]; then
    echo -e "  ${GREEN}✓${NC} $lib"
  else
    echo -e "  ${RED}✗${NC} $lib NO ENCONTRADO"
    exit 1
  fi
done

echo ""
echo "╔════════════════════════════════════════════════════════════════╗"
echo "║                  ✅ Auditoría Completada                       ║"
echo "╚════════════════════════════════════════════════════════════════╝"

echo ""
echo "📝 Recomendaciones:"
echo "   • Para desarrollo: composer install"
echo "   • Para producción: composer install --no-dev"
echo "   • Para actualizar: composer update"
echo "   • Revisar SECURITY.md para mejores prácticas"
