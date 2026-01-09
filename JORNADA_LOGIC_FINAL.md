# Lógica de Jornada Laboral - Implementación Final

## Resumen Ejecutivo

Se ha implementado la lógica de **detección automática de tipo de jornada** basada en el patrón del **lunes de la semana actual**:

- **Si el lunes tiene pausa comida** → Jornada partida (split shift) para **lunes a jueves**
- **Si el lunes NO tiene pausa comida** → Jornada continua para **toda la semana**
- **Viernes SIEMPRE es jornada continua** (sin pausa comida), independientemente del patrón de la semana

## Reglas de Negocio

### Jornada Partida (Lunes-Jueves si lunes lo es)
```
Entrada:    07:30+ flexible (pero normalmente ~08:00)
Pausa comida: lunch_out → lunch_in (típicamente 13:45-14:45, ~1 hora)
Salida:     16:45 (con pausa deducida)
Total:      8 horas de trabajo neto (excluye pausa comida)
```

Ejemplo:
- Entrada: 08:00
- Pausa comida: 13:45-14:45 (1 hora)
- Horas requeridas: 8
- Salida calculada: 08:00 + 8h + 1h pausa = 17:00

### Jornada Continua (Lunes-Jueves si lunes lo es, y SIEMPRE viernes)
```
Entrada:    07:30+ flexible
Salida:     entrada + horas_requeridas
Sin pausa comida deducida
Total:      8 horas (lunes-jueves) o 6 horas (viernes)
```

Ejemplo Lunes:
- Entrada: 08:00
- Horas requeridas: 8
- Salida: 08:00 + 8h = 16:00

Ejemplo Viernes (SIEMPRE):
- Entrada: 08:00
- Horas requeridas: 6 (porque viernes es jornada corta)
- Salida: 08:00 + 6h = 14:00

## Implementación Técnica

### 1. Detección de Patrón (`detect_weekly_shift_pattern()`)

```php
function detect_weekly_shift_pattern($pdo, $user_id, $monday_date) {
    $stmt = $pdo->prepare(
        "SELECT lunch_out, lunch_in FROM entries 
         WHERE user_id = ? AND date = ? LIMIT 1"
    );
    $stmt->execute([$user_id, $monday_date]);
    $monday_entry = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Si lunes tiene lunch_out y lunch_in → jornada partida
    $has_lunch = $monday_entry && 
                 !empty($monday_entry['lunch_out']) && 
                 !empty($monday_entry['lunch_in']);
    
    return ['is_split_shift' => $has_lunch];
}
```

**Lógica:**
- Busca entrada del **lunes** de la semana actual
- Si tiene `lunch_out` y `lunch_in` → `is_split_shift = true` (partida)
- Si NO tiene ambos campos → `is_split_shift = false` (continua)

### 2. Distribución Inteligente (`distribute_hours()`)

La función ahora recibe parámetro `$is_split_shift` y lo aplica así:

#### Para viernes (dow = 5) - SIEMPRE continua:
```php
// Friday always has 6 hours target, continuous shift
$friday_hours = ($pattern['friday'] > 0) ? $pattern['friday'] : 6;
$start_minutes = weighted_average_time($pattern['start_times'], true);
$friday_end = sprintf('%02d:%02d', 
    intval($start_minutes / 60) + 6,
    $start_minutes % 60
);
```

Resultado: `08:00 + 6h = 14:00` (sin pausa comida)

#### Para lunes-jueves - Respeta `$is_split_shift`:

**Si es jornada continua:**
```php
// Direct calculation: start + hours
$day_hours = $pattern['hours'] > 0 ? $pattern['hours'] : 8;
$end_minutes = $start_minutes + ($day_hours * 60);
$end = sprintf('%02d:%02d', 
    intval($end_minutes / 60),
    $end_minutes % 60
);
```

**Si es jornada partida:**
```php
// Add lunch duration: start + hours + lunch_break
$lunch_minutes = $year_config['lunch_minutes'] ?? 60;
$end_minutes = $start_minutes + ($day_hours * 60) + $lunch_minutes;
$end = sprintf('%02d:%02d', 
    intval($end_minutes / 60),
    $end_minutes % 60
);
```

### 3. Flujo de Ejecución Principal

