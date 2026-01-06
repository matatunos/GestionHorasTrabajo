# 📋 Sugerencias de Horario Mejoradas - Con Información de Jornada

## Cambio Implementado

Se ha agregado información detallada sobre el tipo de jornada y pausa comida en cada sugerencia de horario.

---

## 📊 Nuevo Formato de Respuesta

### Estructura Anterior
```json
{
  "suggestions": [
    {
      "day_name": "Tuesday",
      "start": "08:00",
      "end": "17:00",
      "hours": 8,
      "reasoning": "Basado en 25 registros históricos | Jornada partida",
      "confidence": "alta"
    }
  ]
}
```

### Estructura Nueva (MEJORADA)
```json
{
  "suggestions": [
    {
      "date": "2024-01-09",
      "day_name": "Tuesday",
      "day_of_week": 2,
      "start": "08:00",
      "end": "17:00",
      "hours": 8,
      "confidence": "alta",
      "pattern_count": 25,
      "reasoning": "Basado en 25 registros históricos | Jornada partida",
      "shift_type": "partida",
      "shift_label": "Jornada Partida",
      "has_lunch_break": true,
      "lunch_duration_minutes": 60,
      "lunch_note": "Pausa comida: ~60 min (aprox. 13:45-14:45)"
    },
    {
      "date": "2024-01-12",
      "day_name": "Friday",
      "day_of_week": 5,
      "start": "08:00",
      "end": "14:00",
      "hours": 6,
      "confidence": "alta",
      "pattern_count": 25,
      "reasoning": "Basado en 25 registros históricos | Viernes: Jornada continua, salida 14:00 (sin pausa comida)",
      "shift_type": "continua",
      "shift_label": "Jornada Continua",
      "has_lunch_break": false,
      "lunch_duration_minutes": 0,
      "lunch_note": "Sin pausa comida"
    }
  ]
}
```

---

## 🎯 Campos Nuevos Agregados

| Campo | Tipo | Descripción | Ejemplo |
|-------|------|-------------|---------|
| **shift_type** | string | Tipo de jornada (partida/continua) | `"partida"` |
| **shift_label** | string | Etiqueta legible del tipo de jornada | `"Jornada Partida"` |
| **has_lunch_break** | boolean | Si hay pausa comida en este día | `true` |
| **lunch_duration_minutes** | integer | Duración de pausa comida en minutos | `60` |
| **lunch_note** | string | Nota descriptiva sobre pausa comida | `"Pausa comida: ~60 min (aprox. 13:45-14:45)"` |

---

## 💡 Ejemplos de Uso en Frontend

### Mostrar Tipo de Jornada
```javascript
// Mostrar badge con tipo de jornada
const suggestion = suggestions[0];

const badge = document.createElement('span');
badge.textContent = suggestion.shift_label;
badge.className = suggestion.shift_type === 'partida' 
  ? 'badge-split-shift' 
  : 'badge-continuous';
```

### Mostrar Información de Pausa Comida
```javascript
const mealInfo = suggestion.has_lunch_break
  ? `🍽️ ${suggestion.lunch_note}`
  : `⏸️ ${suggestion.lunch_note}`;

console.log(mealInfo);
// Output: "🍽️ Pausa comida: ~60 min (aprox. 13:45-14:45)"
// Output: "⏸️ Sin pausa comida"
```

### Crear Tabla Completa
```html
<table>
  <tr>
    <td>${suggestion.day_name}</td>
    <td>${suggestion.start} - ${suggestion.end}</td>
    <td>
      <span class="shift-badge">${suggestion.shift_label}</span>
    </td>
    <td>${suggestion.lunch_note}</td>
  </tr>
</table>
```

---

## 🎨 Estilos CSS Recomendados

```css
/* Badge para tipo de jornada */
.badge-split-shift {
  background-color: #FFA500;  /* Orange */
  color: white;
  padding: 4px 8px;
  border-radius: 4px;
  font-size: 12px;
}

.badge-continuous {
  background-color: #4CAF50;  /* Green */
  color: white;
  padding: 4px 8px;
  border-radius: 4px;
  font-size: 12px;
}

/* Información de pausa comida */
.meal-info {
  font-size: 14px;
  color: #666;
  margin-top: 4px;
}

.meal-info.has-lunch {
  color: #FF6B6B;
}

.meal-info.no-lunch {
  color: #51CF66;
}
```

