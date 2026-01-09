## 🔄 Cómo funciona la importación de datos - Extensión Chrome

### Flujo general de importación

```
[Página HTML con fichajes]
        ↓
[Content Script detecta la página]
        ↓
[Usuario hace clic en botón "Importar"]
        ↓
[Content Script extrae datos del HTML]
        ↓
[Content Script envía datos al Background Script]
        ↓
[Background Script procesa y envía al servidor]
        ↓
[Servidor recibe y guarda en base de datos]
        ↓
[Usuario ve confirmación de importación]
```

---

## 📋 Paso 1: Detección de página (content.js)

### ¿Cuándo se activa?
La extensión detecta si la página contiene datos de fichajes buscando:

```javascript
// Formato EXTERNAL
const externalTable = document.getElementById('tabla_fichajes');

// Formato estándar HTML
const standardTable = document.querySelector('table[border="1"]');
const hasDataColumns = Array.from(standardTable.querySelectorAll('th'))
  .some(th => ['Entrada', 'Salida', 'Fecha', 'Día'].some(text => th.textContent.includes(text)))
```

### ¿Qué hace?
Si detecta una página válida, agrega un botón flotante en la esquina inferior derecha:
```
"📥 Importar a GestionHorasTrabajo"
```

---

## 🔍 Paso 2: Extracción de datos

### Formato EXTERNAL (formato específico de EXTERNAL)

**Estructura HTML esperada:**
```html
<table id="tabla_fichajes">
  <tr class="fechas">
    <td>08-dic</td>
    <td>09-dic</td>
    ...
  </tr>
  <tr class="horas">
    <td>
      <div class="Terminal"><span>07:34</span></div>
      <div class="Terminal"><span>10:50</span></div>
      <div class="Terminal"><span>11:13</span></div>
      ...
    </td>
  </tr>
</table>
```

**Cómo se extrae:**
1. Obtiene las fechas de `tr.fechas`
2. Obtiene todas las horas de `tr.horas` agrupadas por columna
3. Inteligentemente agrupa las horas:
   - 2 horas = entrada y salida
   - 4 horas = entrada, coffee_out, coffee_in, salida
   - 6+ horas = entrada, coffee_out, coffee_in, lunch_out, lunch_in, salida

**Resultado:** Objeto con estructura:
```javascript
{
  '2025-12-08': {
    times: ['07:34', '10:50', '11:13', '15:00', ...],
    format: 'external'
  },
  '2025-12-09': {
    times: ['08:15', '10:30', '11:00', '13:30', ...],
    format: 'external'
  }
}
```

### Formato HTML estándar

**Estructura HTML esperada:**
```html
<table border="1">
  <thead>
    <tr>
      <th>Fecha</th>
      <th>Entrada</th>
      <th>Salida Café</th>
      <th>Entrada Café</th>
      <th>Salida Comida</th>
      <th>Entrada Comida</th>
      <th>Salida</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td>02/12</td>
      <td>08:00</td>
      <td>10:30</td>
      <td>10:45</td>
      <td>14:00</td>
      <td>15:00</td>
      <td>17:00</td>
    </tr>
  </tbody>
</table>
```

**Cómo se extrae:**
1. Lee los headers de la tabla
2. Mapea las columnas a campos estándar:
   - "Entrada" → start
   - "Salida Café" → coffee_out
   - "Entrada Café" → coffee_in
   - "Salida Comida" → lunch_out
   - "Entrada Comida" → lunch_in
   - "Salida" → end

**Resultado:** Similar al formato EXTERNAL pero con mapeamiento directo de columnas.

---

## 📤 Paso 3: Envío al servidor

### Content Script → Background Script
El content.js envía un mensaje al background script:

```javascript
chrome.runtime.sendMessage({
  action: 'importFichajes',
  data: data,  // Datos extraídos
  sourceFormat: 'external'  // o 'standard'
}, response => {
  if (response.success) {
    alert(`✅ ${response.count} fichajes importados`);
  }
});
```

### Background Script → Servidor
El background.js recibe el mensaje y envía una solicitud POST al servidor:

```javascript
const response = await fetch(`${appUrl}/index.php`, {
  method: 'POST',
  headers: {
    'Content-Type': 'application/x-www-form-urlencoded',
    'X-Requested-With': 'XMLHttpRequest'
  },
  credentials: 'include',  // Incluye cookies de sesión
  body: new URLSearchParams({
    date: '2025-12-08',
    start: '07:34',
    end: '18:02',
    coffee_out: '10:50',
    coffee_in: '11:13',
    lunch_out: '13:50',
    lunch_in: '15:00',
    note: 'Importado vía extensión Chrome - external format'
  }).toString()
});
```

**Parámetros enviados:**
- `date` - Fecha en formato YYYY-MM-DD
- `start` - Hora de entrada (HH:MM)
- `end` - Hora de salida (HH:MM)
- `coffee_out` - Salida desayuno (opcional)
- `coffee_in` - Entrada desayuno (opcional)
- `lunch_out` - Salida comida (opcional)
- `lunch_in` - Entrada comida (opcional)
- `note` - Nota descriptiva

---

## 💾 Paso 4: Guardado en servidor (index.php)

### Recepción de datos
El servidor recibe el POST en la sección de "handle POST create/update entry":

