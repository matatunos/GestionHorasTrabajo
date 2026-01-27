# 📊 Guía de Logging - GestionHorasTrabajo

**Fecha:** 8 de enero de 2026
**Sistema de Logging:** LogConfig.php

---

## 📍 Ubicación de los Logs

Todos los logs se guardan en el directorio:

```
/opt/GestionHorasTrabajo/logs/
```

### Archivos de Log

| Archivo | Propósito | Contenido |
|---------|-----------|----------|
| `logs/app.log` | Logs generales | Mensajes de aplicación |
| `logs/auth.log` | Autenticación | Intentos de login, tokens |
| `logs/error.log` | Errores | Errores de PHP y excepciones |
| `logs/api.log` | API requests | Requests/responses de API |

---

## 🔍 Cómo Acceder a los Logs

### Ver logs de autenticación (login)

```bash
# Últimas 20 líneas
tail -20 /opt/GestionHorasTrabajo/logs/auth.log

# Seguir logs en tiempo real
tail -f /opt/GestionHorasTrabajo/logs/auth.log

# Ver todos los logs
cat /opt/GestionHorasTrabajo/logs/auth.log
```

### Buscar intentos fallidos

```bash
# Intentos fallidos de login
grep "LOGIN_FAILED" /opt/GestionHorasTrabajo/logs/auth.log

# Intentos fallidos por razón
grep "user_not_found" /opt/GestionHorasTrabajo/logs/auth.log
grep "invalid_password" /opt/GestionHorasTrabajo/logs/auth.log
```

### Buscar logins exitosos

```bash
grep "LOGIN_SUCCESS" /opt/GestionHorasTrabajo/logs/auth.log
```

### Ver logs en tiempo real

```bash
# Monitorear todos los logs
tail -f /opt/GestionHorasTrabajo/logs/*.log

# Monitorear solo auth
tail -f /opt/GestionHorasTrabajo/logs/auth.log
```

---

## 📋 Formato de los Logs

### Formato JSON (Recomendado)

Los logs se guardan en formato JSON para mejor parsing:

```json
{
  "timestamp": "2026-01-08 23:45:30",
  "unix_timestamp": 1673209530,
  "ip": "192.168.1.100",
  "user_agent": "Mozilla/5.0...",
  "data": {
    "action": "LOGIN_FAILED",
    "reason": "invalid_password",
    "username": "juan",
    "user_id": 5
  }
}
```

### Procesar logs JSON

```bash
# Instalar jq (JSON processor)
sudo apt-get install jq

# Extraer solo el action de cada línea
cat /opt/GestionHorasTrabajo/logs/auth.log | jq '.data.action'

# Filtrar por action
cat /opt/GestionHorasTrabajo/logs/auth.log | jq 'select(.data.action == "LOGIN_FAILED")'

# Extraer IPs de intentos fallidos
cat /opt/GestionHorasTrabajo/logs/auth.log | jq 'select(.data.action == "LOGIN_FAILED") | .ip'

# Contar logins fallidos
cat /opt/GestionHorasTrabajo/logs/auth.log | jq 'select(.data.action == "LOGIN_FAILED")' | wc -l
```

---

## 🔧 Uso Programático de Logs

### En tu código PHP

```php
<?php
require_once __DIR__ . '/LogConfig.php';
LogConfig::init();

// Log de autenticación
LogConfig::jsonLog('auth', [
    'action' => 'LOGIN_SUCCESS',
    'user_id' => 5,
    'username' => 'juan'
]);

// Log de API
LogConfig::jsonLog('api', [
    'endpoint' => '/entries',
    'method' => 'GET',
    'status_code' => 200
]);

// Log general (string)
LogConfig::appLog('Importación completada', 'INFO');

// Log de error
LogConfig::errorLog('Conexión a BD fallida', 'ERROR');
```

---

## 📊 Análisis de Logs

### Script para análisis de intentos fallidos