---

## 📱 Ejemplo de UI Mejorada

```
┌─────────────────────────────────────────────────────┐
│ SUGERENCIAS DE HORARIO                              │
├─────────────────────────────────────────────────────┤
│ Martes 09/01 (Confianza: alta)                      │
│ ├─ Horario: 08:00 - 17:00 (8h)                      │
│ ├─ Tipo: [Jornada Partida]                          │
│ └─ 🍽️ Pausa comida: ~60 min (aprox. 13:45-14:45)    │
├─────────────────────────────────────────────────────┤
│ Viernes 12/01 (Confianza: alta)                     │
│ ├─ Horario: 08:00 - 14:00 (6h)                      │
│ ├─ Tipo: [Jornada Continua]                         │
│ └─ ⏸️ Sin pausa comida                              │
└─────────────────────────────────────────────────────┘
```

---

## 🔄 Valores por Tipo de Jornada

### Jornada Partida (split_shift = true, NOT Friday)
```json
{
  "shift_type": "partida",
  "shift_label": "Jornada Partida",
  "has_lunch_break": true,
  "lunch_duration_minutes": 60,
  "lunch_note": "Pausa comida: ~60 min (aprox. 13:45-14:45)"
}
```

### Jornada Continua (split_shift = false)
```json
{
  "shift_type": "continua",
  "shift_label": "Jornada Continua",
  "has_lunch_break": false,
  "lunch_duration_minutes": 0,
  "lunch_note": "Sin pausa comida"
}
```

### Viernes (SIEMPRE continua)
```json
{
  "shift_type": "continua",
  "shift_label": "Jornada Continua",
  "has_lunch_break": false,
  "lunch_duration_minutes": 0,
  "lunch_note": "Sin pausa comida"
}
```

---

## 📋 Casos de Uso en Frontend

### 1. Mostrar Aviso de Pausa Comida
```javascript
if (suggestion.has_lunch_break) {
  showAlert(`Recuerda: ${suggestion.lunch_note}`);
}
```

### 2. Filtrar Sugerencias
```javascript
// Mostrar solo jornadas partidas
const splitShifts = suggestions.filter(s => s.shift_type === 'partida');

// Mostrar solo jornadas continuas
const continuousShifts = suggestions.filter(s => s.shift_type === 'continua');
```

### 3. Comparar Patrones
```javascript
// Ver si viernes es diferente
const fridaySuggestion = suggestions.find(s => s.day_of_week === 5);
const mondaySuggestion = suggestions.find(s => s.day_of_week === 1);

const shiftsDifferent = fridaySuggestion.shift_type !== mondaySuggestion.shift_type;
```

---

## ✅ Validación

El archivo `schedule_suggestions.php` ha sido actualizado y validado:

```bash
$ php -l schedule_suggestions.php
✅ No syntax errors detected
```

---

## 📝 Notas Técnicas

1. **lunch_duration_minutes** viene de `$year_config['lunch_minutes']`
   - Típicamente 60 minutos (1 hora)
   - Configurable por año en tabla `year_configs`

2. **lunch_note** es generado automáticamente
   - Para partida: "Pausa comida: ~{minutos} min (aprox. 13:45-14:45)"
   - Para continua: "Sin pausa comida"

3. **shift_type y shift_label** son redundantes con datos previos
   - Sirven para comodidad del frontend
   - No requieren análisis adicional

---

## 🚀 Implementación Completa

**Cambio de código:** 
- Ubicación: `schedule_suggestions.php` líneas ~340-355
- Adición de 5 nuevos campos a cada sugerencia
- Sin cambios en API endpoint o parámetros
- Totalmente backward compatible

**Status:** ✅ IMPLEMENTADO Y VALIDADO

---

Ahora el frontend puede mostrar claramente a los usuarios si cada día tiene pausa comida o no, mejorando significativamente la experiencia del usuario.

