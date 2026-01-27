# Guía de Deployment - GestionHorasTrabajo

**Versión:** 1.0  
**Última actualización:** 2026-01-27

## 📋 Tabla de Contenidos

1. [Ambiente de Desarrollo](#ambiente-de-desarrollo)
2. [Ambiente de Testing](#ambiente-de-testing)
3. [Ambiente de Producción](#ambiente-de-producción)
4. [Procedimiento de Actualización](#procedimiento-de-actualización)
5. [Rollback de Emergencia](#rollback-de-emergencia)
6. [Monitoreo Post-Deployment](#monitoreo-post-deployment)

---

## Ambiente de Desarrollo

### Setup Inicial

```bash
# 1. Clonar repositorio
git clone https://github.com/matatunos/GestionHorasTrabajo.git
cd GestionHorasTrabajo

# 2. Crear .env local
cp .env.example .env

# 3. Editar .env con credenciales de desarrollo
nano .env
# DB_HOST=localhost
# DB_NAME=gestion_horas_dev
# DB_USER=dev_user
# DB_PASS=dev_password

# 4. Instalar dependencias
composer install

# 5. Crear BD
mysql -u root -p -e "CREATE DATABASE gestion_horas_dev;"
mysql -u root -p gestion_horas_dev < db_schema.sql

# 6. Permisos
chmod 777 logs/

# 7. Servir localmente
php -S localhost:8000
```

### Rama de Trabajo

```bash
# Crear rama para feature
git checkout -b feature/mi-feature

# Hacer cambios
# ... editar archivos ...

# Commit
git add .
git commit -m "feat: Descripción del cambio"

# Push a rama
git push origin feature/mi-feature

# Pull Request en GitHub
```

---

## Ambiente de Testing

### Configuración de Server de Testing

```bash
# 1. Clonar con rama de staging
git clone --branch staging https://github.com/matatunos/GestionHorasTrabajo.git
cd GestionHorasTrabajo

# 2. Instalar
composer install

# 3. Configurar .env para testing
cp .env.example .env
# Editar con credenciales de testing
nano .env

# 4. Crear BD de testing
mysql -u root -p -e "CREATE DATABASE gestion_horas_testing;"
mysql -u root -p gestion_horas_testing < db_schema.sql

# 5. Cargar datos de prueba
mysql -u root -p gestion_horas_testing < scripts/seed_data.sql
```

### Ejecutar Tests

```bash
# Tests unitarios
php scripts/testing/test_*.php

# Tests de funcionalidad
php scripts/testing/check_*.php

# Verificar logs
tail -f logs/auth.log
```

---

## Ambiente de Producción

### Pre-Deployment

```bash
# Verificar checklist
cat PREPRODUCTION_CHECKLIST.md

# ✅ Todos los items completados
```

### Deployment Inicial

```bash
# 1. En el servidor de producción
ssh user@calendar.favala.es

# 2. Clonar repositorio
cd /var/www
git clone https://github.com/matatunos/GestionHorasTrabajo.git
cd GestionHorasTrabajo

# 3. Configurar .env con credenciales reales
nano .env
# Usar credenciales REALES de producción
# ⚠️ NUNCA commitear esto

# 4. Instalar dependencias (sin dev)
composer install --no-dev --no-scripts

# 5. Crear BD
mysql -u root -p gestion_horas < db_schema.sql

# 6. Permisos
chmod 755 logs/
chmod 777 logs/
chmod 755 uploads/
chmod 777 uploads/

# 7. Configurar Apache VirtualHost
nano /etc/apache2/sites-available/gestion-horas.conf
```

### Configuración de Apache

```apache
<VirtualHost *:443>
    ServerName calendar.favala.es
    ServerAlias www.calendar.favala.es
    
    DocumentRoot /var/www/GestionHorasTrabajo
    
    <Directory /var/www/GestionHorasTrabajo>
        AllowOverride All
        Require all granted
    </Directory>
    
    # Logs
    ErrorLog ${APACHE_LOG_DIR}/gestion-horas-error.log
    CustomLog ${APACHE_LOG_DIR}/gestion-horas-access.log combined
    
    # SSL (Let's Encrypt)
    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/calendar.favala.es/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/calendar.favala.es/privkey.pem
</VirtualHost>

# Redirect HTTP a HTTPS
<VirtualHost *:80>
    ServerName calendar.favala.es
    Redirect permanent / https://calendar.favala.es/
</VirtualHost>
```

### Habilitación

```bash
# Habilitar sitio
a2ensite gestion-horas
a2enmod rewrite
a2enmod ssl

# Reiniciar Apache
systemctl reload apache2

# Verificar
curl -I https://calendar.favala.es/
```

### Verificación Final

```bash
# Comprobar conectividad
curl https://calendar.favala.es/login.php

# Revisar logs
tail -100 /var/log/apache2/gestion-horas-error.log

# Probar login
curl -X POST -d "username=admin&password=admin" https://calendar.favala.es/api.php
```

---

## Procedimiento de Actualización

### Actualización Menor (1.2.0 → 1.2.1)

```bash
# 1. En el servidor
ssh user@calendar.favala.es
cd /var/www/GestionHorasTrabajo

# 2. Backup
mysqldump -u app_user -p gestion_horas > /backups/gestion_horas_before_$(date +%Y%m%d).sql

# 3. Descargar última versión
git fetch origin
git pull origin main

# 4. Actualizar dependencias
composer install --no-dev

# 5. Si hay migraciones
php scripts/migrations/migrate_*.php

# 6. Reiniciar servicios
systemctl reload apache2

# 7. Verificar
curl -I https://calendar.favala.es/
tail -20 /var/log/apache2/gestion-horas-error.log
```

### Actualización Mayor (1.x → 2.0)

```bash
# Seguir pasos anteriores más:

# 1. Revisar CHANGELOG.md para breaking changes
cat CHANGELOG.md | grep -A 20 "## \[2.0"

# 2. Backup completo (archivos + BD)
tar czf /backups/gestion_horas_complete_$(date +%Y%m%d).tar.gz /var/www/GestionHorasTrabajo/
mysqldump -u app_user -p gestion_horas > /backups/gestion_horas_$(date +%Y%m%d).sql

# 3. Actualizar con cuidado
git pull origin main --ff-only

# 4. Ejecutar todos los scripts de migración
for script in scripts/migrations/migrate_*.php; do
  echo "Ejecutando: $script"
  php "$script" || exit 1
done

# 5. Verificar integridad
php -l *.php
find lib/ scripts/ admin/ -name "*.php" -exec php -l {} \;

# 6. Test exhaustivo
bash scripts/testing/test_all.sh

# 7. Si todo bien: go live
systemctl reload apache2
```

---

## Rollback de Emergencia

### Escenario: Error en Producción Después del Deployment

```bash
# 1. INMEDIATAMENTE: Volver a versión anterior
ssh user@calendar.favala.es
cd /var/www/GestionHorasTrabajo

# 2. Resetear a commit anterior
git log --oneline -5
git reset --hard <commit-anterior>

# 3. Restaurar BD si es necesario
mysql -u app_user -p gestion_horas < /backups/gestion_horas_before_$(date +%Y%m%d).sql

# 4. Reiniciar
systemctl reload apache2

# 5. Verificar
curl -I https://calendar.favala.es/

# 6. Investigar problema
tail -100 /var/log/apache2/gestion-horas-error.log
cat logs/auth.log

# 7. Hacer fix en desarrollo
# ... corregir en rama feature ...
# ... hacer PR ...
# ... merge cuando esté listo ...
```

### Script de Rollback Automático

```bash
#!/bin/bash
# rollback.sh - Script rápido de rollback

set -e

BACKUP_DIR="/backups"
APP_DIR="/var/www/GestionHorasTrabajo"
PREVIOUS_BACKUP=$(ls -t ${BACKUP_DIR}/gestion_horas_*.sql | head -1)

echo "⚠️  ROLLBACK: Restaurando desde ${PREVIOUS_BACKUP}"

# Backup actual antes de restaurar
mysqldump -u app_user -p gestion_horas > ${BACKUP_DIR}/gestion_horas_emergency_$(date +%Y%m%d_%H%M%S).sql

# Restaurar
mysql -u app_user -p gestion_horas < ${PREVIOUS_BACKUP}

# Resetear código
cd ${APP_DIR}
git reset --hard HEAD~1

# Reiniciar
systemctl reload apache2

echo "✅ Rollback completado. Revisar logs:"
tail -50 /var/log/apache2/gestion-horas-error.log
```

---

## Monitoreo Post-Deployment

### Primeros 5 Minutos

```bash
# Terminal 1: Ver logs en tiempo real
tail -f /var/log/apache2/gestion-horas-error.log

# Terminal 2: Hacer requests de prueba
curl https://calendar.favala.es/login.php
curl https://calendar.favala.es/dashboard.php
curl -X POST https://calendar.favala.es/api.php

# Terminal 3: Monitorear BD
watch -n 1 "mysql -u app_user -p -e 'SHOW PROCESSLIST;' gestion_horas"
```

### Primeros 30 Minutos

```bash
# Revisar logs de errores
grep "ERROR\|FATAL" logs/auth.log

# Revisar performance
curl -w "Total: %{time_total}s\n" https://calendar.favala.es/dashboard.php

# Revisar autenticación
grep "login" logs/auth.log | tail -20
```

### Primeros 2 Horas

```bash
# Verificar que usuarios pueden entrar
# (comunicado a equipo QA)

# Revisar logs de Apache
tail -100 /var/log/apache2/gestion-horas-access.log

# Revisar BD (sin errores de conexión)
mysql -u app_user -p -e "SELECT COUNT(*) FROM users;" gestion_horas

# Revisar espacio en disco
df -h /var/www
du -sh logs/
```

---

## Monitoreo Continuo

### Verificaciones Diarias

```bash
#!/bin/bash
# daily-check.sh

echo "=== Verificación Diaria ==="

# 1. Servicios
echo "✓ Apache"
systemctl is-active apache2 || echo "  ⚠️ INACTIVO"

echo "✓ MySQL"
systemctl is-active mysql || echo "  ⚠️ INACTIVO"

# 2. Errores en logs
echo "✓ Errores últimas 24h"
find logs/ -name "*.log" -mtime -1 -exec grep -l ERROR {} \; || echo "  ✓ Sin errores"

# 3. Espacio en disco
echo "✓ Espacio disponible"
df -h /var/www | tail -1

# 4. Backup
echo "✓ Backup más reciente"
ls -lh /backups/gestion_horas*.sql | tail -1
```

### Alertas Automáticas

```bash
#!/bin/bash
# Añadir a crontab: 0 * * * * /usr/local/bin/gestion-horas-alerts.sh

ALERT_EMAIL="admin@example.com"
APP_DIR="/var/www/GestionHorasTrabajo"

# Checar Apache
if ! systemctl is-active apache2 > /dev/null; then
  echo "ALERTA: Apache no está activo" | mail -s "GestionHoras: Apache DOWN" $ALERT_EMAIL
  systemctl restart apache2
fi

# Checar MySQL
if ! mysql -u app_user -p -e "SELECT 1" > /dev/null 2>&1; then
  echo "ALERTA: MySQL no responde" | mail -s "GestionHoras: MySQL DOWN" $ALERT_EMAIL
  systemctl restart mysql
fi

# Checar espacio
USAGE=$(df /var/www | awk 'NR==2 {print $5}' | sed 's/%//')
if [ $USAGE -gt 90 ]; then
  echo "ALERTA: Espacio en disco al ${USAGE}%" | mail -s "GestionHoras: Espacio BAJO" $ALERT_EMAIL
fi

# Checar errores en logs
ERRORS=$(grep -c ERROR $APP_DIR/logs/auth.log 2>/dev/null || echo 0)
if [ $ERRORS -gt 10 ]; then
  echo "ALERTA: $ERRORS errores en últimas 24h" | mail -s "GestionHoras: Errores ALTOS" $ALERT_EMAIL
fi
```

---

## Contactos de Emergencia

**Desarrollador Principal:** [Tu nombre/email]  
**Administrador de Sistemas:** [Admin email]  
**Contacto de Negocio:** [Business owner]

---

## Referencias

- [PREPRODUCTION_CHECKLIST.md](PREPRODUCTION_CHECKLIST.md) - Checklist antes de ir a producción
- [SECURITY.md](SECURITY.md) - Políticas de seguridad
- [DEVELOPER_GUIDE.md](DEVELOPER_GUIDE.md) - Guía técnica

---

**Última actualización:** 27 de enero de 2026
