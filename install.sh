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
    "git"
    "poppler-utils"
)

PACKAGES_TO_INSTALL=()

for package in "${REQUIRED_PACKAGES[@]}"; do
    if ! dpkg -l | grep -q "^ii  $package "; then
        PACKAGES_TO_INSTALL+=("$package")
    fi
done

if [ ${#PACKAGES_TO_INSTALL[@]} -gt 0 ]; then
    echo -e "${YELLOW}Instalando paquetes faltantes: ${PACKAGES_TO_INSTALL[*]}${NC}"
    apt-get update -qq || {
        echo -e "${RED}Error: No se pudo actualizar lista de paquetes${NC}"
        exit 1
    }
    
    if DEBIAN_FRONTEND=noninteractive apt-get install -y -qq "${PACKAGES_TO_INSTALL[@]}" > /dev/null 2>&1; then
        echo -e "${GREEN}✓ Paquetes instalados${NC}"
    else
        echo -e "${RED}Error: Falló la instalación de paquetes${NC}"
        exit 1
    fi
else
    echo -e "${GREEN}✓ Todas las dependencias ya están instaladas${NC}"
fi

# Habilitar módulos de Apache
echo -e "${YELLOW}Habilitando módulos de Apache...${NC}"
if a2enmod rewrite > /dev/null 2>&1; then
    echo -e "${GREEN}✓ Módulo rewrite habilitado${NC}"
else
    echo -e "${YELLOW}⚠ No se pudo habilitar módulo rewrite${NC}"
fi

# Intentar habilitar módulo PHP (nombre genérico)
if a2enmod php > /dev/null 2>&1; then
    echo -e "${GREEN}✓ Módulo PHP habilitado${NC}"
elif a2enmod php8 > /dev/null 2>&1; then
    echo -e "${GREEN}✓ Módulo PHP8 habilitado${NC}"
else
    echo -e "${YELLOW}⚠ No se pudo habilitar módulo PHP (puede estar preinstalado)${NC}"
fi

echo ""

# ============================================
# PASO 2: Configurar MySQL
# ============================================
echo -e "${BLUE}[2/8] Configuración de base de datos...${NC}"

# Detectar sistema de base de datos disponible
DB_SYSTEM=""
if command -v mysql &> /dev/null; then
    DB_SYSTEM="mysql"
    echo -e "${GREEN}✓ MySQL detectado${NC}"
elif command -v mariadb &> /dev/null; then
    DB_SYSTEM="mariadb"
    echo -e "${GREEN}✓ MariaDB detectado${NC}"
else
    echo -e "${RED}Error: No se encontró MySQL o MariaDB instalado${NC}"
    exit 1
fi

# Iniciar servicio de base de datos
echo -e "${YELLOW}Iniciando servicio de base de datos...${NC}"
if systemctl start mysql 2>/dev/null; then
    echo -e "${GREEN}✓ MySQL iniciado${NC}"
elif systemctl start mariadb 2>/dev/null; then
    echo -e "${GREEN}✓ MariaDB iniciado${NC}"
else
    echo -e "${YELLOW}⚠ No se pudo iniciar el servicio (puede estar ya activo)${NC}"
fi

echo -e "${YELLOW}Ingresa la contraseña de base de datos root (presiona Enter si es vacía):${NC}"
read -s MYSQL_ROOT_PASS
echo ""

# Verificar conexión
if [ -z "$MYSQL_ROOT_PASS" ]; then
    MYSQL_CMD="mysql -u root"
else
    MYSQL_CMD="mysql -u root -p$MYSQL_ROOT_PASS"
fi

if ! $MYSQL_CMD -e "SELECT 1" &>/dev/null; then
    echo -e "${RED}Error: No se pudo conectar a la base de datos. Verifica:${NC}"
    echo -e "  - El servicio está corriendo"
    echo -e "  - La contraseña es correcta"
    echo -e "  - El usuario root tiene acceso local"
    exit 1
fi
echo -e "${GREEN}✓ Conexión a base de datos exitosa${NC}\n"

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

# Verificar si composer está instalado
if ! command -v composer &> /dev/null; then
    echo -e "${YELLOW}Instalando Composer...${NC}"
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer 2>&1 || {
        echo -e "${RED}Error: No se pudo instalar Composer${NC}"
        echo -e "${YELLOW}Intenta instalar Composer manualmente desde https://getcomposer.org${NC}"
        exit 1
    }
fi

if [ ! -f "$SCRIPT_DIR/composer.json" ]; then
    echo -e "${RED}Error: No se encontró composer.json${NC}"
    exit 1
fi

# Instalar dependencias
echo -e "${YELLOW}Descargando dependencias de Composer...${NC}"
cd "$SCRIPT_DIR"
if COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --optimize-autoloader 2>&1 | grep -E "(Installing|Generating|loaded)" || true; then
    echo -e "${GREEN}✓ Dependencias de Composer instaladas${NC}"
else
    echo -e "${YELLOW}⚠ Composer completó con advertencias (revisar arriba)${NC}"
fi
echo ""

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
APACHE_USER=$(ps aux | grep -E 'apache2|apache|httpd' | grep -v 'grep\|root' | head -1 | awk '{print $1}')
if [ -z "$APACHE_USER" ]; then
    APACHE_USER="www-data"
    echo -e "${YELLOW}⚠ Usando usuario por defecto: www-data${NC}"
fi

# Establecer permisos
echo -e "${YELLOW}Aplicando permisos para usuario: $APACHE_USER${NC}"
if chown -R "$APACHE_USER:$APACHE_USER" "$SCRIPT_DIR" 2>/dev/null; then
    echo -e "${GREEN}✓ Propietario configurado${NC}"
else
    echo -e "${RED}✗ Error al configurar propietario${NC}"
fi

chmod -R 755 "$SCRIPT_DIR" 2>/dev/null || true
chmod 600 "$SCRIPT_DIR/.env" 2>/dev/null || true

echo -e "${GREEN}✓ Permisos configurados${NC}\n"

# ============================================
# PASO 8: Configurar Apache VirtualHost
# ============================================
echo -e "${BLUE}[8/8] Configurando servidor web...${NC}"

# Verificar si Apache está instalado
if ! systemctl is-active --quiet apache2; then
    echo -e "${YELLOW}Iniciando Apache...${NC}"
    if systemctl start apache2 2>/dev/null; then
        echo -e "${GREEN}✓ Apache iniciado${NC}"
    else
        echo -e "${RED}✗ No se pudo iniciar Apache${NC}"
    fi
fi

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

echo -e "${YELLOW}Habilitando sitio...${NC}"
if a2ensite gestion-horas.conf > /dev/null 2>&1; then
    echo -e "${GREEN}✓ Sitio habilitado${NC}"
else
    echo -e "${RED}✗ Error al habilitar sitio${NC}"
fi

# Deshabilitar sitio por defecto si existe
a2dissite 000-default.conf > /dev/null 2>&1 || true

# Reiniciar Apache
echo -e "${YELLOW}Reiniciando Apache...${NC}"
if systemctl restart apache2 2>/dev/null; then
    echo -e "${GREEN}✓ Apache reiniciado exitosamente${NC}"
else
    echo -e "${RED}✗ Error al reiniciar Apache${NC}"
fi
echo ""

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

INSTALLATION_OK=true

# Verificar Apache
if systemctl is-active --quiet apache2; then
    echo -e "${GREEN}✓ Apache está corriendo${NC}"
elif systemctl is-active --quiet httpd; then
    echo -e "${GREEN}✓ Apache (httpd) está corriendo${NC}"
else
    echo -e "${RED}✗ Apache no está corriendo${NC}"
    INSTALLATION_OK=false
fi

# Verificar MySQL/MariaDB
if systemctl is-active --quiet mysql; then
    echo -e "${GREEN}✓ MySQL está corriendo${NC}"
elif systemctl is-active --quiet mariadb; then
    echo -e "${GREEN}✓ MariaDB está corriendo${NC}"
else
    echo -e "${YELLOW}⚠ Base de datos no detectada como activa${NC}"
fi

# Verificar PHP
if command -v php &> /dev/null; then
    PHP_VERSION=$(php -v | head -n 1 | grep -oP 'PHP \K[0-9]+\.[0-9]+')
    echo -e "${GREEN}✓ PHP instalado (versión $PHP_VERSION)${NC}"
else
    echo -e "${RED}✗ PHP no encontrado${NC}"
    INSTALLATION_OK=false
fi

# Verificar Composer
if [ -f "$SCRIPT_DIR/vendor/autoload.php" ]; then
    echo -e "${GREEN}✓ Dependencias de Composer instaladas${NC}"
else
    echo -e "${YELLOW}⚠ Faltan dependencias de Composer (revisar paso 5)${NC}"
fi

# Verificar base de datos
if $MYSQL_CMD -e "USE $DB_NAME; SELECT COUNT(*) FROM users;" &>/dev/null; then
    USER_COUNT=$($MYSQL_CMD -N -e "USE $DB_NAME; SELECT COUNT(*) FROM users;" 2>/dev/null)
    echo -e "${GREEN}✓ Base de datos accesible ($USER_COUNT usuario(s))${NC}"
else
    echo -e "${RED}✗ No se puede acceder a la base de datos${NC}"
    INSTALLATION_OK=false
fi

echo ""

if [ "$INSTALLATION_OK" = true ]; then
    echo -e "${GREEN}¡Instalación completada exitosamente!${NC}"
else
    echo -e "${YELLOW}⚠ La instalación se completó con algunos problemas.${NC}"
    echo -e "  Revisa los mensajes de error arriba."
fi
