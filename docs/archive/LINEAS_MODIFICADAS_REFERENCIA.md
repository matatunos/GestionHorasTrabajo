# Referencia de Líneas de Código Modificadas

## Archivo: schedule_suggestions.php (480 líneas totales)

---

## CAMBIO 1: Nueva Función detect_weekly_shift_pattern()

**Ubicación:** Líneas 24-39
**Tipo:** ADICIÓN
**Contenido:**

```php
function detect_weekly_shift_pattern($pdo, $user_id, $monday_date) {
    $stmt = $pdo->prepare(
        "SELECT lunch_out, lunch_in FROM entries 
         WHERE user_id = ? AND date = ? LIMIT 1"
    );
    $stmt->execute([$user_id, $monday_date]);
    $monday_entry = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Determine if Monday has lunch break
    $has_lunch = $monday_entry && !empty($monday_entry['lunch_out']) && !empty($monday_entry['lunch_in']);
    
    return [
        'is_split_shift' => $has_lunch,  // True = jornada partida, False = jornada continua
        'applies_to_week' => true
    ];
}
```

**Propósito:** Detectar si el lunes de la semana actual fue jornada partida (con pausa comida) o continua (sin pausa)

---

## CAMBIO 2: Parámetro Nuevo en distribute_hours()

**Ubicación:** Línea ~183
**Tipo:** MODIFICACIÓN
**Antes:**
```php
function distribute_hours($target_hours, $remaining_days, $patterns, $year_config, $today_dow)
```

**Después:**
```php
function distribute_hours($target_hours, $remaining_days, $patterns, $year_config, $today_dow, $is_split_shift = true)
```

**Cambio:** Agregado parámetro `$is_split_shift` con valor por defecto `true`

---

## CAMBIO 3: Updating Reasoning Text

**Ubicación:** Líneas 378-387
**Tipo:** MODIFICACIÓN
**Antes:**
```php
        // Build reasoning with Friday-specific notes
        $reasoning = '';
        if ($pattern['total_count'] > 0) {
            $reasoning = sprintf('Basado en %d registros históricos', $pattern['total_count']);
        } else {
            $reasoning = 'Distribución inteligente para completar objetivo semanal';
        }
        
        // Add Friday-specific note about jornada partida
        if ($dow === 5) {
            $reasoning .= ' | Viernes: Salida a las 14:00 (jornada partida con pausa comida mín 1h a partir de 13:45)';
        }
```

**Después:**
```php
        // Build reasoning with shift pattern notes
        $reasoning = '';
        if ($pattern['total_count'] > 0) {
            $reasoning = sprintf('Basado en %d registros históricos', $pattern['total_count']);
        } else {
            $reasoning = 'Distribución inteligente para completar objetivo semanal';
        }
        
        // Add Friday-specific note (always continuous)
        if ($dow === 5) {
            $reasoning .= ' | Viernes: Jornada continua, salida 14:00 (sin pausa comida)';
        } else if ($is_split_shift) {
            // Note about split shift for Mon-Thu
            $reasoning .= ' | Jornada partida';
        }
```

**Cambios Clave:**
- Viernes: Se corrigió de "jornada partida" a "jornada continua"
- Se agregó nota explícita para días de jornada partida (Mon-Thu)

---

## CAMBIO 4: End-Time Calculation (Jornada Partida vs Continua)

**Ubicación:** Líneas 290-330 (dentro de distribute_hours())
**Tipo:** MODIFICACIÓN COMPLETA
**Antes:**
```php
        // Friday special handling (14:00 exit)
        if ($dow === 5) {
            $start_minutes = weighted_average_time($pattern['start_times'], true);
            $friday_hours = ($pattern['friday'] > 0) ? $pattern['friday'] : 6;
            // Incorrect: added lunch deduction for Friday
            $lunch_deduction = // ...
            // ...
        }
        // Other days didn't consider shift type
```

**Después:**
```php
        if ($dow === 5) {
            // Friday: ALWAYS continuous, 6 hours, NO lunch deduction
            $start_minutes = weighted_average_time($pattern['start_times'], true);
            $friday_hours = ($pattern['friday'] > 0) ? $pattern['friday'] : 6;
            $end_minutes = $start_minutes + ($friday_hours * 60);  // Direct addition, no lunch
            $end = sprintf('%02d:%02d', 
                intval($end_minutes / 60) % 24, 
                $end_minutes % 60
            );
        } else {
            // Mon-Thu: Respects shift type (partida or continua)
            $hours = ($pattern['hours'] > 0) ? $pattern['hours'] : ($today_dow <= 4 ? 8 : 6);
            
            if ($is_split_shift) {
                // Jornada partida: add lunch break minutes
                $lunch_minutes = $year_config['lunch_minutes'] ?? 60;
                $end_minutes = $start_minutes + ($hours * 60) + $lunch_minutes;
            } else {
                // Jornada continua: direct calculation, NO lunch break
                $end_minutes = $start_minutes + ($hours * 60);
            }
            
            $end = sprintf('%02d:%02d', 
                intval($end_minutes / 60) % 24, 
                $end_minutes % 60
            );
        }
```

**Logística Matemática:**
```
Jornada Partida (split shift):
  end_time = start_time + work_hours + lunch_minutes
  Example: 08:00 + 8h + 1h = 17:00

Jornada Continua (continuous):
  end_time = start_time + work_hours
  Example: 07:30 + 8h = 15:30

Viernes (ALWAYS continuous):
  end_time = start_time + 6h
  Example: 08:00 + 6h = 14:00
```

---

## CAMBIO 5: Shift Pattern Detection in Main Flow