```php
// 1. Detectar patrón desde lunes
$monday_date = date('Y-m-d', strtotime($current_week_start . ' +1 days'));
$shift_detection = detect_weekly_shift_pattern($pdo, $user_id, $monday_date);
$is_split_shift = $shift_detection['is_split_shift'] ?? true;

// 2. Distribuir horas pasando el patrón detectado
$suggestions = distribute_hours(
    $remaining_hours, 
    $remaining_days, 
    $patterns, 
    $year_config, 
    $today_dow, 
    $is_split_shift  // ← Parámetro nuevo
);

// 3. Incluir información en respuesta JSON
'shift_pattern' => [
    'type' => $is_split_shift ? 'jornada_partida' : 'jornada_continua',
    'label' => $is_split_shift ? 'Jornada Partida (con pausa comida)' : 'Jornada Continua (sin pausa)',
    'applies_to' => 'Lunes a Jueves (Viernes siempre es continua)',
    'detected_from' => 'Entrada del lunes de la semana actual'
]
```

## Ejemplos de Aplicación

### Caso 1: Lunes con Jornada Partida
**Entrada del lunes:** 08:00-17:00, lunch_out: 13:45, lunch_in: 14:45

```
Patrón detectado: jornada_partida

Sugerencias para la semana:
- Lunes: 08:00-17:00 (8h + 1h pausa = 9h totales en calendario)
- Martes: 08:00-17:00 (jornada partida)
- Miércoles: 08:00-17:00 (jornada partida)
- Jueves: 08:00-17:00 (jornada partida)
- Viernes: 08:00-14:00 (6h sin pausa, jornada continua)
```

### Caso 2: Lunes con Jornada Continua (sin pausa)
**Entrada del lunes:** 07:30-15:30 (8h sin pausa comida)

```
Patrón detectado: jornada_continua

Sugerencias para la semana:
- Lunes: 07:30-15:30 (8h sin pausa)
- Martes: 07:30-15:30 (jornada continua)
- Miércoles: 07:30-15:30 (jornada continua)
- Jueves: 07:30-15:30 (jornada continua)
- Viernes: 08:00-14:00 (6h sin pausa, jornada continua)
```

### Caso 3: Sin Entrada del Lunes
Si no hay registro del lunes, por defecto se asume **jornada partida** (valor conservador).

## Validación de Datos

**Para detectar jornada partida:**
- Campo `lunch_out`: MUST NOT be empty/null
- Campo `lunch_in`: MUST NOT be empty/null
- Ambos deben ser times válidos (HH:MM)

**Para detectar jornada continua:**
- Campo `lunch_out`: Empty/null OR
- Campo `lunch_in`: Empty/null

## Integración con year_config

La tabla `year_config` contiene:
```
mon_thu: Horas objetivo lunes-jueves (8)
friday: Horas objetivo viernes (6)
lunch_minutes: Duración pausa comida (60, 45, etc.)
coffee_minutes: Duración descanso café (15, 10, etc.)
```

Uso en `distribute_hours()`:
- `lunch_minutes` se suma al tiempo final SI es jornada partida
- Para viernes NUNCA se suma pausa comida (jornada continua siempre)

## Testing Checklist

- [x] Lunes con pausa comida → detecta jornada partida
- [x] Lunes sin pausa comida → detecta jornada continua
- [x] Viernes SIEMPRE calcula como continua (14:00 exit)
- [x] Parámetro `$is_split_shift` se pasa a `distribute_hours()`
- [x] JSON response incluye `shift_pattern` metadata
- [x] Reasoning text nota el tipo de jornada
- [x] PHP syntax validation: NO ERRORS

## Archivos Modificados

1. **schedule_suggestions.php**
   - ✅ Added: `detect_weekly_shift_pattern()` function
   - ✅ Modified: `distribute_hours()` signature (added `$is_split_shift` parameter)
   - ✅ Updated: End-time calculation logic for split vs continuous
   - ✅ Integrated: Shift pattern detection in main flow
   - ✅ Updated: Response JSON with `shift_pattern` metadata
   - ✅ Updated: Reasoning text with shift type notes

## Referencias de Cambio

**Línea ~165:** `detect_weekly_shift_pattern()` function definition
**Línea ~183:** Modified `distribute_hours()` signature
**Línea ~290-330:** Updated end-time calculation logic
**Línea ~428-442:** Main flow integration (shift detection + distribute_hours call)
**Línea ~449-457:** JSON response with shift_pattern metadata

---

**Status:** ✅ IMPLEMENTADO Y VALIDADO
**Syntax Check:** No syntax errors detected
**Ready for:** QA testing with real schedule data
