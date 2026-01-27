# Política de Seguridad - GestionHorasTrabajo

**Versión:** 1.0  
**Última actualización:** 2026-01-27  
**Estado:** ✅ Auditado y anonimizado

## 📋 Auditoría de Seguridad

Este documento describe las medidas de seguridad implementadas y los estándares de anonimización del proyecto.

---

## 🔒 Credenciales y Secretos

### ✅ Implementado

- **Variables de entorno:** Todas las credenciales deben configurarse en `.env` (no versionado)
- **Fallbacks seguros:** Los fallbacks en `config.php` y `db.php` usan placeholders no funcionales
- **Plantilla anonimizada:** `.env.example` contiene solo placeholders genéricos
- **Credenciales no en git:** Ni `.env` ni archivos `.key`/`.pem` son rastreados por git

### 📝 Archivo `.env`

```bash
# Crear desde plantilla
cp .env.example .env

# Editar con valores reales
nano .env
```

**Variables requeridas:**
- `DB_HOST` - Host de la base de datos
- `DB_NAME` - Nombre de la base de datos
- `DB_USER` - Usuario de BD (debe existir con permisos)
- `DB_PASS` - Contraseña del usuario de BD
- `DB_CHARSET` - Charset (utf8mb4 recomendado)

### 🚫 Nunca

```bash
❌ Commitar .env real a git
❌ Hardcodear credenciales en PHP
❌ Usar mismo password en dev/prod
❌ Compartir .env archivos
❌ Poner credenciales en comentarios
```

---

## 📦 Archivos Ignorados por Git

El `.gitignore` protege:

### Credenciales
- `.env` - Variables de entorno
- `.env.*` - Variantes de configuración
- `*.key` - Claves privadas
- `*.pem` - Certificados
- `.htpasswd` - Credenciales HTTP Basic Auth

### Logs (pueden contener datos sensibles)
- `/logs` - Directorio de logs completo
- `*.log` - Archivos de log
- `error.log`, `access.log` - Logs específicos

### Datos Generados
- `/uploads` - Archivos subidos por usuarios
- `/data` - Datos locales
- `/tmp`, `/cache` - Directorios temporales

### Dependencias
- `node_modules/` - NPM packages
- `vendor/` - Composer packages
- `composer.lock` - Versiones exactas de dependencias

### IDE y Sistema
- `.vscode/`, `.idea/` - Configuración IDE
- `.DS_Store` - macOS
- `thumbs.db` - Windows

---

## 🔐 Prácticas de Código

### Passwords

✅ **Correcto:**
```php
$hashed = password_hash($password, PASSWORD_DEFAULT);
$verified = password_verify($input, $hashed);
```

❌ **Incorrecto:**
```php
// Nunca hagas esto
if ($password === 'admin123') { }
$_SESSION['password'] = $password;  // Guardar plaintext
```

### Base de Datos

✅ **Correcto:**
```php
$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$id]);
```

❌ **Incorrecto:**
```php
$sql = "SELECT * FROM users WHERE id = $id";  // SQL injection
$pdo->exec($sql);
```

### Variables de Entorno

✅ **Correcto:**
```php
$db_user = getenv('DB_USER') ?: 'app_user';
$db_pass = getenv('DB_PASS') ?: '';
```

❌ **Incorrecto:**
```php
$db_pass = getenv('DB_PASS') ?: 'hardcoded_password';
```

---

## 🛡️ Headers de Seguridad

Implementados en [lib/SecurityHeaders.php](lib/SecurityHeaders.php):

```php
Security::setHeaders();
```

Establece:
- `X-Frame-Options: DENY` - Previene clickjacking
- `X-Content-Type-Options: nosniff` - Previene MIME sniffing
- `X-XSS-Protection: 1; mode=block` - Protección XSS
- `Strict-Transport-Security` - Fuerza HTTPS
- `Content-Security-Policy` - Política de seguridad de contenido

---

## 🔑 Tokens y JWT

### Extensión Chrome

Los tokens para la extensión Chrome:
- Se generan bajo demanda
- Expiran en 7 días
- Están incrustados en la descarga (config.js)
- **Nunca deben compartirse**

```php
require 'lib/JWTHelper.php';

// Crear token
$token = JWTHelper::create($user_id, $username);

// Verificar token
$payload = JWTHelper::verify($token);
```

---

## 📋 Checklist de Seguridad

### Antes de Producción

- [ ] Cambiar contraseña admin/admin
- [ ] Configurar HTTPS
- [ ] Activar mod_rewrite en Apache
- [ ] Desactivar display_errors
- [ ] Revisar permisos de archivos
- [ ] Backup regular de BD
- [ ] Revisar logs de seguridad
- [ ] Actualizar dependencias (vendor/)
- [ ] Configurar firewall

### Regularmente

- [ ] Revisar /var/log/apache2/gestion-horas-error.log
- [ ] Revisar /logs/auth.log
- [ ] Rotar contraseñas de BD
- [ ] Actualizar parches de PHP/MySQL
- [ ] Revisar acceso a archivos sensibles
- [ ] Verificar credenciales no expuestas

---

## 🚨 Reportar Vulnerabilidades

**No abras GitHub issues públicos para vulnerabilidades.**

Contacta a: `[tu email de seguridad]`

Incluye:
- Descripción detallada
- Pasos para reproducir
- Impacto potencial
- Versión afectada

---

## 📚 Referencias

- [OWASP Top 10](https://owasp.org/www-project-top-ten/)
- [PHP Security Best Practices](https://www.php.net/manual/en/security.php)
- [Password Hashing](https://www.php.net/manual/en/faq.passwords.php)
- [Prepared Statements](https://www.php.net/manual/en/pdo.prepared-statements.php)

---

## Auditoría de Este Proyecto

**Fecha:** 2026-01-27  
**Estado:** ✅ Completo

### Cambios Realizados

1. ✅ **Anonimizar .env.example**
   - Reemplazar credenciales reales con placeholders
   - Agregar instrucciones de configuración

2. ✅ **Mejorar fallbacks de credenciales**
   - `config.php`: 'CHANGE_ME_IN_ENV' para password
   - `db.php`: '' (vacío) para password, requiere .env

3. ✅ **Actualizar .gitignore**
   - Agregar categorías documentadas
   - Cubrir todos los tipos de archivos sensibles
   - Mencionar logs, uploads, cache, etc.

4. ✅ **Crear este documento SECURITY.md**
   - Guía de mejores prácticas
   - Checklist de seguridad
   - Procedimientos de reporte

### Archivos Rastreados

- ✅ `.env.example` (plantilla anonimizada)
- ❌ `.env` (ignorado por .gitignore)
- ❌ `*.key`, `*.pem` (ignorados)
- ❌ `/logs` (ignorado)

---

**¡Gracias por mantener GestionHorasTrabajo seguro!**
