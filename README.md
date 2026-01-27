# GestionHorasTrabajo

**Versión:** 1.1.1  
**Estado:** ✅ Producción

Sistema web para gestionar y registrar horas de trabajo con análisis, reportes y control de horarios.

## 📖 Documentación

Hemos consolidado toda la documentación en **dos manuales principales** + changelog:

### Para Usuarios
📚 **[Manual de Usuario](USER_GUIDE.md)** - Guía completa de uso del sistema
- Cómo acceder al sistema
- Registrar entrada/salida
- Gestionar ausencias
- Ver reportes y análisis
- Configuración personal
- Preguntas frecuentes

### Para Desarrolladores
👨‍💻 **[Manual Técnico](DEVELOPER_GUIDE.md)** - Guía para desarrolladores
- Arquitectura y estructura del proyecto
- Setup de desarrollo local
- Configuración de BD
- API REST
- Seguridad y mejores prácticas
- Testing y deployment
- Cómo contribuir

### Historial de Cambios
📝 **[CHANGELOG](CHANGELOG.md)** - Versiones y cambios realizados

---

## 🚀 Inicio rápido

### Para usuarios

1. Abre `https://calendar.favala.es` en tu navegador
2. Inicia sesión con tus credenciales
3. Consulta el [Manual de Usuario](USER_GUIDE.md) para instrucciones

### Para desarrolladores

```bash
# Clonar repositorio
git clone https://github.com/matatunos/GestionHorasTrabajo.git
cd GestionHorasTrabajo

# Seguir guía completa en Manual Técnico
# Ver: DEVELOPER_GUIDE.md → Setup local
```

---

## 🔧 Requisitos mínimos

- **PHP:** 8.3+
- **BD:** MySQL 5.7+ o MariaDB 10.2+
- **Servidor:** Apache 2.4+ (u otro compatible con PHP)
- **Navegador:** Chrome, Firefox, Safari o Edge moderno

---

## 📁 Estructura del proyecto

```
/opt/GestionHorasTrabajo/
├── Archivos principales (PHP)
│   ├── index.php              Portal principal
│   ├── login.php              Autenticación
│   ├── dashboard.php          Dashboard del usuario
│   ├── api.php                API REST
│   └── config.php, db.php, auth.php, lib.php
│
├── lib/                       Librerías y helpers
├── scripts/                   Scripts CLI y utilidades
├── admin/                     Herramientas administrativas
├── tools/                     Herramientas de análisis
├── docs/                      Documentación detallada
├── logs/                      Archivos de log
│
├── USER_GUIDE.md              👈 Manual para usuarios
├── DEVELOPER_GUIDE.md         👈 Manual para desarrolladores
├── CHANGELOG.md               👈 Versiones y cambios
└── README.md                  👈 Este archivo
```

---

## ✨ Características principales

- ✅ Registro de entrada/salida
- ✅ Cálculo automático de horas trabajadas
- ✅ Soporte para horarios diferenciados (invierno/verano)
- ✅ Gestión de ausencias y permisos
- ✅ Reportes y análisis
- ✅ API REST para integraciones
- ✅ Extensión Chrome para registro rápido
- ✅ Exportación de datos (Excel, PDF)
- ✅ Autenticación segura (JWT, sesiones)

---

## 🔒 Seguridad

- Passwords hasheados con bcrypt
- Protección contra SQL injection (prepared statements)
- CSRF tokens en formularios
- Headers de seguridad HTTP
- Validación de entrada en todos los endpoints
- Logs de acceso y auditoría

---

## 📞 Soporte

**Para usuarios:** Contacta a tu administrador o consulta el [Manual de Usuario](USER_GUIDE.md#contacto-y-soporte)

**Para desarrolladores:** Consulta el [Manual Técnico](DEVELOPER_GUIDE.md#troubleshooting-técnico) o abre un issue en GitHub

---

## 📄 Licencia

Consulta [LICENSE](LICENSE) para más detalles.

---

## 🎯 Próximas mejoras

Consulta [CHANGELOG.md](CHANGELOG.md) para el roadmap de versiones futuras.

---

**Última actualización:** 27 de enero de 2026

 - Instalar el virtualhost en Apache (opcional si usas el servidor embebido PHP):

```bash
sudo cp deploy/apache/example.conf /etc/apache2/sites-available/
sudo a2ensite example.conf
sudo systemctl reload apache2
```

Nota: cuando el servicio esté online, usa el DNS real en lugar de `/etc/hosts`.

HTTPS / certificado autofirmado (desarrollo)

Para generar un certificado autofirmado y habilitar HTTPS localmente:

```bash
# crear certificado en ./ssl
chmod +x deploy/mk_selfsigned_cert.sh
./deploy/mk_selfsigned_cert.sh

# instalar y habilitar el vhost HTTPS
chmod +x deploy/apache_enable_ssl.sh
sudo deploy/apache_enable_ssl.sh

# añadir hosts si no está (necesario para usar example.com)
sudo sh -c 'echo "127.0.0.1 example.com" >> /etc/hosts'

# luego abrir https://example.com/ (acepta excepción del certificado en el navegador)
```

Si quieres que el navegador confíe automáticamente en el certificado, instala el CRT en tu sistema como certificado de confianza (no recomendado en producción).

Diagnóstico rápido si obtienes "Forbidden" (403)

1) Revisar logs de Apache (mira el vhost o el log general):

```bash
sudo tail -n 200 /var/log/apache2/example.ssl.error.log
sudo tail -n 200 /var/log/apache2/error.log
```

2) Comprobar que el vhost está activo y escuchando:

```bash
sudo apache2ctl -S
```

3) Comprobar permisos de ficheros y acceso (Apache necesita poder recorrer los directorios):

```bash
ls -ld /opt /opt/GestionHorasTrabajo
ls -l /opt/GestionHorasTrabajo/index.php

# si las carpetas no son al menos 'r-x' para others, ajustar:
sudo chmod o+rx /opt
sudo find /opt/GestionHorasTrabajo -type d -exec chmod 755 {} \;
sudo find /opt/GestionHorasTrabajo -type f -exec chmod 644 {} \;
```

4) Forzar recarga del servicio tras cambios de vhost o certificados:

```bash
sudo systemctl reload apache2
sudo apache2ctl configtest
```

Si tras esos pasos sigue el 403, pega aquí las últimas líneas de `example.ssl.error.log` y el resultado de `apache2ctl -S` y te ayudo a depurarlo.
Instalación de la base de datos MySQL (opcional)

Se incluye un esquema y script de instalación en `deploy/` que crea la base de datos y un usuario admin inicial:

```bash
cd /opt/GestionHorasTrabajo/deploy
chmod +x install_db.sh
# usa las variables DB_USER/DB_PASS si quieres cambiar credenciales
DB_USER=root DB_PASS=your_mysql_password ./install_db.sh
```

Esto crea la base `gestion_horas`, las tablas y un usuario `admin` con contraseña `admin` (cámbiala tras el primer login).

# GestionHorasTrabajo
Gestion personal de horario de trabajo
