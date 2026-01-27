# Manual Técnico para Desarrolladores - GestionHorasTrabajo

**Versión:** 1.1.1  
**Última actualización:** 2026-01-27

## Tabla de Contenidos

1. [Introducción técnica](#introducción-técnica)
2. [Arquitectura del proyecto](#arquitectura-del-proyecto)
3. [Estructura de directorios](#estructura-de-directorios)
4. [Requisitos de desarrollo](#requisitos-de-desarrollo)
5. [Setup local](#setup-local)
6. [Configuración](#configuración)
7. [Base de datos](#base-de-datos)
8. [API](#api)
9. [Seguridad](#seguridad)
10. [Testing](#testing)
11. [Deployment](#deployment)
12. [Contribución](#contribución)

---

## Introducción técnica

GestionHorasTrabajo es una aplicación web PHP moderna para gestión de horas de trabajo. Stack:

- **Backend:** PHP 8.3+
- **Base de datos:** MySQL 5.7+
- **Frontend:** HTML5, CSS3, JavaScript vanilla
- **Servidor:** Apache 2.4+
- **Control de versiones:** Git

---

## Arquitectura del proyecto

### Patrón de diseño

El proyecto sigue una arquitectura modular simple:

```
├── Core (autenticación, BD)
│   └── auth.php, db.php, config.php, lib.php
│
├── Views (páginas PHP)
│   └── login.php, dashboard.php, index.php, etc.
│
├── Helpers (/lib)
│   └── JWTHelper.php, SecurityHeaders.php, LogAnalytics.php, etc.
│
├── API
│   └── api.php (endpoints REST)
│
└── Tools (/scripts, /admin, /tools)
    └── Scripts de utilidad y mantenimiento
```

### Flujo de autorización

```
1. Usuario accede a login.php
   ↓
2. do_login() valida credenciales en BD
   ↓
3. Sesión PHP se establece ($_SESSION['user_id'])
   ↓
4. Página protegida llama require_login()
   ↓
5. current_user() verifica sesión activa
   ↓
6. Acceso permitido o redirigido a login
```

---

## Estructura de directorios

```
/opt/GestionHorasTrabajo/
├── index.php                 # Portal principal
├── login.php                 # Página de login
├── dashboard.php             # Dashboard de usuario
├── api.php                   # API REST
├── auth.php                  # Funciones de autenticación
├── db.php                    # Conexión a BD (PDO)
├── config.php                # Configuración global
├── lib.php                   # Funciones principales
│
├── lib/                      # Librerías y helpers
│   ├── JWTHelper.php         # Gestión de JWT
│   ├── SecurityHeaders.php   # Headers de seguridad HTTP
│   ├── LogAnalytics.php      # Análisis de logs
│   ├── LogConfig.php         # Config de logging
│   └── improvements_functions.php
│
├── scripts/                  # Scripts CLI
│   ├── testing/              # Scripts de testing
│   │   ├── check_login.php
│   │   ├── verify_friday_constraint.php
│   │   └── ...
│   ├── migrations/           # Migraciones de BD
│   │   └── migrate_*.php
│   └── seed_year.php         # Seed de datos
│
├── admin/                    # Herramientas de admin
│   ├── admin-backup.php
│   ├── clean_entries.php
│   ├── phpinfo.php
│   └── ...
│
├── tools/                    # Herramientas de análisis
│   ├── analyze_data_summary.php
│   ├── analyze_excel.php
│   └── ...
│
├── plugins/                  # Plugins opcionales
│   ├── pdf_informe/
│   └── clipboard_import/
│
├── assets/                   # Assets estáticos
│   ├── css/
│   ├── js/
│   ├── images/
│   └── vendor/
│
├── uploads/                  # Archivos subidos por usuarios
├── logs/                     # Archivos de log
├── docs/                     # Documentación
│   └── archive/              # Docs históricos
│
└── CHANGELOG.md              # Historial de cambios
```

---

## Requisitos de desarrollo

### Sistema operativo

- Linux (Debian/Ubuntu recomendado)
- macOS
- Windows (WSL2 recomendado)

### Software requerido

```bash
# PHP 8.3+
php -v

# MySQL Server 5.7+
mysql --version

# Composer (gestor de paquetes PHP)
composer --version

# Git
git --version

# Apache 2.4+ (o servidor web alternativo)
apache2ctl -v
```

### PHP Extensions requeridas

```
- pdo_mysql (conexión a BD)
- json (procesamiento JSON)
- mbstring (strings multibyte)
- fileinfo (detección de tipo de archivo)
```

---

## Setup local

### 1. Clonar repositorio

```bash
git clone https://github.com/matatunos/GestionHorasTrabajo.git
cd GestionHorasTrabajo
```

### 2. Instalar dependencias

```bash
composer install
```

### 3. Configurar base de datos

```bash
# Crear BD
mysql -u root -p -e "CREATE DATABASE gestion_horas;"

# Importar schema (si existe)
mysql -u root -p gestion_horas < db_schema.sql

# Crear usuario de aplicación
mysql -u root -p -e "CREATE USER 'app_user'@'localhost' IDENTIFIED BY 'app_pass';"
mysql -u root -p -e "GRANT ALL ON gestion_horas.* TO 'app_user'@'localhost';"
```

### 4. Configurar servidor web

```bash
# Apache vhost
sudo nano /etc/apache2/sites-available/gestion-horas.conf
```

Contenido mínimo:
```apache
<VirtualHost *:80>
    ServerName localhost
    DocumentRoot /opt/GestionHorasTrabajo
    
    <Directory /opt/GestionHorasTrabajo>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Habilitar:
```bash
sudo a2ensite gestion-horas
sudo systemctl reload apache2
```

### 5. Permisos

```bash
chmod 777 logs/
chmod 666 logs/*.log
```

### 6. Variables de entorno

```bash
cp .env.example .env
nano .env
```

Edita con tus valores:
```env
DB_HOST=localhost
DB_NAME=gestion_horas
DB_USER=app_user
DB_PASS=app_pass
```

### 7. Crear usuario admin

```bash
php -r "
require 'db.php';
\$pdo = get_pdo();
\$hash = password_hash('admin', PASSWORD_DEFAULT);
\$pdo->prepare('INSERT INTO users (username, password, is_admin) VALUES (?, ?, 1)')
    ->execute(['admin', \$hash]);
echo 'Admin creado: admin / admin';
"
```

---

## Configuración

### Archivos de configuración

**config.php** - Configuración global:
```php
'site_name' => 'GestionHoras',
'work_hours' => [
    'winter' => ['mon_thu' => 8.0, 'friday' => 6.0],
    'summer' => ['mon_thu' => 7.5, 'friday' => 6.0],
],
'coffee_minutes' => 15,
'lunch_minutes' => 30,
```

**db.php** - Conexión a BD:
```php
$user = getenv('DB_USER') ?: 'app_user';
$pass = getenv('DB_PASS') ?: 'app_pass';
```

**.env** - Variables de entorno:
```env
DB_USER=app_user
DB_PASS=app_pass
DB_HOST=localhost
DB_NAME=gestion_horas
```

---

## Base de datos

### Tablas principales

**users**
```sql
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(191) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    is_admin BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

**entries** (fichajes)
```sql
CREATE TABLE entries (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    date DATE NOT NULL,
    entry_time TIME,
    exit_time TIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

**holidays** (ausencias)
```sql
CREATE TABLE holidays (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    date_from DATE NOT NULL,
    date_to DATE NOT NULL,
    type VARCHAR(50),
    status ENUM('pending', 'approved', 'rejected'),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

### Migraciones

Para ejecutar migraciones:

```bash
cd scripts/migrations/
php migrate_add_absence_type_column.php
php migrate_add_incidents_table.php
```

---

## API

### Endpoints principales

**POST /api.php?action=login**
```json
{
    "username": "admin",
    "password": "admin"
}
```

**GET /api.php?action=entries&date=2026-01-27**
- Retorna fichajes del usuario para una fecha

**POST /api.php?action=entry**
```json
{
    "date": "2026-01-27",
    "entry_time": "09:00",
    "exit_time": "18:00"
}
```

**GET /api.php?action=config**
- Retorna configuración del usuario

### Autenticación JWT

Si usas tokens JWT:

```php
require 'lib/JWTHelper.php';

$token = JWTHelper::create($user_id, $username);
$payload = JWTHelper::verify($token);
```

---

## Seguridad

### Prácticas implementadas

- ✅ Passwords hasheados con `password_hash()` (bcrypt)
- ✅ Prepared statements para prevenir SQL injection
- ✅ CSRF tokens en formularios
- ✅ Session HTTPS only en producción
- ✅ Validación de entrada en todos los endpoints
- ✅ Sanitización de output con `htmlspecialchars()`

### Headers de seguridad

Ver [lib/SecurityHeaders.php](lib/SecurityHeaders.php):

```php
Security::setHeaders();
```

Establece:
- X-Frame-Options: DENY
- X-Content-Type-Options: nosniff
- X-XSS-Protection: 1; mode=block
- Strict-Transport-Security

### Checklist de seguridad antes de producción

- [ ] Cambiar credenciales por defecto (admin/admin)
- [ ] Configurar HTTPS
- [ ] Activar mod_rewrite de Apache
- [ ] Desactivar display_errors en producción
- [ ] Configurar logs correctamente
- [ ] Revisar permisos de archivos
- [ ] Backup regular de BD
- [ ] Monitorear logs de seguridad

---

## Testing

### Scripts de testing disponibles

```bash
# Test de login
php scripts/testing/check_login.php

# Test de restricción de viernes
php scripts/testing/verify_friday_constraint.php

# Test de configuración
php scripts/testing/test_config_2026.php

# Validar conexión a BD
php scripts/testing/test_promedios_db.php
```

### Testing manual

```bash
# Validar sintaxis PHP
php -l archivo.php

# Ejecutar script PHP
php -r "require 'db.php'; var_dump(get_pdo());"
```

---

## Deployment

### Preparación para producción

```bash
# 1. Clonar repo
git clone https://github.com/matatunos/GestionHorasTrabajo.git /var/www/gestion-horas

# 2. Instalar dependencias
cd /var/www/gestion-horas
composer install --no-dev

# 3. Permisos
chmod 755 logs/
chmod 644 logs/*.log

# 4. Variables de entorno
cp .env.example .env
# Editar .env con valores reales

# 5. Configurar HTTPS
# (usar Let's Encrypt o certificado propio)

# 6. Reiniciar Apache
sudo systemctl restart apache2
```

### Backup

```bash
# Backup BD
mysqldump -u app_user -p gestion_horas > backup_$(date +%Y%m%d).sql

# Backup archivos
tar czf gestion-horas-backup-$(date +%Y%m%d).tar.gz /var/www/gestion-horas
```

### Monitoreo

Revisar regularmente:
- `/logs/auth.log` - Intentos de login
- `/var/log/apache2/error.log` - Errores de Apache
- Uso de espacio en disco (logs, uploads)
- Rendimiento de BD

---

## Contribución

### Flujo de trabajo

1. **Crear rama** para tu feature
```bash
git checkout -b feature/nombre-feature
```

2. **Hacer cambios** y commits
```bash
git add archivo.php
git commit -m "feat: Descripción clara del cambio"
```

3. **Push a rama** personal
```bash
git push origin feature/nombre-feature
```

4. **Pull Request** a main

### Estándares de código

- **PHP:** PSR-12 compliant
- **Nombres:** camelCase para variables/funciones, UPPER_CASE para constantes
- **Comentarios:** En español, PHPDoc para funciones públicas
- **Validación:** Preparar statements siempre para BD

### Tipos de commits

- `feat:` Nueva funcionalidad
- `fix:` Corrección de bug
- `refactor:` Cambios de estructura sin cambiar funcionalidad
- `docs:` Cambios en documentación
- `test:` Nuevos tests
- `chore:` Cambios administrativos
- `security:` Fixes de seguridad

---

## Troubleshooting técnico

### Problemas comunes

**Error: PDOException "SQLSTATE[HY000]"**
- Verificar credenciales en .env
- Verificar que BD existe
- Verificar permisos del usuario BD

**Error: "Class not found"**
- Ejecutar `composer install`
- Verificar rutas de include

**Apache 500 error**
- Ver `/var/log/apache2/gestion-horas-error.log`
- Activar display_errors temporalmente en desarrollo

**Logs sin permisos de escritura**
- Ejecutar `chmod 777 logs/`
- Verificar que Apache corre como www-data

---

## Recursos adicionales

- **PHP:** https://www.php.net/manual/
- **MySQL:** https://dev.mysql.com/doc/
- **Git:** https://git-scm.com/doc
- **Apache:** https://httpd.apache.org/docs/

---

¡Gracias por contribuir a GestionHorasTrabajo!
