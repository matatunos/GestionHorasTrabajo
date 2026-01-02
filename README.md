# Gestión de Horas — Demo

Mini aplicación PHP para registrar una fila por día (estilo Excel) con campos:


Calcula: horas trabajadas, balance diario y balances comparados con la configuración para verano/invierno.

## Funcionalidad de Importación de Fichajes

La aplicación permite importar registros de fichajes desde archivos HTML descargados de portales externos.

### Cómo usar la función de importación:

1. **Descargar el informe HTML**: Desde tu portal de horas externo, descarga tu informe de fichajes usando la opción "Guardar como" del navegador (Ctrl+S o Cmd+S), guardándolo como archivo HTML.

2. **Acceder a la función de importación**: En la aplicación, haz clic en "Importar Fichajes" en el menú lateral.

3. **Seleccionar el archivo**: 
   - Haz clic en "Seleccionar archivo" y elige el archivo HTML descargado.
   - Indica el año correspondiente al informe (necesario ya que la tabla no suele incluir el año).

4. **Previsualizar datos**: Haz clic en "Cargar y previsualizar" para ver los datos extraídos del archivo HTML. El sistema parseará automáticamente la tabla y mostrará:
   - Día de la semana
   - Fecha original
   - Fecha en formato ISO (YYYY-MM-DD)
   - Horas registradas
   - Balance

5. **Importar**: Si la previsualización es correcta, haz clic en "Importar registros" para guardar los datos en la base de datos.

**Nota**: La utilidad de parsing está implementada en `importFichajes.js` y soporta múltiples formatos de tablas HTML comunes.

Cómo ejecutar:

```bash
cd /opt/GestionHorasTrabajo
php -S localhost:8000
# luego abrir http://localhost:8000/index.php
```

Los datos se guardan ahora en la base de datos MySQL (tabla `entries`).

Configuración local para `calendar.favala.es` (desarrollo)

 - Añadir entrada en `/etc/hosts` (requerido para probar como calendar.favala.es):

```bash
sudo sh -c 'echo "127.0.0.1 calendar.favala.es" >> /etc/hosts'
# o usar el script incluido:
./setup_hosts.sh
```

 - Instalar el virtualhost en Apache (opcional si usas el servidor embebido PHP):

```bash
sudo cp deploy/apache/calendar.favala.conf /etc/apache2/sites-available/
sudo a2ensite calendar.favala.conf
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

# añadir hosts si no está (necesario para usar calendar.favala.es)
sudo sh -c 'echo "127.0.0.1 calendar.favala.es" >> /etc/hosts'

# luego abrir https://calendar.favala.es/ (acepta excepción del certificado en el navegador)
```

Si quieres que el navegador confíe automáticamente en el certificado, instala el CRT en tu sistema como certificado de confianza (no recomendado en producción).

Diagnóstico rápido si obtienes "Forbidden" (403)

1) Revisar logs de Apache (mira el vhost o el log general):

```bash
sudo tail -n 200 /var/log/apache2/calendar.favala.ssl.error.log
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

Si tras esos pasos sigue el 403, pega aquí las últimas líneas de `calendar.favala.ssl.error.log` y el resultado de `apache2ctl -S` y te ayudo a depurarlo.
Instalación de la base de datos MySQL (opcional)

Se incluye un esquema y script de instalación en `deploy/` que crea la base de datos y un usuario admin inicial:

```bash
cd /opt/GestionHorasTrabajo/deploy
chmod +x install_db.sh
# usa las variables DB_USER/DB_PASS si quieres cambiar credenciales
DB_USER=root DB_PASS=satriani ./install_db.sh
```

Esto crea la base `gestion_horas`, las tablas y un usuario `admin` con contraseña `admin` (cámbiala tras el primer login).

# GestionHorasTrabajo
Gestion personal de horario de trabajo
