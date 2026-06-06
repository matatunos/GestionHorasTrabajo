# Instrucciones de instalación del plugin `pdf_informe`

Este plugin permite generar informes en PDF con estructura personalizada, usando datos de la base de datos del sistema.

## Requisitos
- PHP 7.2 o superior
- Composer (https://getcomposer.org/)
- Acceso a la base de datos de GestionHorasTrabajo


## Pasos de instalación

1. **Accede a la carpeta del plugin:**

   ```bash
   cd plugins/pdf_informe
   ```

2. **Instala Composer (si no lo tienes):**

   Puedes instalar Composer ejecutando:

   ```bash
   php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
   php composer-setup.php
   php -r "unlink('composer-setup.php');"
   mv composer.phar /usr/local/bin/composer
   ```
   O sigue las instrucciones oficiales en: https://getcomposer.org/download/

3. **Instala las dependencias del plugin:**

   Ejecuta el siguiente comando para instalar TCPDF y otras dependencias:

   ```bash
   composer install
   ```

3. **Configura la conexión a la base de datos:**

   Edita el archivo `generar_informe.php` y ajusta la línea de conexión PDO:

   ```php
   $pdo = new PDO('mysql:host=localhost;dbname=gestionhoras', 'usuario', 'password');
   ```
   Cambia `usuario` y `password` por tus credenciales reales.

4. **Genera el informe:**

   Ejecuta el script para crear el PDF:

   ```bash
   php generar_informe.php
   ```

   El archivo generado se llamará `informe_generado.pdf` y estará en la carpeta del plugin.

## Personalización
- Puedes modificar la plantilla del PDF editando `plantilla_informe.php`.
- Para cambiar los datos que se muestran, ajusta la consulta SQL en `generar_informe.php`.

---

¿Dudas o mejoras? Edita este plugin sin miedo: todo queda dentro de la carpeta `plugins/pdf_informe`.
