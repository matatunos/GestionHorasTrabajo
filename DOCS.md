# 📚 Documentación Rápida - Índice de Referencia

**Última actualización:** 27 de enero de 2026

## 🚀 Comienza Aquí

1. **[README.md](README.md)** - Descripción general del proyecto
2. **[INSTALL.md](INSTALL.md)** - Instalación rápida local
3. **[DEVELOPER_GUIDE.md](DEVELOPER_GUIDE.md)** - Guía técnica para desarrolladores
4. **[USER_GUIDE.md](USER_GUIDE.md)** - Manual para usuarios finales

---

## 📚 Documentación Completa

### Para Usuarios
- **[USER_GUIDE.md](USER_GUIDE.md)** - Cómo usar el sistema
  - Primeros pasos
  - Registrar fichajes
  - Ver reportes
  - Configuración personal

### Para Desarrolladores
- **[DEVELOPER_GUIDE.md](DEVELOPER_GUIDE.md)** - Arquitectura y desarrollo
  - Setup local
  - Estructura del proyecto
  - API REST
  - Testing

### Seguridad y Operaciones
- **[SECURITY.md](SECURITY.md)** - Política de seguridad
  - Mejores prácticas
  - Manejo de credenciales
  - Checklist de seguridad
  
- **[SECURITY_AUDIT.md](SECURITY_AUDIT.md)** - Verificación de auditoría
  - Checklist de anonimización
  - Verificaciones ejecutadas
  - Estado de seguridad

- **[PREPRODUCTION_CHECKLIST.md](PREPRODUCTION_CHECKLIST.md)** - Antes de producción
  - 50+ items de verificación
  - Seguridad, configuración, testing
  - Script de verificación rápida

- **[DEPLOYMENT.md](DEPLOYMENT.md)** - Desplegar a producción
  - Setup en dev/testing/prod
  - Procedimiento de actualización
  - Rollback de emergencia
  - Monitoreo post-deployment

### Historial de Cambios
- **[CHANGELOG.md](CHANGELOG.md)** - Versiones y cambios

---

## 🔧 Scripts Útiles

### Auditar Dependencias
```bash
bash scripts/audit-dependencies.sh
```

### Ver Logs de Autenticación
```bash
tail -f logs/auth.log
```

### Ejecutar Tests
```bash
php scripts/testing/check_login.php
php scripts/testing/verify_friday_constraint.php
```

---

## ⚡ Acciones Rápidas

### Instalación Local
```bash
cp .env.example .env
nano .env  # Editar con credenciales locales
composer install
php -S localhost:8000
```

### Antes de Ir a Producción
```bash
# Revisar checklist
cat PREPRODUCTION_CHECKLIST.md

# Auditar dependencias
bash scripts/audit-dependencies.sh

# Validar sintaxis
find . -name "*.php" -exec php -l {} \;
```

### Deploy a Producción
```bash
# Revisar guía
cat DEPLOYMENT.md

# Backup antes de actualizar
mysqldump -u app_user -p gestion_horas > backup.sql

# Actualizar código
git pull origin main
composer install --no-dev
```

### Rollback de Emergencia
```bash
# Volver a versión anterior
git reset --hard HEAD~1
systemctl reload apache2
```

---

## 📋 Matriz de Documentos

| Documento | Para | Contenido |
|-----------|------|----------|
| README.md | Todos | Visión general |
| INSTALL.md | Devs | Setup inicial |
| USER_GUIDE.md | Usuarios | Cómo usar |
| DEVELOPER_GUIDE.md | Devs | Arquitectura |
| SECURITY.md | Devs/Ops | Seguridad |
| SECURITY_AUDIT.md | Devs/Ops | Auditoría |
| PREPRODUCTION_CHECKLIST.md | Ops | Verificaciones |
| DEPLOYMENT.md | Ops | Desplegar |
| CHANGELOG.md | Todos | Versiones |

---

## 🎯 Por Rol

### 👤 Usuario Final
1. [USER_GUIDE.md](USER_GUIDE.md) - Cómo usar el sistema
2. [PREPRODUCTION_CHECKLIST.md](PREPRODUCTION_CHECKLIST.md) - Qué checklist antes de usar

### 👨‍💻 Desarrollador
1. [README.md](README.md) - Descripción general
2. [INSTALL.md](INSTALL.md) - Setup local
3. [DEVELOPER_GUIDE.md](DEVELOPER_GUIDE.md) - Arquitectura
4. [SECURITY.md](SECURITY.md) - Mejores prácticas
5. [CHANGELOG.md](CHANGELOG.md) - Qué cambió

### 🔧 DevOps/Administrador
1. [SECURITY.md](SECURITY.md) - Políticas
2. [PREPRODUCTION_CHECKLIST.md](PREPRODUCTION_CHECKLIST.md) - Pre-deploy
3. [DEPLOYMENT.md](DEPLOYMENT.md) - Desplegar
4. [SECURITY_AUDIT.md](SECURITY_AUDIT.md) - Verificación

---

## 🔍 Búsqueda Rápida

### Necesito saber cómo...

**...instalar localmente**
- [INSTALL.md](INSTALL.md)
- [DEVELOPER_GUIDE.md](DEVELOPER_GUIDE.md#setup-local)

**...usar el sistema**
- [USER_GUIDE.md](USER_GUIDE.md)

**...desplegar a producción**
- [DEPLOYMENT.md](DEPLOYMENT.md)
- [PREPRODUCTION_CHECKLIST.md](PREPRODUCTION_CHECKLIST.md)

**...mantener seguro el sistema**
- [SECURITY.md](SECURITY.md)
- [SECURITY_AUDIT.md](SECURITY_AUDIT.md)

**...contribuir código**
- [DEVELOPER_GUIDE.md](DEVELOPER_GUIDE.md#contribución)

**...hacer un rollback**
- [DEPLOYMENT.md](DEPLOYMENT.md#rollback-de-emergencia)

**...auditar dependencias**
- `bash scripts/audit-dependencies.sh`

---

## 📞 Contacto

Para preguntas o problemas, revisar:
1. La documentación relevante en este índice
2. SECURITY.md para reportar vulnerabilidades
3. DEVELOPER_GUIDE.md para procedimientos técnicos

---

## 📖 Versiones de Documentación

| Versión | Fecha | Cambios |
|---------|-------|---------|
| 1.2.0 | 27/01/2026 | Consolidación de docs + seguridad |
| 1.1.1 | 27/01/2026 | Reorganización + fixes |
| 1.0.0 | - | Release inicial |

---

**¡Gracias por contribuir a mantener GestionHorasTrabajo!**
