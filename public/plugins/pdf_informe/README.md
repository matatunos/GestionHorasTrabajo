# Plugin: pdf_informe

Este plugin genera un informe en PDF con la misma estructura visual que el documento de ejemplo proporcionado, pero usando datos obtenidos de la base de datos del sistema.

- No modifica nada fuera de la carpeta del plugin.
- Utiliza la librería TCPDF para la generación de PDFs.
- El diseño replica tablas, cabeceras y formato del PDF de ejemplo.

## Uso

1. Instala las dependencias (TCPDF).
2. Configura la conexión a la base de datos si es necesario.
3. Ejecuta `generar_informe.php` para generar el PDF con los datos actuales.

---

Puedes personalizar la plantilla en `plantilla_informe.php` para adaptarla a futuros cambios de formato.
