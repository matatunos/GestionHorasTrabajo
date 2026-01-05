# Lógica de Captura de Datos - Extensión Chrome

## Resumen

La extensión Chrome captura datos de fichajes de dos formatos:

1. **Formato TRAGSA** - Tabla específica con estructura HTML especial (`id="tabla_fichajes"`)
2. **Formato Estándar** - Cualquier tabla HTML con fechas y horarios

## Detección de Año

### Problema Original

Cuando una página contiene datos de dos años (ej: diciembre 2025 + enero 2026), la extensión necesita detectar correctamente a qué año pertenece cada fecha.

**Escenario**: 
- Página descargada en enero 2026 mostrando últimas semanas de diciembre + primeras de enero
- Fechas: `12-12` (diciembre), `12-01` (enero)
- Año por defecto: 2026 (año actual)
- **Problema anterior**: Asignaba `2026-12-12` y `2026-01-12` (ambos en 2026)

### Solución Implementada

Se agregó detección automática de cambio de año (Similar a `importFichajes.js`):

```javascript
// Detectar si hay cambio de año (ej: diciembre 2025 + enero 2026)
const months = {};
for (let dateStr of Object.keys(data)) {
  const month = dateStr.split('-')[1];
  if (!months[month]) months[month] = [];
  months[month].push(dateStr);
}

if (months['01'] && months['12']) {
  // Hay tanto enero como diciembre - ajustar diciembre
  for (let dateStr of months['12']) {
    const parts = dateStr.split('-');
    const correctedDate = `${parseInt(parts[0]) - 1}-${parts[1]}-${parts[2]}`;
    data[correctedDate] = data[dateStr];
    delete data[dateStr];
  }
}
```

**Resultado**:
- `2026-12-12` → `2025-12-12` ✅
- `2026-01-12` → `2026-01-12` ✅

## Flujo de Captura de Datos

### 1. Usuario hace clic en "Capturar datos" (popup.js)

```javascript
captureData() {
  chrome.tabs.query({active: true, currentWindow: true}, (tabs) => {
    chrome.tabs.sendMessage(tabs[0].id, {action: 'captureFichajes'});
  });
}
```

### 2. Content Script extrae datos (content.js)

```javascript
chrome.runtime.onMessage.addListener((request) => {
  if (request.action === 'captureFichajes') {
    const tragsaData = extractTragsaData();      // Intenta TRAGSA
    const standardData = extractStandardData();  // Intenta estándar
    const data = tragsaData || standardData;
    sendResponse({ success: true, data: data, sourceFormat: ... });
  }
});
```

### 3. Popup muestra preview (popup.js)

```javascript
chrome.tabs.sendMessage(tabs[0].id, {action: 'captureFichajes'}, (response) => {
  if (response.success) {
    displayPreview(response.data); // Mostrar tabla preview
  }
});
```

### 4. Usuario confirma "Importar" (popup.js)

```javascript
importData() {
  chrome.runtime.sendMessage({
    action: 'importFichajes',
    data: this.capturedData,
    sourceFormat: this.sourceFormat,
    appUrl: this.appUrl
  });
}
```

### 5. Background Service Worker importa (background.js)

```javascript
chrome.runtime.onMessage.addListener((request) => {
  if (request.action === 'importFichajes') {
    importFichajes(request.data, request.sourceFormat, request.appUrl);
  }
});
```

Procesa cada entrada y envía a `/api.php` con token si está disponible.

### 6. Servidor importa datos (api.php)

```php
// Validar token o sesión
$user_id = validate_extension_token($token) ?? get_current_user()['id'];

// UPSERT entries en tabla 'entries'
foreach ($entries as $entry) {
  INSERT OR UPDATE entries SET ...
}
```

## Comparación: Extensión vs Importar HTML

### Importar archivo HTML (import.php)

- Usuario descarga HTML manualmente
- Carga archivo en formulario
- Especifica año explícitamente
- Usa `importFichajes.js` para parsear
- **Ventaja**: El usuario controla el año

### Captura con Extensión (content.js)

- Captura automática de página actual
- Detecta año del selector de semana (`#ddl_semanas`)
- Auto-ajusta si hay cambio de año
- **Ventaja**: Más rápido y automático

## Formatos Soportados

### TRAGSA Format

```html
<table id="tabla_fichajes">
  <tr class="fechas">
    <td></td>
    <td>12-12</td>
    <td>13-12</td>
    ...
  </tr>
  <tr class="horas">
    <td></td>
    <td><span>07:30</span><span>17:00</span></td>
    ...
  </tr>
</table>
```

**Extracción**:
- Fechas de `tr.fechas` → `["12-12", "13-12", ...]`
- Tiempos de `tr.horas` → spans con formato `HH:MM`
- Año detectado del selector `#ddl_semanas`

### Standard Format

```html
<table>
  <tr>
    <th>Fecha</th>
    <th>Entrada</th>
    <th>Salida</th>
  </tr>
  <tr>
    <td>12/12/2025</td>
    <td>07:30</td>
    <td>17:00</td>
  </tr>
</table>
```

**Extracción**:
- Headers de first row
- Mapeo automático: `fecha` → date key
- Datos de columnas restantes

## Depuración

### Console Logs de la Extensión

```javascript
// En DevTools (F12) → Console
[GestionHoras] Iniciando captura de datos...
[GestionHoras] Datos TRAGSA: 15 registros
[GestionHoras] ✅ Captura exitosa: 15 registros
[GestionHoras] ✅ Años ajustados: diciembre movido a año anterior
```

### Enviar Feedback

Si los datos se importan en el año incorrecto:

1. Abre DevTools (F12)
2. Busca logs con `[GestionHoras]`
3. Verifica que dice "Años ajustados" si hay enero+diciembre
4. Si no aparece, puede ser un formato no detectado

## Próximas Mejoras

- [ ] Permitir selección manual de año en popup
- [ ] Detectar más formatos de tabla
- [ ] Mostrar preview con fechas para confirmar
- [ ] Logging de cada fecha procesada
