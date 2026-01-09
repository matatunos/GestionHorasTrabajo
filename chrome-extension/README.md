# GestionHorasTrabajo Chrome Extension

Extensión de Chrome que permite importar datos de fichajes directamente desde páginas HTML con un solo click.

## Características

✅ **Detección automática** de páginas de fichajes (formatos EXTERNAL y estándar)
✅ **Un click para importar** - Botón flotante en la esquina inferior derecha
✅ **Soporta múltiples formatos**:
  - Formato EXTERNAL (tabla con clase `tabla_fichajes`)
  - Formato estándar HTML con tablas de fichajes
✅ **Extrae automáticamente**:
  - Horas de entrada/salida
  - Pausas de café
  - Pausas de comida
  - Fechas
✅ **Configuración flexible** - Establece la URL de tu aplicación

## Instalación

### Opción 1: Modo de desarrollador

1. Abre Chrome y ve a `chrome://extensions/`
2. Activa el "Modo de desarrollador" (esquina superior derecha)
3. Haz clic en "Cargar extensión sin empaquetar"
4. Selecciona la carpeta `chrome-extension`
5. ¡Listo! La extensión está instalada

### Opción 2: Empaquetada (.crx)

(Para distribución en tiendas de Chrome - requiere cuenta de desarrollador)

## Uso

1. Navega a una página HTML con datos de fichajes
2. Haz clic en el botón **"📥 Importar a GestionHorasTrabajo"** (esquina inferior derecha)
3. La extensión detectará automáticamente los datos y los importará
4. Recibirás una notificación con el número de fichajes importados

## Configuración

1. Haz clic en el icono de la extensión en la barra de herramientas
2. Ingresa la URL de tu aplicación (ej: `http://localhost`)
3. Haz clic en "💾 Guardar"

## Formatos soportados

### Formato EXTERNAL
```html
<table id="tabla_fichajes">
  <tr class="horas">
    <td>
      <div class="Terminal"><span>08:00</span></div>
      <div class="Terminal"><span>10:30</span></div>
      ...
    </td>
  </tr>
</table>
```

### Formato estándar
```html
<table border="1">
  <thead>
    <tr>
      <th>Fecha</th>
      <th>Entrada</th>
      <th>Salida</th>
      ...
    </tr>
  </thead>
  <tbody>
    <tr>
      <td>02/12</td>
      <td>08:00</td>
      <td>17:00</td>
      ...
    </tr>
  </tbody>
</table>
```

## Estructura de archivos

```
chrome-extension/
├── manifest.json          # Configuración de la extensión
├── content.js            # Script que se inyecta en las páginas
├── background.js         # Service worker (backend de la extensión)
├── popup.html           # Interfaz de configuración
├── popup.js             # Script del popup
├── README.md            # Este archivo
└── images/
    ├── icon-16.png      # Icono 16x16
    ├── icon-48.png      # Icono 48x48
    └── icon-128.png     # Icono 128x128
```

## Cómo funciona

### 1. Detección
El `content.js` detecta automáticamente si la página contiene datos de fichajes buscando:
- Tabla con id `tabla_fichajes` (formato EXTERNAL)
- Tablas con columnas "Entrada", "Salida", "Fecha", etc.

### 2. Extracción
Extrae automáticamente:
- Fechas
- Horas de entrada/salida
- Pausas (café, comida)
- Convierte formatos variados (DD-mes, DD/MM/YY, etc.)

### 3. Envío
Envía los datos a tu aplicación usando:
- **URL**: La configurada en el popup
- **Método**: POST a `/index.php`
- **Credenciales**: Se envían con `credentials: 'include'` para mantener sesión

### 4. Validación
- Verifica que existan al menos hora de entrada y salida
- Maneja errores de red y HTTP
- Reporta el número de importaciones exitosas

## Notas de seguridad

⚠️ **Importante**: 
- La extensión envía los datos a la URL configurada
- Asegúrate de que la URL es confiable
- Los datos se envían con tus cookies de sesión (para autenticación)
- No almacena datos sensibles localmente (solo URL)

## Troubleshooting

### El botón no aparece
- Verifica que la página contenga una tabla de fichajes
- Abre la consola (F12) y busca errores
- Asegúrate de que la extensión está habilitada en `chrome://extensions/`

### Los datos no se importan
- Verifica que la URL configurada es correcta
- Comprueba que has iniciado sesión en tu aplicación
- Abre la consola del navegador (F12 > Pestaña Network) para ver las requests

### Los datos se importan incompletos
- Algunos formatos pueden no ser reconocidos automáticamente
- Intenta ajustar la estructura de tu HTML para que coincida con los formatos soportados
- Abre un issue con un ejemplo del HTML

## Desarrollo

### Modificar la extensión

1. Edita los archivos en la carpeta `chrome-extension/`
2. Ve a `chrome://extensions/`
3. Haz clic en el icono 🔄 de recarga en la tarjeta de la extensión
4. Prueba los cambios

### Agregar soporte para nuevos formatos

Edita `content.js` y `background.js`:
- Agrega lógica de detección en `detectFicharPage()`
- Crea una función `extractNuevoFormato()` similar a las existentes
- Llama a la función en `importData()`

## Licencia

MIT - Igual que GestionHorasTrabajo

## Autor

Desarrollado para GestionHorasTrabajo
