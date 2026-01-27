# Checklist de Pre-Producción

**Versión:** 1.0  
**Última actualización:** 2026-01-27

## ✅ Antes de Desplegar a Producción

Use esta lista de verificación para asegurar que todo está listo antes de llevar GestionHorasTrabajo a producción.

---

## 🔐 Seguridad

### Credenciales y Configuración

- [ ] **Archivo `.env` creado** 
  - Copiar desde `.env.example`
  - Reemplazar con credenciales reales (BD, URLs, etc.)
  - Verificar que `.env` está en `.gitignore` ✓

- [ ] **Contraseña admin cambiada**
  - Login inicial: `admin/admin`
  - Cambiar inmediatamente
  - Usar contraseña fuerte (12+ caracteres)

- [ ] **Base de datos configurada**
  - Verificar credenciales en `.env` son correctas
  - Usuario BD debe existir con permisos apropriados
  - BD debe estar vacía o migrada correctamente

- [ ] **Variables de entorno validadas**
  ```bash
  php -r "
    echo 'DB_HOST: ' . (getenv('DB_HOST') ?: 'NO SET') . PHP_EOL;
    echo 'DB_NAME: ' . (getenv('DB_NAME') ?: 'NO SET') . PHP_EOL;
    echo 'DB_USER: ' . (getenv('DB_USER') ?: 'NO SET') . PHP_EOL;
  "
  ```

