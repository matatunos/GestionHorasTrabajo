# Guía de Deployment de la Extensión Chrome

Este documento describe cómo desplegar la extensión Chrome y su sistema de tokens en un servidor de producción.

## Requisitos

- PHP >= 7.4
- MySQL/MariaDB >= 5.7
- Servidor web (Apache/Nginx) con HTTPS configurado
- Acceso SSH a la base de datos

## Pasos de Deployment

### 1. Actualizar código desde GitHub

```bash
cd /ruta/a/GestionHorasTrabajo
git fetch origin
git checkout feature/multiuser-dashboard
git pull origin feature/multiuser-dashboard
```

### 2. Crear tabla de tokens en la base de datos

Ejecutar la migración en la base de datos:

```bash
mysql -u app_user -p -h localhost gestion_horas < deploy/migration_extension_tokens.sql
```

O si prefiere ingresar interactivamente:

```bash
mysql -u app_user -p -h localhost gestion_horas
mysql> SOURCE /ruta/a/GestionHorasTrabajo/deploy/migration_extension_tokens.sql;
```

### 3. Verificar que la tabla fue creada

```bash
mysql -u app_user -p -h localhost gestion_horas -e "SHOW TABLES LIKE 'extension_tokens';"
```

Debe mostrar:
```
+---------------------------------+
| Tables_in_gestion_horas         |
+---------------------------------+
| extension_tokens                |
+---------------------------------+
```

### 4. Verificar permisos de archivos

```bash
ls -la /ruta/a/GestionHorasTrabajo/extension-tokens.php
ls -la /ruta/a/GestionHorasTrabajo/lib.php
ls -la /ruta/a/GestionHorasTrabajo/api.php
```

Deben tener permisos de lectura para el usuario de Apache (www-data).

### 5. Verificar logs de error

Si hay problemas, verificar:

```bash
# Apache error log
tail -50 /var/log/apache2/error.log

# PHP error log (si existe)
tail -50 /var/log/php_errors.log

# Sistema
tail -50 /var/log/syslog
```

### 6. Probar la página de tokens

Acceder a: `https://example.com/extension-tokens.php`

Debe:
- Solicitar login si no está logueado
- Mostrar lista de tokens del usuario (vacía al principio)
- Permitir descargar extensión desde profile.php

## Funciones Principales

### Generación de Tokens

Cuando el usuario descarga la extensión desde `profile.php`:

1. Se llama a `download-addon.php`
2. Se valida HTTPS
3. Se genera un token único (64 caracteres, random_bytes)
4. El token se inserta en tabla `extension_tokens` con:
   - `user_id`: ID del usuario logueado
   - `token`: Cadena aleatoria
   - `name`: "Extension Download [fecha]"
   - `expires_at`: Fecha actual + 7 días
   - `created_at`: Timestamp actual
5. El token se inyecta en `config.js` dentro del ZIP
6. Se retorna el archivo ZIP descargado

### Validación de Tokens

En `api.php`, cuando se importan datos:

1. Intentar usar sesión PHP (si usuario logueado)
2. Si no hay sesión, validar token:
   - Verificar que el token existe
   - Verificar que NO está expirado (`expires_at > NOW()`)
   - Verificar que NO está revocado (`revoked_at IS NULL`)
3. Si válido, actualizar `last_used_at`
4. Si inválido, retornar 401 Unauthorized

### Revocación de Tokens

En `extension-tokens.php`, el usuario puede:

1. Ver lista de todos sus tokens
2. Verificar: Nombre, Creación, Expiración, Último uso, Estado
3. Revocar manualmente cualquier token
4. Al revocar: establece `revoked_at = NOW()` y `revoke_reason`

## Flujo de Uso de la Extensión

1. Usuario logueado accede a `profile.php`
2. Hace clic en "Descargar extensión"
3. Se ejecuta `download-addon.php` → genera token → devuelve ZIP
4. Usuario instala la extensión en Chrome
5. Extensión carga `config.js` con URL y TOKEN preconfigurados
6. Usuario hace clic en "Capturar datos" en la extensión
7. La extensión envía datos a `api.php` con:
   - Datos de fichajes
   - Token (desde config.js) O credenciales de sesión
8. `api.php` valida token/sesión y importa datos
9. Extensión muestra resultado al usuario

## Flujo de Usuario NO Logueado

1. Usuario accede a página con datos (EXTERNAL, etc)
2. Hace clic en extensión
3. Sin token: error "No autorizado, descarga extensión desde profile"
4. Usuario accede a login y descarga extensión
5. Extensión se reconfigurada con token
6. Ahora puede importar sin loguearse

## Seguridad

- ✅ HTTPS obligatorio (no funciona sin HTTPS en producción)
- ✅ Tokens de 64 caracteres (random_bytes)
- ✅ Expiración automática (7 días)
- ✅ Revocación manual disponible
- ✅ Auditoría (last_used_at)
- ⚠️ Usuario responsable de no compartir el token

## Troubleshooting

### Error 500 en extension-tokens.php

**Probable causa**: Tabla `extension_tokens` no existe

```bash
mysql -u app_user -p -h localhost gestion_horas < deploy/migration_extension_tokens.sql
```

### Error "EXTENSION_TOKEN undefined" en extensión

**Solución**: Descargar extensión nuevamente desde profile.php

### Tokens no aparecen en lista

**Verificar**:
```bash
mysql -u app_user -p -h localhost gestion_horas
mysql> SELECT * FROM extension_tokens WHERE user_id = 1 LIMIT 5;
```

### Importación rechazada con "Invalid token"

**Verificar**:
- Token no está expirado: `SELECT * FROM extension_tokens WHERE token = 'xxx' AND expires_at > NOW();`
- Token no está revocado: `SELECT revoked_at FROM extension_tokens WHERE token = 'xxx';`

## Rollback

Si hay problemas, puedes:

1. Revertir cambios en Git:
```bash
git checkout main  # o tu rama estable
git pull origin main
```

2. Pero MANTENER la tabla (no borrarla):
```bash
# NO EJECUTAR - la tabla no se elimina
# DROP TABLE extension_tokens;
```

## Próximas mejoras

- [ ] Interfaz para crear tokens manualmente (sin descargar extensión)
- [ ] Generación automática de nuevo token antes de expiración
- [ ] Notificaciones por email cuando token expira
- [ ] Histórico de uso de tokens (logging)
- [ ] Límite de tokens por usuario