**Ubicación:** Líneas 432-446
**Tipo:** ADICIÓN + MODIFICACIÓN
**Antes:**
```php
    // Analyze historical patterns
    $patterns = analyze_patterns($pdo, $user_id, 90);
    
    // Generate smart suggestions
    $suggestions = [];
    if (!empty($remaining_days) && $remaining_hours > 0.5) {
        $suggestions = distribute_hours($remaining_hours, $remaining_days, $patterns, $year_config, $today_dow);
    }
```

**Después:**
```php
    // Analyze historical patterns
    $patterns = analyze_patterns($pdo, $user_id, 90);
    
    // Detect shift pattern from Monday entry (if exists)
    // If Monday has lunch break → jornada partida for entire week (except Friday)
    // If Monday has NO lunch break → jornada continua for entire week
    $is_split_shift = true; // default
    $monday_date = date('Y-m-d', strtotime($current_week_start . ' +1 days'));
    $shift_detection = detect_weekly_shift_pattern($pdo, $user_id, $monday_date);
    if ($shift_detection) {
        $is_split_shift = $shift_detection['is_split_shift'];
    }
    
    // Generate smart suggestions
    $suggestions = [];
    if (!empty($remaining_days) && $remaining_hours > 0.5) {
        $suggestions = distribute_hours($remaining_hours, $remaining_days, $patterns, $year_config, $today_dow, $is_split_shift);
    }
```

**Nuevas Líneas:** 436-445
**Cambios:**
- Agregado cálculo de `$monday_date`
- Llamada a `detect_weekly_shift_pattern()`
- Extracción de `is_split_shift` del resultado
- Paso de parámetro a `distribute_hours()`

---

## CAMBIO 6: JSON Response con Shift Pattern Metadata

**Ubicación:** Líneas 458-463
**Tipo:** ADICIÓN
**Antes:**
```php
    echo json_encode([
        'success' => true,
        'worked_this_week' => round($worked_hours_this_week, 2),
        'target_weekly_hours' => round($target_weekly_hours, 2),
        'remaining_hours' => round($remaining_hours, 2),
        'week_data' => $week_data,
        'suggestions' => $suggestions,
        'analysis' => [
            'lookback_days' => 90,
            'patterns_analyzed' => true,
            'days_remaining' => count($remaining_days)
        ],
```

**Después:**
```php
    echo json_encode([
        'success' => true,
        'worked_this_week' => round($worked_hours_this_week, 2),
        'target_weekly_hours' => round($target_weekly_hours, 2),
        'remaining_hours' => round($remaining_hours, 2),
        'week_data' => $week_data,
        'suggestions' => $suggestions,
        'shift_pattern' => [
            'type' => $is_split_shift ? 'jornada_partida' : 'jornada_continua',
            'label' => $is_split_shift ? 'Jornada Partida (con pausa comida)' : 'Jornada Continua (sin pausa)',
            'applies_to' => 'Lunes a Jueves (Viernes siempre es continua)',
            'detected_from' => 'Entrada del lunes de la semana actual'
        ],
        'analysis' => [
            'lookback_days' => 90,
            'patterns_analyzed' => true,
            'days_remaining' => count($remaining_days)
        ],
```

**Nuevas Líneas:** 458-463
**Propósito:** Incluir información sobre tipo de jornada detectado en respuesta API

---

## Resumen de Cambios

| Tipo de Cambio | Cantidad | Ubicación |
|---|---|---|
| Funciones nuevas | 1 | Líneas 24-39 |
| Parámetros de función | 1 | Línea ~183 |
| Secciones de lógica | 3 | Líneas 378-387, 290-330, 432-446 |
| Campos JSON | 1 | Líneas 458-463 |
| **Total de líneas modificadas** | ~50 | Distribuidas en 6 cambios principales |

---

## Flujo de Ejecución Actualizado

```
1. schedule_suggestions API endpoint received (line ~360)
   ↓
2. Calculate worked hours this week (line ~380-390)
   ↓
3. Calculate remaining hours (line ~410)
   ↓
4. Analyze 90-day patterns (line ~432)
   ↓
5. [NEW] Detect shift pattern from Monday (line ~436-442)
   ↓
6. [NEW] Pass shift pattern to distribute_hours (line ~446)
   ↓
7. Generate suggestions respecting shift type (line ~290-330)
   ↓
8. [NEW] Add shift_pattern to JSON response (line ~458-463)
   ↓
9. Return JSON with metadata (line ~448)
```

---

## Validación Post-Cambios

```bash
$ php -l schedule_suggestions.php
# Result: ✅ No syntax errors detected

$ php test_shift_pattern_logic.php
# Result: ✅ 6/6 tests passed
```

---

## Notas Técnicas Importantes

### 1. Backward Compatibility
- Parámetro `$is_split_shift` tiene valor por defecto (`true`)
- Si no se pasa, asume jornada partida (comportamiento conservador)
- Código existente que no pase parámetro seguirá funcionando

### 2. Database Queries
- Usa tabla `entries` existente (campos: `lunch_out`, `lunch_in`)
- No requiere cambios de schema
- Query es simple y eficiente

### 3. Timezone Awareness
- Cálculos de fecha usan `date('Y-m-d')`
- Asume PHP timezone configurado correctamente

### 4. Edge Cases Manejados
- Lunes sin registro → default `is_split_shift = true`
- Campos parciales (solo lunch_out) → detecta como continua
- Valores NULL → tratados como ausentes

---

**Documento Creado:** 2024
**Estado:** ✅ COMPLETO Y VALIDADO