- [ ] **HTTPS configurado**
  - Certificado válido (Let's Encrypt recomendado)
  - Redirigir HTTP → HTTPS
  - Headers HSTS activos

- [ ] **Permisos de archivos correctos**
  ```bash
  # Directorios writable por Apache
  chmod 755 logs/
  chmod 777 uploads/ 2>/dev/null || true
  
  # Archivos de configuración read-only
  chmod 644 config.php
  chmod 644 db.php
  ```

---

## 🔧 Configuración del Sistema

### Apache

- [ ] **mod_rewrite activo**
  ```bash
  a2enmod rewrite
  systemctl reload apache2
  ```

- [ ] **VirtualHost configurado**
  - ServerName correcto (dominio producción)
  - DocumentRoot apuntando a directorio correcto
  - AllowOverride All habilitado

- [ ] **PHP configurado para producción**
  - `display_errors = Off`
  - `log_errors = On`
  - `error_log` apuntando a archivo válido
  - `memory_limit >= 128M`
  - `upload_max_filesize >= 10M`

### Base de Datos

- [ ] **MySQL/MariaDB ejecutándose**
  ```bash
  systemctl status mysql
  ```

- [ ] **Backup automático configurado**
  ```bash
  # Cron job diario
  0 2 * * * /usr/local/bin/backup-gestion-horas.sh
  ```

- [ ] **Revisar estructura de BD**
  ```bash
  mysql -u app_user -p gestion_horas -e "SHOW TABLES;"
  ```

### Logs y Monitoreo

- [ ] **Directorio `/logs` creado y writable**
  ```bash
  mkdir -p /opt/GestionHorasTrabajo/logs
  chmod 777 /opt/GestionHorasTrabajo/logs
  ```

- [ ] **Rotación de logs configurada**
  - logrotate para `/logs/*.log`
  - Retención: mínimo 30 días
  - Compresión habilitada

- [ ] **Monitoreo de Apache logs**
  - Revisar `/var/log/apache2/gestion-horas-error.log`
  - Revisar `/var/log/apache2/gestion-horas-access.log`

---

## 🚀 Aplicación

### Funcionalidad Core

- [ ] **Página de login carga correctamente**
  ```bash
  curl -I https://calendar.favala.es/login.php
  ```

- [ ] **Dashboard es accesible tras login**
  - Verificar datos de usuario
  - Verificar cálculo de horas

- [ ] **Registro de fichajes funciona**
  - Crear entrada de prueba
  - Verificar se guardó en BD
  - Verificar se muestra en dashboard

- [ ] **API funciona**
  - Probar endpoint de login (POST /api.php)
  - Probar endpoint de datos (GET /api.php)

### Dependencias

- [ ] **Composer instalado**
  ```bash
  composer install --no-dev
  ```

- [ ] **Todas las librerías presentes**
  - `/vendor` directorio existe
  - `composer.json` está completo
  - No hay error de dependencias faltantes

### Archivos Estáticos

- [ ] **Assets disponibles**
  - CSS carga correctamente
  - JS carga correctamente
  - Imágenes se sirven

- [ ] **Permisos de lectura**
  ```bash
  find /opt/GestionHorasTrabajo -type f -perm 600 | wc -l
  # Debería ser 0 o muy pocos archivos
  ```

---

## 📊 Datos y Backups

### Base de Datos

- [ ] **Backup inicial creado**
  ```bash
  mysqldump -u app_user -p gestion_horas > /backups/gestion_horas_inicial.sql
  ```

- [ ] **Plan de backups documentado**
  - Frecuencia (diaria recomendada)
  - Retención (mínimo 30 días)
  - Ubicación de backups (externo si es posible)

- [ ] **Procedimiento de restauración probado**
  - Saber cómo restaurar desde backup
  - Documentar en DEPLOYMENT.md

### Datos Iniciales

- [ ] **Usuarios creados**
  - Al menos 2-3 usuarios de prueba
  - Admin con contraseña fuerte
  - Usuarios normales con permisos correctos

- [ ] **Configuración de años cargada**
  - Horarios para año actual
  - Períodos (verano/invierno) configurados

---

## 📝 Documentación

### Proyectos

- [ ] **README.md actualizado**
  - URL producción correcta
  - Instrucciones de uso claras

- [ ] **DEVELOPER_GUIDE.md es accesible**
  - Instrucciones de setup local
  - Documentación de API

- [ ] **SECURITY.md revisado**
  - Políticas de seguridad entendidas
  - Procedimiento de reporte de vulnerabilidades claro

### Operaciones

- [ ] **Crear DEPLOYMENT.md**
  - Pasos para nuevos deployments
  - Procedimiento de rollback
  - Contactos en caso de emergencia

- [ ] **Crear RUNBOOK.md**
  - Cómo reiniciar servicios
  - Cómo revisar logs
  - Cómo diagnosticar problemas comunes

---

## 🧪 Testing

### Funcionalidad

- [ ] **Prueba de login**
  - Usuario válido puede entrar ✓
  - Usuario inválido rechazado ✓
  - Sesión persiste ✓

- [ ] **Prueba de fichas**
  - Crear entrada/salida ✓
  - Editar fichas ✓
  - Eliminar fichas ✓
  - Calcular horas correctamente ✓

- [ ] **Prueba de ausencias**
  - Crear ausencia ✓
  - Aprobar/rechazar ✓
  - Reflejar en reportes ✓

- [ ] **Prueba de reportes**
  - Filtrar por período ✓
  - Exportar datos ✓
  - Cálculos correctos ✓

### Performance

- [ ] **Páginas cargan rápido**
  - Dashboard < 2 segundos
  - Reportes < 3 segundos

- [ ] **No hay N+1 queries**
  - Revisar slow query log
  - Optimizar si es necesario

### Seguridad

- [ ] **HTTPS funciona**
  ```bash
  curl -I https://calendar.favala.es/
  ```

- [ ] **Headers de seguridad presentes**
  ```bash
  curl -I https://calendar.favala.es/ | grep -i "x-frame\|x-content\|strict-transport"
  ```

- [ ] **No hay credenciales expuestas**
  - Revisar Network tab en DevTools
  - Verificar logs no contienen passwords
  - Verificar .env no está en git

---

## 📞 Soporte y Monitoreo

### Alertas

- [ ] **Monitoreo configurado**
  - Alertas si Apache cae
  - Alertas si BD no responde
  - Alertas si espacio en disco bajo

- [ ] **Email de alertas funciona**
  - Probar con alert de prueba
  - Verificar llega a bandeja de entrada

### Contacto

- [ ] **Equipo de soporte informado**
  - Saben cómo acceder a logs
  - Saben procedimiento de escalada
  - Tienen documentación disponible

- [ ] **Documentación de emergencia**
  - Cómo restaurar desde backup
  - Cómo reiniciar servicios
  - Contacto del desarrollador disponible

---

## 🎯 Último Paso: Go-Live

- [ ] **Checkpoint final**
  ```bash
  # Verificar que todo está en su lugar
  ls -la /opt/GestionHorasTrabajo/.env
  ls -la /opt/GestionHorasTrabajo/logs/
  ls -la /var/www/.../vendor/
  ```

- [ ] **Redirigir DNS**
  - Cambiar A record a IP producción
  - Esperar propagación (5-10 minutos)

- [ ] **Test desde navegador en producción**
  - Abrir https://calendar.favala.es
  - Login con credenciales
  - Crear ficha de prueba
  - Verificar en BD

- [ ] **Comunicar a usuarios**
  - Anunciar que sistema está en vivo
  - Proporcionar URL y credenciales iniciales
  - Informar sobre cambio de contraseña requerido

- [ ] **Monitorear primeras horas**
  - Revisar logs cada 15 minutos
  - Estar atento a errores
  - Responder rápido si hay problemas

---

## 📋 Comando para Verificar Todo

```bash
#!/bin/bash
set -e

echo "🔍 Verificando pre-producción..."

# Seguridad
echo "✓ .env existe" && test -f .env
echo "✓ .env no en git" && ! git ls-files | grep -q "^.env$"
echo "✓ HTTPS configurado" && curl -I https://calendar.favala.es | head -1

# Aplicación
echo "✓ Login accesible" && curl -I https://calendar.favala.es/login.php | grep -q "200"
echo "✓ Vendor instalado" && test -d vendor

# Base de datos
echo "✓ BD responde" && mysql -u app_user -p -e "SELECT 1;" >/dev/null 2>&1

# Logs
echo "✓ /logs writable" && test -w logs/

echo ""
echo "✅ Pre-producción checklist completo!"
```

---

**Guarda este archivo y úsalo como referencia cada vez que hagas un deployment.**
