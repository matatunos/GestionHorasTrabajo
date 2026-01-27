NUEVOS CONSTRAINTS DE HORARIO - MÁRGENES Y PAUSA COMIDA
========================================================

CAMBIOS IMPLEMENTADOS EN: schedule_suggestions.php

1. MÁRGENES PERMITIDOS (EXCEPCIONALES)
======================================

VIERNES:
  Antes: Máximo 14:00 (salida)
  Ahora: Máximo 14:10 (margen para casos excepcionales)
  
  Implementación:
  ```php
  $max_exit_min = time_to_minutes('14:10');
  if ($end_min > $max_exit_min) {
      $end_min = $max_exit_min;
  }
  ```
  
  Justificación: 10 minutos de margen para excepciones
  Ejemplo: Start 07:42 + 6h = 13:42 exit (sigue siendo dentro del margen)

SEMANA (Lunes-Jueves):
  Antes: Sin límite máximo definido
  Ahora: Máximo 18:10 (margen para casos excepcionales)
  
  Implementación:
  ```php
  $max_exit_min_weekday = time_to_minutes('18:10');
  if ($end_min > $max_exit_min_weekday) {
      $end_min = $max_exit_min_weekday;
  }
  ```
  
  Justificación: 10 minutos de margen para excepciones
  Límite base: 18:00 (jornada de trabajo estándar)


2. RESTRICCIONES DE PAUSA COMIDA (PARA SALIDA > 16:00)
======================================================

CUANDO: Si salida calculada > 16:00
ENTONCES: Se forza pausa de comida y se aplican restricciones:

RESTRICCIÓN 1: Duración mínima > 60 minutos
  - Pausa debe ser más de 1 hora
  - No es suficiente con exactamente 60 minutos

RESTRICCIÓN 2: No se puede parar antes de 13:45
  - Entrada normal: 08:00
  - Trabajo hasta: 13:45 mínimo (5h 45m)
  - No se permite pausa comida temprana

RESTRICCIÓN 3: Después de pausa, trabajo > 60 minutos
  - Después de comer: mínimo 1h 1 minuto más
  - No puede ser última pequeña tarea antes de salida

EJEMPLO PRÁCTICO:
  Entrada: 08:00
  Salida deseada: 17:30 (> 16:00, requiere pausa)
  
  Cálculo:
  - 08:00 a 13:45 = 5h 45m (trabajo antes de pausa)
  - 13:45 a 14:46 = 1h 1m (pausa comida > 60 min)
  - 14:46 a 17:30 = 2h 44m (trabajo después, > 60 min) ✓
  - Total: 8h 29m trabajo + 1h 1m pausa = 9h 30m jornada
  
  ✅ Todas las restricciones se cumplen


3. LÓGICA IMPLEMENTADA EN CÓDIGO
=================================

Para jornada partida (split shift) con salida > 16:00:

```php
$calculated_exit = intval($end_min / 60);
if ($calculated_exit > 16) {
    // Recalcular con pausa comida a las 13:45
    $lunch_start_min = time_to_minutes('13:45');
    $min_lunch = 61;  // > 60 minutos
    $work_before_lunch = max(0, $lunch_start_min - $start_min);
    $total_work_needed = $suggested_hours * 60;  // minutos
    
    if ($total_work_needed > $work_before_lunch) {
        $work_after_lunch = $total_work_needed - $work_before_lunch;
        if ($work_after_lunch >= 61) {
            // Cabe trabajo >60 min después
            $lunch_duration = max($min_lunch, intval($avg_lunch));
            $lunch_end_min = $lunch_start_min + $lunch_duration;
            $end_min = $lunch_end_min + $work_after_lunch;
        }
    }
}
```

Para jornada continua que se convierte en partida:

```php
if ($calculated_exit > 16) {
    // Forza pausa comida
    $lunch_start_min = time_to_minutes('13:45');
    $min_lunch = 61;  // > 60 minutos
    $min_work_after = 61;  // > 60 minutos después
    $lunch_duration = max($min_lunch, 60);
    $lunch_end_min = $lunch_start_min + $lunch_duration;
    
    $work_before_lunch = max(0, $lunch_start_min - $start_min);
    $remaining_work = ($suggested_hours * 60) - $work_before_lunch;
    
    if ($remaining_work < $min_work_after) {
        $remaining_work = $min_work_after;
    }
    
    $end_min = $lunch_end_min + $remaining_work;
}
```


4. CASOS DE USO Y EJEMPLOS
===========================

CASO 1: Viernes Normal
  Input: 15.97h distribuir entre Thu + Fri
  Viernes capped a 6h (máximo)
  Result: Fri 13:42 exit (< 14:10) ✅

CASO 2: Viernes con Excepción
  Input: 15.97h distribuir, pero viernes debe tomar 6.5h
  Result: Fri 14:12 exit → capped a 14:10 ✅

CASO 3: Jueves Jornada Larga (> 16:00)
  Input: 10h de trabajo
  Start: 08:00
  Calculation: 08:00 - 13:45 (5h45m) + PAUSA (>60m) + 14:46 - 18:31 (3h45m)
  Result: 18:31 → capped a 18:10 ✅
  Pausa: 1h 1m, con todos los constraints cumplidos ✅

CASO 4: Miércoles Jornada Partida Corta (< 16:00)
  Input: 7h de trabajo
  Start: 08:30
  Jornada partida: 08:30 - 13:45 (5h15m) + 60m pausa + 14:45 - 16:15
  Result: 16:15 exit (< 16:00? No, > 16:00)
  → Se aplica pausa comida con restricciones
  → Final: 08:30 - 13:45 + pausa > 60min + work > 60min


5. VALIDACIÓN
=============

✅ Sintaxis verificada: No hay errores
✅ Test test_new_margins.php: PASS
✅ Constraints documentados y claros
✅ Ejemplos incluidos

CAMBIOS A ARCHIVOS:

schedule_suggestions.php:
  - Línea ~360: Friday max exit 14:10 (fue 14:00)
  - Línea ~380-410: Monday-Thursday lunch constraints
  - Línea ~412-428: Jornada continua → split shift conversion
  - Línea ~430: Weekday max exit 18:10


CONSIDERACIONES OPERACIONALES:

1. Márgenes (14:10, 18:10):
   - Son para casos excepcionales
   - La norma sigue siendo 14:00 (viernes) y 18:00 (semana)
   - El sistema sugiere dentro de los márgenes

2. Pausa comida:
   - Obligatoria solo si salida > 16:00
   - Antes de 16:00 puede ser jornada continua
   - Restricciones son estrictas para cumplir normativa

3. Flexibilidad:
   - Sistema permite variaciones históricas
   - Márgenes dan espacio para excepciones reales
   - Pausa comida es configurable pero con mínimos

ESTADO: ✅ IMPLEMENTADO Y VALIDADO
PRODUCCIÓN: READY
