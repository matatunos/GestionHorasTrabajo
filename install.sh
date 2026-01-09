#!/bin/bash

# Script de instalación completa para GestionHorasTrabajo
# Para sistemas Debian/Ubuntu

set -e  # Exit on error

# Colores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

clear
echo -e "${BLUE}"
echo "╔════════════════════════════════════════════════════════════╗"
echo "║                                                            ║"
echo "║        GestionHorasTrabajo - Script de Instalación        ║"
echo "║                                                            ║"
echo "╚════════════════════════════════════════════════════════════╝"
echo -e "${NC}\n"

# Verificar que se ejecuta como root
if [ "$EUID" -ne 0 ]; then 
    echo -e "${RED}Este script debe ejecutarse como root o con sudo${NC}"
    exit 1
fi

# Detectar el directorio del script
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

echo -e "${YELLOW}Directorio de instalación: $SCRIPT_DIR${NC}\n"

# ============================================
# PASO 1: Instalar dependencias del sistema
# ============================================
echo -e "${BLUE}[1/8] Verificando e instalando dependencias del sistema...${NC}"

# Lista de paquetes necesarios
REQUIRED_PACKAGES=(
    "apache2"
    "mysql-server"
    "php8.3"
    "php8.3-mysql"
    "php8.3-xml"
    "php8.3-mbstring"
    "php8.3-curl"
    "php8.3-zip"
    "php8.3-gd"
    "php8.3-intl"
    "libapache2-mod-php8.3"
    "composer"
    "git"
)

PACKAGES_TO_INSTALL=()

for package in "${REQUIRED_PACKAGES[@]}"; do
    if ! dpkg -l | grep -q "^ii  $package "; then
        PACKAGES_TO_INSTALL+=("$package")
    fi
done

