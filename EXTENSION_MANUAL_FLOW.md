# Extensión Chrome: Captura Manual de Fichajes

## Cambio de diseño (v1.1.0)

La extensión **ya no detecta automáticamente** las páginas de fichajes. En su lugar, utiliza un **flujo manual** donde el usuario controla explícitamente cuándo capturar datos.

### ¿Por qué este cambio?

1. **Mejor rendimiento**: No procesa todas las páginas que visitas
2. **Control explícito**: Sabes exactamente cuándo se extraen datos
3. **Compatible con cualquier sitio**: Funciona en TRAGSA, HTML personalizado, páginas locales, etc.
4. **UX más clara**: Botón visible, vista previa, confirmación antes de importar

---

## Flujo de uso

```
Usuario haz clic en icono → Popup abre → Usuario presiona "Capturar datos"
                                         ↓
                            Sistema extrae datos de página
                                         ↓
                            Muestra vista previa en popup
                                         ↓
                        Usuario revisa y presiona "Importar"
                                         ↓
                            Se envía al servidor y se guarda
                                         ↓
                            Confirmación de éxito
```

---

## Componentes de la extensión

### 1. **popup.html** - Interfaz del usuario

```html
<!-- Botón principal de captura -->
<button id="captureBtn">📥 Capturar datos de esta página</button>

<!-- Vista previa (aparece después de capturar) -->
<div id="dataSection" style="display: none;">
  <div id="preview"><!-- Lista de datos capturados --></div>
  <button id="importBtn">✅ Importar fichajes</button>
</div>

<!-- Configuración (opcional) -->
<button id="settingsToggle">⚙️ Configuración</button>
<div id="settings">
  <input id="appUrl" placeholder="URL de GestionHorasTrabajo">
  <button id="saveBtn">💾 Guardar</button>
  <button id="resetBtn">🔄 Por defecto</button>
</div>
```

### 2. **popup.js** - Lógica del popup

```javascript
// Cuando el usuario hace clic en "Capturar datos"
captureData() {
  1. Envía mensaje 'captureFichajes' al content script
  2. Content script extrae datos de la página actual
  3. Devuelve JSON con datos capturados
  4. Popup muestra vista previa
  5. Activa botón "Importar fichajes"
}

// Cuando el usuario hace clic en "Importar"
importData() {
  1. Lee URL configurada de chrome.storage.sync
  2. Envía mensaje al background script con datos + URL
  3. Background script hace POST a /index.php
  4. Muestra confirmación de éxito
}
```

### 3. **content.js** - Extrae datos de la página

```javascript
// Escucha mensaje del popup
chrome.runtime.onMessage.addListener((request) => {
  if (request.action === 'captureFichajes') {
    // Intenta detectar formato TRAGSA primero
    const tragsaData = extractTragsaData()
    
    // Si no, intenta formato HTML estándar
    const standardData = extractStandardData()
    
    // Devuelve los datos encontrados
    return { data, sourceFormat, count }
  }
})

// Formato TRAGSA: busca tabla id="tabla_fichajes"
// Extrae: fechas de tr.fechas, horas de tr.horas con spans

// Formato estándar: busca table[border="1"]
// Extrae: headers de thead th, datos de tbody tr
```

### 4. **background.js** - Importa a servidor

```javascript
// Recibe datos del popup
chrome.runtime.onMessage.addListener((request) => {
  if (request.action === 'importFichajes') {
    // Procesa cada fecha capturada
    for each entry {
      // Convierte tiempos (TRAGSA o estándar) a formato estándar
      // POST a appUrl/index.php con parametros:
      //   date, start, end, coffee_out, coffee_in, lunch_out, lunch_in, note
      // Cuenta los importados exitosamente
    }
    return { success, count, errors }
  }
})

// Manejo inteligente de tiempos:
// 2 tiempos  → start + end
// 4 tiempos  → start + coffee_out + coffee_in + end
// 6+ tiempos → start + coffee_out + coffee_in + lunch_out + lunch_in + end
```

---

## Formatos soportados

### TRAGSA
- Tabla con id="tabla_fichajes"
- Fila con class="fechas" contiene fechas (ej: "01-ene", "02-ene")
- Fila con class="horas" contiene spans con tiempos (ej: "08:00", "09:00", "13:00")

