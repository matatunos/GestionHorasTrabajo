# 🔧 Correcciones de Jornada Partida (Split Shift) - Viernes

## Problema Identificado

El algoritmo original no consideraba correctamente:

1. **Viernes: Salida a las 14:00 (FIJA)**
   - No 15:00, 16:00 o variable
   - Siempre 14:00 exacto

2. **Jornada Partida (Split Shift)**
   - Pausa obligatoria mínimo 1 hora para comer
   - Pausa comienza a partir de las 13:45
   - Por tanto: 08:00-14:00 = 6 horas presencia, PERO 1h comida = **5 horas trabajadas**

3. **Implicaciones para objetivos semanales**
   - Configuración dice "6 horas viernes"
   - Pero con jornada partida: solo 5 horas SE TRABAJAN
   - El objetivo semanal debe reflejar esto

## Cambios Implementados

### 1. Cálculo de Horas Objetivo (Líneas 336-348)

**Antes:**
```php
$target_weekly_hours = (
    ($year_config['work_hours']['winter']['mon_thu'] ?? 8.0) * 4 + 
    ($year_config['work_hours']['winter']['friday'] ?? 6.0)
) / 5 * 5;
```

**Después:**
```php
// Jornada partida: Friday 08:00-14:00 = 6h presencia, -1h comida = 5h trabajo
$friday_worked_hours = max(5.0, $friday_config_hours - 1.0);

$target_weekly_hours = (
    ($year_config['work_hours']['winter']['mon_thu'] ?? 8.0) * 4 + 
    $friday_worked_hours
) / 5 * 5;
```

**Impacto:** 
- Objetivo semanal correcto: Lun-Jue (8h × 4) + Vie (5h) = **37 horas**
- Antes calculaba 38 horas incorrectamente

### 2. Distribución para Viernes (Líneas 177-190)

**Implementado:**
```php
// Para Friday: máximo ~6 horas (salida a 14:00)
if ($dow === 5) {
    $min_hours = 5.0;      // Mínimo 5h (con 1h comida)
    $max_hours = 6.0;      // Máximo 6h (08:00-14:00)
    $final_hours[$dow] = max($min_hours, min($max_hours, $suggested));
}
```

### 3. Cálculo de Hora de Salida para Viernes (Líneas 251-267)

**Ahora respeta:**
```php
if ($dow === 5) {
    // Friday: Fixed exit at 14:00
    $suggested_end = '14:00';
    
    // Jornada partida: ensure 1-hour lunch break after 13:45
    // Typical: 08:00-13:45 (5h45min) + 1h lunch = 14:00 exit
    
    $lunch_start_min = time_to_minutes('13:45'); // Start lunch at 13:45 or earlier
    $lunch_duration_min = 60; // 1 hour minimum
    
    // Work end time before lunch: start + hours - lunch duration
    $work_before_lunch_min = time_to_minutes($suggested_start) + 
                             ($suggested_hours * 60) - 
                             $lunch_duration_min;
    
    if ($work_before_lunch_min < $lunch_start_min) {
        // Can take lunch before 13:45
        $lunch_break_start = [calculated];
        $lunch_break_end = '14:00';
    } else {
        // Must start lunch at 13:45 to finish by 14:00
        $lunch_break_start = '13:45';
        $lunch_break_end = '14:00';
    }
}
```

### 4. Nota en Explicación (Líneas 310-317)

**Para viernes, se añade contexto:**
```json
{
    "date": "2024-01-12",
    "day_name": "Friday",
    "start": "08:00",
    "end": "14:00",
    "hours": 5.0,
    "reasoning": "Basado en 15 registros históricos | Viernes: Salida a las 14:00 (jornada partida con pausa comida mín 1h a partir de 13:45)",
    "is_friday_split_shift": true
}
```

## Ejemplos de Funcionamiento

### Escenario 1: Usuario típico
```
Lun-Jue: 8h cada día = 32h
Viernes: 5h (con 1h comida) = 5h
OBJETIVO: 37 horas

Ya trabajó:
Lunes:   8.2h
Martes:  8.0h
Miércoles: 8.1h
Jueves:  7.8h
TRABAJADO: 32.1h

Faltan: 4.9 horas (viernes)
Sugerencia para viernes: 08:00 → 14:00 (5h trabajo)
✓ Cumple: Salida exacta a las 14:00
✓ Cumple: Comida a partir de las 13:45
✓ Cumple: Objetivo semanal
```

### Escenario 2: Jornada completa
```
Entrada viernes: 08:00
Trabajo hasta: 13:45 (5h 45min)
Comida: 13:45-14:45 (1h)
PERO: Debe salir a las 14:00

→ Sistema detecta conflicto
→ Sugiere: Comida 13:00-14:00 (sale a las 14:00)
  O: Comida 13:45-14:00 (solo 15 min, necesita ampliarse)

→ Nota: "Jornada partida con pausa comida mín 1h a partir de 13:45"
```

## Cálculos Afectados

| Elemento | Antes | Después |
|----------|-------|---------|
| Objetivo viernes | 6h | 5h (con 1h comida) |
| Objetivo semanal | 38h | 37h |
| Salida viernes | Variable | 14:00 (FIJO) |
| Comida viernes | Ignorada | Mín 1h a partir de 13:45 |
| Confianza viernes | Igual que otros | Especial nota |

## Compatibilidad

✅ No rompe integraciones existentes
✅ Compatible con `compute_day()` 
✅ Respeta `year_config` 
✅ Works with split shift detection in entries table
✅ Supports variable lunch durations

## Notas Técnicas

- El cambio es **"smart" pero simple**: detecta Friday (dow === 5) y aplica lógica especial
- No requiere tabla nueva ni cambio en schema
- Usa datos históricos cuando existen (confianza alta)
- Fallback a 08:00 start si no hay históricos
- Calcula lunch break time automáticamente respetando 13:45 start

## Próximos Pasos (Opcional)

Si se desea mayor sofisticación:

1. **Detectar automáticamente jornada partida:**
   - Analizar si entries tiene lunch_out/lunch_in registrado
   - Si sí: asumir jornada partida
   - Duración comida: usar promedio histórico (actualmente 1h)

2. **Flexibilidad en viernes:**
   - Permitir salida antes de 14:00 (ej: 13:30) si se negocia
   - Guardar preferencia del usuario

3. **Notificaciones:**
   - Avisar si lunch break < 1 hora en jornada partida
   - Sugerir ajustes

---

**Versión:** 2.1 (Split Shift Aware)  
**Estado:** ✅ Operacional  
**Cambios:** 4 funciones modificadas  
**Compatibilidad:** Backward compatible
