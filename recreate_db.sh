#!/bin/bash

# Script para recrear la base de datos desde cero
# Uso: ./recreate_db.sh [mysql_root_password]

set -e  # Exit on error

# Colores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${YELLOW}=== Recreación de Base de Datos GestionHorasTrabajo ===${NC}\n"

# Pedir contraseña de root si no se proporciona
if [ -z "$1" ]; then
    echo -e "${YELLOW}Ingresa la contraseña de MySQL root:${NC}"
    read -s MYSQL_ROOT_PASS
else
    MYSQL_ROOT_PASS="$1"
fi

echo ""

# Configuración de la base de datos
DB_NAME="gestion_horas"
DB_USER="app_user"
DB_PASS="app_pass"

# Verificar conexión a MySQL
echo -e "${YELLOW}[1/6] Verificando conexión a MySQL...${NC}"
if [ -z "$MYSQL_ROOT_PASS" ]; then
    MYSQL_CMD="mysql -u root"
else
    MYSQL_CMD="mysql -u root -p$MYSQL_ROOT_PASS"
fi

if ! $MYSQL_CMD -e "SELECT 1" &>/dev/null; then
    echo -e "${RED}Error: No se pudo conectar a MySQL. Verifica la contraseña.${NC}"
    exit 1
fi
echo -e "${GREEN}✓ Conexión exitosa${NC}\n"

# Eliminar base de datos existente
echo -e "${YELLOW}[2/6] Eliminando base de datos existente...${NC}"
$MYSQL_CMD -e "DROP DATABASE IF EXISTS $DB_NAME;" 2>/dev/null
echo -e "${GREEN}✓ Base de datos eliminada${NC}\n"

# Crear base de datos nueva
echo -e "${YELLOW}[3/6] Creando base de datos nueva...${NC}"
$MYSQL_CMD -e "CREATE DATABASE $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
echo -e "${GREEN}✓ Base de datos creada${NC}\n"

# Aplicar schema principal
echo -e "${YELLOW}[4/6] Aplicando schema principal...${NC}"
$MYSQL_CMD $DB_NAME << 'EOF'
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
EOF
echo -e "${GREEN}✓ Schema aplicado${NC}\n"

# Crear o actualizar usuario de la aplicación
echo -e "${YELLOW}[5/6] Configurando usuario de la aplicación...${NC}"
$MYSQL_CMD << EOF
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
EOF
echo -e "${GREEN}✓ Usuario configurado${NC}\n"

# Crear usuario admin por defecto
echo -e "${YELLOW}[6/7] Creando usuario admin...${NC}"
ADMIN_HASH=$(php -r 'echo password_hash("admin", PASSWORD_DEFAULT);')
$MYSQL_CMD $DB_NAME -e "INSERT IGNORE INTO users (username, password, is_admin) VALUES ('admin', '${ADMIN_HASH}', 1);" 2>/dev/null
echo -e "${GREEN}✓ Usuario admin creado${NC}"
echo -e "${YELLOW}  Usuario: admin${NC}"
echo -e "${YELLOW}  Contraseña: admin (cámbiala después del primer inicio de sesión)${NC}\n"

# Mostrar resumen
echo -e "${YELLOW}[7/7] Resumen de la configuración:${NC}"
echo -e "  Base de datos: ${GREEN}$DB_NAME${NC}"
echo -e "  Usuario: ${GREEN}$DB_USER${NC}"
echo -e "  Contraseña: ${GREEN}$DB_PASS${NC}"
echo -e "  Host: ${GREEN}localhost${NC}"
echo -e "  Usuario admin: ${GREEN}admin / admin${NC}\n"

# Verificar tablas creadas
echo -e "${YELLOW}Tablas creadas:${NC}"
$MYSQL_CMD $DB_NAME -e "SHOW TABLES;" 2>/dev/null | tail -n +2 | while read table; do
    echo -e "  ${GREEN}✓${NC} $table"
done

# Verificar usuario admin creado
ADMIN_COUNT=$($MYSQL_CMD -N -e "USE $DB_NAME; SELECT COUNT(*) FROM users WHERE username='admin';")
if [ "$ADMIN_COUNT" -gt 0 ]; then
    echo -e "\n${GREEN}✓ Usuario admin verificado en la base de datos${NC}"
fi

echo -e "\n${GREEN}=== Base de datos recreada exitosamente ===${NC}"
echo -e "${YELLOW}Puedes conectarte con: mysql -u $DB_USER -p$DB_PASS $DB_NAME${NC}"
echo -e "${YELLOW}Usuario admin: admin / admin${NC}\n"