if [ ${#PACKAGES_TO_INSTALL[@]} -gt 0 ]; then
    echo -e "${YELLOW}Instalando paquetes faltantes: ${PACKAGES_TO_INSTALL[*]}${NC}"
    apt-get update -qq
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq "${PACKAGES_TO_INSTALL[@]}" > /dev/null 2>&1
    echo -e "${GREEN}✓ Paquetes instalados${NC}"
else
    echo -e "${GREEN}✓ Todas las dependencias ya están instaladas${NC}"
fi

# Habilitar módulos de Apache
a2enmod rewrite php8.3 > /dev/null 2>&1 || true
echo -e "${GREEN}✓ Módulos de Apache habilitados${NC}\n"

# ============================================
# PASO 2: Configurar MySQL
# ============================================
echo -e "${BLUE}[2/8] Configuración de MySQL...${NC}"

# Iniciar MySQL si no está corriendo
systemctl start mysql 2>/dev/null || true

echo -e "${YELLOW}Ingresa la contraseña de MySQL root (presiona Enter si es vacía):${NC}"
read -s MYSQL_ROOT_PASS
echo ""

# Verificar conexión
if [ -z "$MYSQL_ROOT_PASS" ]; then
    MYSQL_CMD="mysql -u root"
else
    MYSQL_CMD="mysql -u root -p$MYSQL_ROOT_PASS"
fi

if ! $MYSQL_CMD -e "SELECT 1" &>/dev/null; then
    echo -e "${RED}Error: No se pudo conectar a MySQL. Verifica la contraseña.${NC}"
    exit 1
fi
echo -e "${GREEN}✓ Conexión a MySQL exitosa${NC}\n"

# ============================================
# PASO 3: Configurar base de datos
# ============================================
echo -e "${BLUE}[3/8] Configuración de la base de datos...${NC}"

DB_NAME="gestion_horas"
echo -e "${YELLOW}Nombre de la base de datos [${DB_NAME}]:${NC}"
read -r DB_NAME_INPUT
DB_NAME="${DB_NAME_INPUT:-$DB_NAME}"

echo -e "${YELLOW}Usuario de la aplicación [app_user]:${NC}"
read -r DB_USER_INPUT
DB_USER="${DB_USER_INPUT:-app_user}"

echo -e "${YELLOW}Contraseña para el usuario de la aplicación [app_pass]:${NC}"
read -s DB_PASS_INPUT
DB_PASS="${DB_PASS_INPUT:-app_pass}"
echo ""

# Eliminar y recrear base de datos
echo -e "${YELLOW}Eliminando base de datos existente (si existe)...${NC}"
$MYSQL_CMD -e "DROP DATABASE IF EXISTS $DB_NAME;" 2>/dev/null
echo -e "${GREEN}✓ Base de datos eliminada${NC}"

echo -e "${YELLOW}Creando base de datos nueva...${NC}"
$MYSQL_CMD -e "CREATE DATABASE $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
echo -e "${GREEN}✓ Base de datos '$DB_NAME' creada${NC}"

# Crear schema
echo -e "${YELLOW}Creando tablas...${NC}"
$MYSQL_CMD $DB_NAME << 'EOSQL'
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(100) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  is_admin TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS entries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  date DATE NOT NULL,
  start TIME NULL,
  coffee_out TIME NULL,
  coffee_in TIME NULL,
  lunch_out TIME NULL,
  lunch_in TIME NULL,
  end TIME NULL,
  note TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY user_date (user_id,date),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS app_config (
  k VARCHAR(100) PRIMARY KEY,
  v TEXT
);

CREATE TABLE IF NOT EXISTS incidents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  date DATE NOT NULL,
  incident_type ENUM('full_day', 'hours') NOT NULL DEFAULT 'hours',
  hours_lost INT NULL COMMENT 'Minutes lost (only for hours type)',
  reason TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  KEY user_date (user_id, date)
);

CREATE TABLE IF NOT EXISTS app_settings (
  name VARCHAR(191) PRIMARY KEY,
  value TEXT NOT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS year_configs (
  year INT PRIMARY KEY,
  config JSON NOT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS extension_tokens (
  id INT PRIMARY KEY AUTO_INCREMENT,
  user_id INT NOT NULL,
  token VARCHAR(64) UNIQUE NOT NULL,
  name VARCHAR(255),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  expires_at TIMESTAMP NOT NULL,
  last_used_at TIMESTAMP NULL,
  revoked_at TIMESTAMP NULL,
  revoke_reason VARCHAR(255),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_valid_tokens (token, expires_at),
  INDEX idx_user_valid (user_id, expires_at, revoked_at)
);
EOSQL
echo -e "${GREEN}✓ Tablas creadas${NC}"

# Crear usuario de la aplicación
echo -e "${YELLOW}Configurando usuario de la base de datos...${NC}"
$MYSQL_CMD << EOSQL
DROP USER IF EXISTS '$DB_USER'@'localhost';
CREATE USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
EOSQL
echo -e "${GREEN}✓ Usuario '$DB_USER' configurado${NC}\n"

# ============================================
# PASO 4: Actualizar config.php con credenciales
# ============================================
echo -e "${BLUE}[4/8] Actualizando archivo de configuración...${NC}"

# Crear archivo .env para las credenciales
cat > "$SCRIPT_DIR/.env" << EOF
# Credenciales de base de datos
DB_HOST=localhost
DB_NAME=$DB_NAME
DB_USER=$DB_USER
DB_PASS=$DB_PASS
EOF

chmod 600 "$SCRIPT_DIR/.env"
echo -e "${GREEN}✓ Archivo .env creado${NC}\n"

# ============================================
# PASO 5: Instalar dependencias de Composer
# ============================================
echo -e "${BLUE}[5/8] Instalando dependencias de Composer...${NC}"

if [ ! -f "$SCRIPT_DIR/composer.json" ]; then
    echo -e "${RED}Error: No se encontró composer.json${NC}"
    exit 1
fi

# Instalar como usuario no-root si es posible
cd "$SCRIPT_DIR"
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --optimize-autoloader 2>&1 | grep -E "(Installing|Generating)" || true
echo -e "${GREEN}✓ Dependencias de Composer instaladas${NC}\n"

# ============================================
# PASO 6: Crear usuario admin por defecto
# ============================================
echo -e "${BLUE}[6/8] Creando usuario administrador...${NC}"

ADMIN_HASH=$(php -r 'echo password_hash("admin", PASSWORD_DEFAULT);')
$MYSQL_CMD -e "USE $DB_NAME; INSERT IGNORE INTO users (username,password,is_admin) VALUES ('admin', '$ADMIN_HASH', 1);"

echo -e "${GREEN}✓ Usuario admin creado${NC}"
echo -e "${YELLOW}  Usuario: admin${NC}"
echo -e "${YELLOW}  Contraseña: admin (cámbiala en el primer inicio de sesión)${NC}\n"

# ============================================
# PASO 7: Configurar permisos
# ============================================
echo -e "${BLUE}[7/8] Configurando permisos...${NC}"

# Encontrar el usuario de Apache
APACHE_USER=$(ps aux | grep -E 'apache|httpd' | grep -v root | head -1 | awk '{print $1}')
if [ -z "$APACHE_USER" ]; then
    APACHE_USER="www-data"
fi

chown -R $APACHE_USER:$APACHE_USER "$SCRIPT_DIR"
chmod -R 755 "$SCRIPT_DIR"
chmod 600 "$SCRIPT_DIR/.env"

echo -e "${GREEN}✓ Permisos configurados para usuario $APACHE_USER${NC}\n"

# ============================================
# PASO 8: Configurar Apache VirtualHost
# ============================================
echo -e "${BLUE}[8/8] Configurando Apache VirtualHost...${NC}"

VHOST_FILE="/etc/apache2/sites-available/gestion-horas.conf"

cat > "$VHOST_FILE" << EOF
<VirtualHost *:80>
    ServerName localhost
    DocumentRoot $SCRIPT_DIR
    
    <Directory $SCRIPT_DIR>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    
    ErrorLog \${APACHE_LOG_DIR}/gestion-horas-error.log
    CustomLog \${APACHE_LOG_DIR}/gestion-horas-access.log combined
</VirtualHost>
EOF

# Habilitar el sitio
a2ensite gestion-horas.conf > /dev/null 2>&1
a2dissite 000-default.conf > /dev/null 2>&1 || true

# Reiniciar Apache
systemctl restart apache2

echo -e "${GREEN}✓ VirtualHost configurado y Apache reiniciado${NC}\n"

# ============================================
# RESUMEN FINAL
# ============================================
echo -e "${GREEN}"
echo "╔════════════════════════════════════════════════════════════╗"
echo "║                                                            ║"
echo "║            ✓ Instalación completada exitosamente          ║"
echo "║                                                            ║"
echo "╚════════════════════════════════════════════════════════════╝"
echo -e "${NC}\n"

echo -e "${YELLOW}Resumen de la instalación:${NC}"
echo -e "  ${GREEN}✓${NC} Base de datos: ${BLUE}$DB_NAME${NC}"
echo -e "  ${GREEN}✓${NC} Usuario DB: ${BLUE}$DB_USER${NC}"
echo -e "  ${GREEN}✓${NC} Directorio: ${BLUE}$SCRIPT_DIR${NC}"
echo -e "  ${GREEN}✓${NC} Usuario admin: ${BLUE}admin / admin${NC}"
echo ""
echo -e "${YELLOW}Accede a la aplicación en:${NC}"
echo -e "  ${BLUE}http://localhost${NC}"
echo -e "  ${BLUE}http://$(hostname -I | awk '{print $1}')${NC}"
echo ""
echo -e "${RED}⚠ IMPORTANTE: Cambia la contraseña del usuario admin después del primer inicio de sesión${NC}"
echo ""

# Verificar que todo funciona
echo -e "${YELLOW}Verificando instalación...${NC}"
if systemctl is-active --quiet apache2; then
    echo -e "${GREEN}✓ Apache está corriendo${NC}"
else
    echo -e "${RED}✗ Apache no está corriendo${NC}"
fi

if systemctl is-active --quiet mysql; then
    echo -e "${GREEN}✓ MySQL está corriendo${NC}"
else
    echo -e "${RED}✗ MySQL no está corriendo${NC}"
fi

if [ -f "$SCRIPT_DIR/vendor/autoload.php" ]; then
    echo -e "${GREEN}✓ Dependencias de Composer instaladas${NC}"
else
    echo -e "${RED}✗ Faltan dependencias de Composer${NC}"
fi

if $MYSQL_CMD -e "USE $DB_NAME; SELECT COUNT(*) FROM users;" &>/dev/null; then
    USER_COUNT=$($MYSQL_CMD -N -e "USE $DB_NAME; SELECT COUNT(*) FROM users;")
    echo -e "${GREEN}✓ Base de datos accesible ($USER_COUNT usuario(s))${NC}"
else
    echo -e "${RED}✗ No se puede acceder a la base de datos${NC}"
fi

echo ""
echo -e "${GREEN}¡Todo listo! Abre tu navegador y accede a la aplicación.${NC}"
echo ""