Ejemplo:
```html
<table id="tabla_fichajes">
  <tr class="fechas">
    <td></td>
    <td>01-ene</td>
    <td>02-ene</td>
  </tr>
  <tr class="horas">
    <td>Horas</td>
    <td><span>08:00</span><span>09:00</span><span>13:00</span><span>14:00</span></td>
    <td><span>08:30</span><span>17:30</span></td>
  </tr>
</table>
```

### HTML Estándar
- Tabla con `border="1"` (o similar)
- Cabecera (thead th) con nombres de columnas
- Filas (tbody tr) con datos

Ejemplo:
```html
<table border="1">
  <thead>
    <tr><th>Fecha</th><th>Entrada</th><th>Salida</th></tr>
  </thead>
  <tbody>
    <tr><td>2024-01-15</td><td>08:00</td><td>17:00</td></tr>
  </tbody>
</table>
```

---

## Instalación

### Opción 1: ZIP descargado (Recomendado)
```bash
# 1. Descargar ZIP desde la aplicación
# 2. Descomprimir en tu computadora
# 3. Chrome → chrome://extensions
# 4. Modo de desarrollador: ON
# 5. Cargar extensión sin empaquetar → selecciona la carpeta descomprimida
```

### Opción 2: Desde repositorio
```bash
git clone -b feature/multiuser-dashboard https://github.com/matatunos/GestionHorasTrabajo.git
cd GestionHorasTrabajo/chrome-extension

# Chrome → chrome://extensions
# Modo de desarrollador: ON
# Cargar extensión sin empaquetar → selecciona esta carpeta
```

---

## Seguridad

1. **Sin auto-ejecución**: Content script no hace nada hasta que se solicita captura
2. **Extracción local**: Datos se extraen en el navegador del usuario, no en servidor
3. **HTTPS cuando es posible**: Envío seguro de datos a servidor
4. **Sin permisos innecesarios**: Solo accede a datos cuando se solicita
5. **Validación servidor**: index.php valida todos los datos antes de guardar

---

## Solución de problemas

### "No veo el icono de la extensión"
- Verifica: chrome://extensions → la extensión debe estar habilitada
- Si descargaste el ZIP, asegúrate de haberla instalado correctamente

### "El botón 'Capturar' no funciona"
- Abre la consola (F12) y busca errores
- Verifica que la página tenga datos de fichajes (tabla)

### "Los datos no se importan"
- Verifica que estés autenticado en GestionHorasTrabajo
- Comprueba la URL configurada en ⚙️ Configuración
- Abre la consola para ver mensajes de error

### "Datos incompletos o incorrectos"
- La página podría tener formato diferente
- Comunica el HTML de la tabla para que se agregue soporte

---

## Configuración

**URL preconfigurada**: Si descargaste el ZIP desde la aplicación, la URL está preconfigurada automáticamente (en `config.js`).

**Cambiar URL**:
1. Haz clic en el icono azul
2. Haz clic en "⚙️ Configuración"
3. Ingresa nueva URL (ej: http://192.168.1.100 o https://miapp.com)
4. Haz clic en "💾 Guardar"

La URL se almacena en `chrome.storage.sync` (sincroniza entre dispositivos si usas cuenta Google).

---

## Desarrollo

### Archivos principales
- `manifest.json` - Configuración de extensión
- `popup.html` - Interfaz visual
- `popup.js` - Lógica del popup y flujo
- `content.js` - Extracción de datos de página
- `background.js` - Importación a servidor
- `config.js` - Generado dinámicamente con URL preconfigurada

### Cambios recientes (v1.1.0)
- Eliminada detección automática de páginas
- Implementado flujo manual: captura por botón
- Mejorado popup.js con vista previa antes de importar
- Ampliados permisos en manifest.json para mayor compatibilidad
- Actualizada documentación de ayuda

---

## Próximas mejoras posibles

- [ ] Editar datos en vista previa antes de importar
- [ ] Historial de importaciones
- [ ] Rollback de importaciones recientes
- [ ] Detectar formatos adicionales automáticamente
- [ ] Publicar en Chrome Web Store (actualmente: extensión no empaquetada)