```php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['date'])) {
  $date = $_POST['date'];
  $data = [
    'start' => $_POST['start'] ?: null,
    'coffee_out' => $_POST['coffee_out'] ?: null,
    'coffee_in' => $_POST['coffee_in'] ?: null,
    'lunch_out' => $_POST['lunch_out'] ?: null,
    'lunch_in' => $_POST['lunch_in'] ?: null,
    'end' => $_POST['end'] ?: null,
    'note' => $_POST['note'] ?: '',
  ];
  // ... validación y guardado
}
```

### Validación
Antes de guardar, la aplicación valida los datos:

```php
$validation = validate_time_entry($data);
if (!$validation['valid']) {
  // Retornar errores
}
```

**Validaciones:**
- Hora de entrada debe ser antes que hora de salida
- Las pausas deben estar dentro de las horas de trabajo
- Formato de horas correcto (HH:MM)
- Lógica consistente de entrada/salida

### UPSERT en base de datos
Si la fecha ya existe, actualiza; si no existe, inserta:

```php
$stmt = $pdo->prepare('SELECT id FROM entries WHERE user_id = ? AND date = ? LIMIT 1');
$stmt->execute([$user['id'], $date]);
$row = $stmt->fetch();

if ($row) {
  // UPDATE
  $stmt = $pdo->prepare('UPDATE entries SET ... WHERE id=?');
  $stmt->execute([...valores..., $row['id']]);
} else {
  // INSERT
  $stmt = $pdo->prepare('INSERT INTO entries (...) VALUES (...)');
  $stmt->execute([...valores...]);
}
```

**Tabla afectada:**
```sql
CREATE TABLE entries (
  id INT PRIMARY KEY AUTO_INCREMENT,
  user_id INT NOT NULL,
  date DATE NOT NULL UNIQUE,
  start TIME,
  coffee_out TIME,
  coffee_in TIME,
  lunch_out TIME,
  lunch_in TIME,
  end TIME,
  note TEXT,
  absence_type VARCHAR(20)
);
```

---

## ✅ Paso 5: Confirmación

### Respuesta del servidor
El servidor responde con JSON:

```php
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
  header('Content-Type: application/json');
  echo json_encode(['ok' => true]);
  exit;
}
```

### Background Script procesa la respuesta
```javascript
if (!response.ok) {
  errors.push(`${date}: Error HTTP ${response.status}`);
} else {
  imported++;
}
```

### Content Script muestra resultado
```javascript
if (response && response.success) {
  alert(`✅ ${response.count} fichajes importados correctamente`);
} else {
  alert('❌ Error al importar fichajes: ' + (response?.error || 'Error desconocido'));
}
```

---

## 🔐 Seguridad

### Autenticación
- Se envía con `credentials: 'include'` para incluir cookies de sesión
- El servidor verifica que el usuario esté autenticado con `require_login()`
- Los datos se guardan solo para el usuario autenticado (`user_id`)

### Validación
- La aplicación valida cada entrada antes de guardar
- Se verifica que pertenezcan al usuario autenticado
- Se valida la coherencia de las horas (entrada < salida, pausas dentro del rango)

### Aislamiento de datos
- Cada usuario solo ve sus propias entradas
- Las operaciones DELETE y UPDATE verifican el user_id

---

## 📊 Ejemplo completo de importación

### 1. Usuario abre página HTML con fichajes
```
Página: ~/Downloads/Datos_de_Usuario.html
```

### 2. Extension detecta la página y agrega botón
```
"📥 Importar a GestionHorasTrabajo" aparece en pantalla
```

### 3. Usuario hace clic
```
Content.js extrae datos:
{
  '2025-12-09': { times: ['07:34', '10:50', '11:13', '15:00', '16:50', '18:02'] },
  '2025-12-10': { times: ['08:18', '10:22', '13:11', '15:03', '16:16', '18:02'] },
  ...
}
```

### 4. Background.js convierte a entrada estándar
```javascript
{
  start: '07:34',
  coffee_out: '10:50',
  coffee_in: '11:13',
  lunch_out: '15:00',
  lunch_in: '16:50',
  end: '18:02'
}
```

### 5. Envía POST a servidor
```
POST http://tuapp.com/index.php
date=2025-12-09&start=07:34&coffee_out=10:50&...
```

### 6. Servidor valida y guarda
```sql
INSERT INTO entries 
(user_id, date, start, coffee_out, coffee_in, lunch_out, lunch_in, end, note)
VALUES (1, '2025-12-09', '07:34', '10:50', '11:13', '15:00', '16:50', '18:02', 'Importado...')
```

### 7. Usuario ve confirmación
```
"✅ 5 fichajes importados correctamente"
```

---

## 🐛 Troubleshooting

### "Los datos no se importan"
**Causas posibles:**
1. URL configurada incorrectamente → Verificar en popup de extensión
2. No estás autenticado → Inicia sesión en la aplicación
3. Validación falla → Revisa la consola (F12) para ver errores

### "Los datos se importan incompletos"
**Causas posibles:**
1. Las pausas no se detectan → Estructura HTML diferente
2. Formato de hora no reconocido → Intenta con DD/MM o DD-mes
3. Tabla no tiene formato estándar → Ajusta el HTML

### "Número incorrecto de horas importadas"
1. Algunas filas no tienen datos → Se saltan automáticamente
2. Errores de validación → Revisa cada entrada en la tabla

