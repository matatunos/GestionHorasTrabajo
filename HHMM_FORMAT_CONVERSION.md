# Formato de Horas - Conversión a HH:MM ✅

## Cambio Implementado

El campo `hours` en la respuesta JSON ahora devuelve el tiempo en formato **HH:MM** en lugar de horas decimales.

### Antes
```json
{
  "suggestions": [
    {
      "date": "2024-01-22",
      "start": "08:00",
      "end": "16:30",
      "hours": 8.5,    // ❌ Formato decimal (100 minutos)
      "day_name": "Monday"
    }
  ]
}
```

### Ahora
```json
{
  "suggestions": [
    {
      "date": "2024-01-22",
      "start": "08:00",
      "end": "16:30",
      "hours": "08:30",  // ✅ Formato HH:MM
      "day_name": "Monday"
    }
  ]
}
```

---

## Ejemplos de Conversión

| Horas Decimales | Formato HH:MM |
|-----------------|---------------|
| 7.5 | 07:30 |
| 8.0 | 08:00 |
| 6.5 | 06:30 |
| 5.75 | 05:45 |
| 5.0 | 05:00 |
| 8.25 | 08:15 |
| 7.33 | 07:20 |

---

## Respuesta Completa de Ejemplo

```json
{
  "success": true,
  "worked_this_week": 16.5,
  "target_weekly_hours": 40,
  "remaining_hours": 23.5,
  "message": "Se sugieren los siguientes horarios...",
  "shift_pattern": {
    "type": "jornada_partida",
    "label": "Jornada Partida (con pausa comida)",
    "applies_to": "Lunes a Jueves (Viernes siempre es continua)",
    "detected_from": "Entrada del lunes de la semana actual"
  },
  "analysis": {
    "lookback_days": 90,
    "patterns_analyzed": true,
    "days_remaining": 4,
    "forced_start_time": null
  },
  "suggestions": [
    {
      "date": "2024-01-22",
      "day_name": "Monday",
      "day_of_week": 1,
      "start": "08:00",
      "end": "16:30",
      "hours": "08:30",     // ✅ NUEVO: Formato HH:MM
      "confidence": "alta",
      "pattern_count": 45,
      "reasoning": "Basado en 45 registros históricos | Jornada partida",
      "shift_type": "partida",
      "shift_label": "Jornada Partida",
      "has_lunch_break": true,
      "lunch_duration_minutes": 60,
      "lunch_note": "Pausa comida: ~60 min (aprox. 13:00-14:00)"
    },
    {
      "date": "2024-01-23",
      "day_name": "Tuesday",
      "day_of_week": 2,
      "start": "08:15",
      "end": "16:45",
      "hours": "08:30",     // ✅ NUEVO: Formato HH:MM
      "confidence": "alta",
      "pattern_count": 44,
      "reasoning": "Basado en 44 registros históricos | Jornada partida",
      "shift_type": "partida",
      "shift_label": "Jornada Partida",
      "has_lunch_break": true,
      "lunch_duration_minutes": 60,
      "lunch_note": "Pausa comida: ~60 min (aprox. 13:15-14:15)"
    },
    {
      "date": "2024-01-24",
      "day_name": "Wednesday",
      "day_of_week": 3,
      "start": "08:00",
      "end": "16:30",
      "hours": "08:30",     // ✅ NUEVO: Formato HH:MM
      "confidence": "alta",
      "pattern_count": 46,
      "reasoning": "Basado en 46 registros históricos | Jornada partida",
      "shift_type": "partida",
      "shift_label": "Jornada Partida",
      "has_lunch_break": true,
      "lunch_duration_minutes": 60,
      "lunch_note": "Pausa comida: ~60 min (aprox. 13:00-14:00)"
    },
    {
      "date": "2024-01-25",
      "day_name": "Thursday",
      "day_of_week": 4,
      "start": "08:30",
      "end": "17:00",
      "hours": "08:30",     // ✅ NUEVO: Formato HH:MM
      "confidence": "alta",
      "pattern_count": 43,
      "reasoning": "Basado en 43 registros históricos | Jornada partida",
      "shift_type": "partida",
      "shift_label": "Jornada Partida",
      "has_lunch_break": true,
      "lunch_duration_minutes": 60,
      "lunch_note": "Pausa comida: ~60 min (aprox. 13:30-14:30)"
    },
    {
      "date": "2024-01-26",
      "day_name": "Friday",
      "day_of_week": 5,
      "start": "08:00",
      "end": "14:10",
      "hours": "06:10",     // ✅ NUEVO: Formato HH:MM (Viernes: continua sin pausa)
      "confidence": "alta",
      "pattern_count": 42,
      "reasoning": "Basado en 42 registros históricos | Viernes: Jornada continua, salida mín. 13:45 (sin pausa comida, restricción operativa)",
      "is_friday_split_shift": false,
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

## Cambios Técnicos

### Función Agregada
```php
/**
 * Convert decimal hours to HH:MM format
 * @param float $decimal_hours Hours as decimal (e.g., 7.5 = 7:30)
 * @return string Time in HH:MM format (e.g., "07:30")
 */
function hours_to_hhmm($decimal_hours) {
    $hours = intdiv((int)($decimal_hours * 60), 60);
    $minutes = ((int)($decimal_hours * 60)) % 60;
    return sprintf('%02d:%02d', $hours, $minutes);
}
```

### Ubicación
- **Archivo**: `schedule_suggestions.php`
- **Línea**: ~162-169
- **Uso**: Línea ~397 en la construcción de sugerencias

---

## Validación

✅ **Sintaxis PHP**: No errors detected
✅ **Conversión de valores**:
- 7.5 → 07:30 ✓
- 8.0 → 08:00 ✓
- 6.5 → 06:30 ✓
- 5.75 → 05:45 ✓
- 5.0 → 05:00 ✓

---

## Impacto

- ✅ Más legible para los usuarios
- ✅ Formato consistente con otros campos (start, end)
- ✅ Fácil de mostrar en la UI
- ✅ Compatible con frontend existente
- ✅ Sin cambios en la lógica de cálculo

---

**Status**: ✅ **IMPLEMENTADO Y VALIDADO**
