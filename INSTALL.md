# Instalación de GestionHorasTrabajo

## 🚀 Instalación Automática (Recomendado)

Para instalar GestionHorasTrabajo en un sistema Debian/Ubuntu, simplemente ejecuta:

```bash
sudo ./install.sh
```

El script realizará automáticamente:

1. ✅ Verificación e instalación de dependencias del sistema
   - Apache2
   - MySQL Server
   - PHP 8.3 y extensiones necesarias
   - Composer

2. ✅ Configuración de MySQL
   - Creación de base de datos
   - Creación de usuario de aplicación
   - Creación de todas las tablas necesarias

3. ✅ Instalación de dependencias PHP
   - PhpSpreadsheet para importación de Excel
   - Otras librerías necesarias

4. ✅ Creación de usuario administrador
   - Usuario: `admin`
   - Contraseña: `admin` (deberás cambiarla en el primer inicio de sesión)

5. ✅ Configuración de Apache VirtualHost

6. ✅ Configuración de permisos

### Acceso Inicial

Después de la instalación, accede a la aplicación:

```
http://localhost
http://TU_IP_DEL_SERVIDOR
```

**Credenciales iniciales:**
- Usuario: `admin`
- Contraseña: `admin`

⚠️ **IMPORTANTE**: Por seguridad, el sistema te obligará a cambiar la contraseña por defecto en el primer inicio de sesión.

---

## 🔄 Recrear Base de Datos

Si necesitas recrear la base de datos desde cero (elimina todos los datos):

```bash
sudo ./recreate_db.sh
```

Este script:
- Elimina la base de datos existente
- Crea una nueva base de datos vacía
- Crea todas las tablas necesarias
- Configura el usuario de la aplicación

Después de recrear la BD, debes crear el usuario admin:

```bash
HASH=$(php -r 'echo password_hash("admin", PASSWORD_DEFAULT);')
mysql -u app_user -papp_pass -e "USE gestion_horas; INSERT INTO users (username,password,is_admin) VALUES ('admin', '${HASH}', 1);"
```

---

## 📋 Requisitos del Sistema

### Mínimos
- Ubuntu 20.04+ o Debian 11+
- PHP 8.3+
- MySQL 8.0+ o MariaDB 10.5+
- Apache 2.4+
- 512 MB RAM
- 500 MB espacio en disco

### Extensiones PHP Requeridas
- php-mysql
- php-xml (DOM)
- php-mbstring
- php-curl
- php-zip
- php-gd
- php-intl

---

## 🔧 Configuración Manual

Si prefieres instalar manualmente:

### 1. Instalar dependencias

```bash
sudo apt update
sudo apt install -y apache2 mysql-server php8.3 php8.3-mysql \
  php8.3-xml php8.3-mbstring php8.3-curl php8.3-zip \
  php8.3-gd php8.3-intl libapache2-mod-php8.3 composer
```

### 2. Crear base de datos

```bash
mysql -u root -p
```

```sql
CREATE DATABASE gestion_horas CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
CREATE USER 'app_user'@'localhost' IDENTIFIED BY 'app_pass';
GRANT ALL PRIVILEGES ON gestion_horas.* TO 'app_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

### 3. Importar schema

```bash
mysql -u root -p gestion_horas < deploy/schema.sql
mysql -u root -p gestion_horas < deploy/migration_extension_tokens.sql
```

### 4. Instalar dependencias de Composer

```bash
composer install
```

### 5. Configurar credenciales

Crea un archivo `.env`:

```bash
DB_HOST=localhost
DB_NAME=gestion_horas
DB_USER=app_user
DB_PASS=app_pass
```

### 6. Crear usuario admin

```bash
HASH=$(php -r 'echo password_hash("admin", PASSWORD_DEFAULT);')
mysql -u app_user -papp_pass -e "USE gestion_horas; INSERT INTO users (username,password,is_admin) VALUES ('admin', '${HASH}', 1);"
```

### 7. Configurar Apache

Crea `/etc/apache2/sites-available/gestion-horas.conf`:

```apache
<VirtualHost *:80>
    ServerName localhost
    DocumentRoot /ruta/a/GestionHorasTrabajo
    
    <Directory /ruta/a/GestionHorasTrabajo>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    
    ErrorLog ${APACHE_LOG_DIR}/gestion-horas-error.log
    CustomLog ${APACHE_LOG_DIR}/gestion-horas-access.log combined
</VirtualHost>
```

```bash
sudo a2ensite gestion-horas.conf
sudo a2enmod rewrite
sudo systemctl restart apache2
```

---

## 🔐 Seguridad

### Cambio de Contraseña Inicial

El sistema detecta automáticamente si el usuario `admin` está usando la contraseña por defecto (`admin`) y fuerza el cambio en el primer inicio de sesión.

### Cambiar Contraseña Manualmente

Accede a: `http://tu-servidor/change_password.php`

### Cambiar Credenciales de Base de Datos

1. Edita el archivo `.env`
2. Actualiza las credenciales en MySQL:

```sql
ALTER USER 'app_user'@'localhost' IDENTIFIED BY 'nueva_contraseña';
FLUSH PRIVILEGES;
```

---

## 🐛 Solución de Problemas

### Error 500 en importación

Si obtienes error 500 al importar archivos Excel:

```bash
# Instalar extensión PHP DOM
sudo apt install php8.3-xml

# Reinstalar dependencias de Composer
composer install

# Reiniciar Apache
sudo systemctl restart apache2
```

### MySQL no inicia

```bash
# Ver logs
sudo journalctl -u mysql -n 50

# Si está "frozen"
sudo rm -f /etc/mysql/FROZEN
sudo systemctl restart mysql
```

### Permisos incorrectos

```bash
sudo chown -R www-data:www-data /ruta/a/GestionHorasTrabajo
sudo chmod -R 755 /ruta/a/GestionHorasTrabajo
```

---

## 📚 Documentación Adicional

- [Guía de Importación Excel](EXCEL_IMPORT_GUIDE.md)
- [Guía de Testing](GUIA_TESTING_PRODUCCION.md)
- [Extensión Chrome](chrome-extension/COMO_FUNCIONA.md)
- [Resumen de Mejoras](IMPLEMENTACION_COMPLETADA.md)

---

## 📝 Notas

- La base de datos usa `utf8mb4` para soporte completo de Unicode
- Los archivos de configuración se guardan en la base de datos
- El sistema soporta múltiples años y usuarios
- Se incluye sistema de tokens para la extensión Chrome

---

## 🆘 Soporte

Si encuentras problemas durante la instalación:

1. Verifica los logs de Apache: `/var/log/apache2/error.log`
2. Verifica los logs de MySQL: `/var/log/mysql/error.log`
3. Verifica que todas las dependencias estén instaladas
4. Asegúrate de que MySQL esté corriendo: `systemctl status mysql`
5. Verifica permisos del directorio de la aplicación