```bash
#!/bin/bash
# analytics.sh - Analizar intentos fallidos de login

echo "=== ANÁLISIS DE INTENTOS FALLIDOS ==="
echo ""

echo "Total de intentos fallidos:"
grep "LOGIN_FAILED" /opt/GestionHorasTrabajo/logs/auth.log | wc -l

echo ""
echo "Intentos fallidos por razón:"
echo "- Usuario no encontrado:"
grep "user_not_found" /opt/GestionHorasTrabajo/logs/auth.log | wc -l
echo "- Contraseña inválida:"
grep "invalid_password" /opt/GestionHorasTrabajo/logs/auth.log | wc -l

echo ""
echo "IPs con más intentos fallidos:"
grep "LOGIN_FAILED" /opt/GestionHorasTrabajo/logs/auth.log | jq '.ip' | sort | uniq -c | sort -rn | head -5

echo ""
echo "Usuarios atacados (intentos fallidos):"
grep "LOGIN_FAILED" /opt/GestionHorasTrabajo/logs/auth.log | jq '.data.username' | sort | uniq -c | sort -rn
```

### Script para monitizar logs en tiempo real

```bash
#!/bin/bash
# monitor.sh - Monitorear logins en tiempo real

watch -n 1 'echo "Últimos logins exitosos:"; tail -5 /opt/GestionHorasTrabajo/logs/auth.log | grep LOGIN_SUCCESS'
```

---

## 🛡️ Mantenimiento de Logs

### Limpiar logs antiguos (más de 30 días)

```php
<?php
require_once __DIR__ . '/LogConfig.php';
LogConfig::cleanup(30); // Elimina logs de más de 30 días
```

### Comprimir logs antiguos

```bash
# Comprimir logs con gzip
gzip /opt/GestionHorasTrabajo/logs/auth.log.1
gzip /opt/GestionHorasTrabajo/logs/api.log.1

# Ver logs comprimidos
zcat /opt/GestionHorasTrabajo/logs/auth.log.1.gz | tail
```

### Rotación automática de logs (logrotate)

```bash
# Crear archivo de configuración
sudo nano /etc/logrotate.d/gestion_horas
```

Contenido:

```
/opt/GestionHorasTrabajo/logs/*.log {
    daily              # Rotar diariamente
    missingok          # No error si archivo no existe
    rotate 30          # Mantener 30 rotaciones
    compress           # Comprimir logs antiguos
    delaycompress      # No comprimir la rotación anterior
    notifempty         # No rotar si está vacío
    create 0644 www-data www-data
    sharedscripts
    postrotate
        systemctl reload php8.4-fpm > /dev/null 2>&1 || true
    endscript
}
```

Aplicar:

```bash
sudo logrotate -f /etc/logrotate.d/gestion_horas
```

---

## 📈 Alertas Sugeridas

### Monitor de intentos fallidos (script cron)

```bash
#!/bin/bash
# check_failed_logins.sh - Alerta si hay muchos intentos fallidos

FAILED_LOGIN_COUNT=$(grep "LOGIN_FAILED" /opt/GestionHorasTrabajo/logs/auth.log | wc -l)
THRESHOLD=10

if [ $FAILED_LOGIN_COUNT -gt $THRESHOLD ]; then
    echo "⚠️  ALERTA: $FAILED_LOGIN_COUNT intentos fallidos en la última hora" | \
    mail -s "GestionHorasTrabajo - Intentos fallidos" admin@example.com
fi
```

Ejecutar cada hora:

```bash
0 * * * * /opt/GestionHorasTrabajo/check_failed_logins.sh
```

---

## ✅ Checklist de Logging

- [x] LogConfig.php creado y configurado
- [x] Directorio `/opt/GestionHorasTrabajo/logs/` creado
- [x] api.php usa LogConfig para autenticación
- [x] Logs en formato JSON para parsing
- [x] IP del cliente registrada en cada evento
- [x] Timestamps en todos los logs
- [ ] Rotación de logs configurada (logrotate)
- [ ] Alertas automáticas configuradas
- [ ] Análisis de logs documentado

---

## 📞 Soporte

Para más información sobre logging en PHP, ver:
- `LogConfig.php` - Implementación completa
- `api.php` - Uso de LogConfig
- `/opt/GestionHorasTrabajo/logs/` - Archivos de log

---

**Último actualizado:** 8 de enero de 2026
