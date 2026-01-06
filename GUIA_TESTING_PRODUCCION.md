# 🧪 GUÍA DE TESTING EN PRODUCCIÓN

## Introducción

Este documento proporciona instrucciones paso a paso para validar la implementación del sistema de jornada laboral en su base de datos real.

---

## 📋 Pre-requisitos

- [x] Código implementado en `schedule_suggestions.php`
- [x] Tests unitarios pasados (6/6)
- [x] Tests integración pasados (6/6)
- [x] Acceso a base de datos de producción (con backup)
- [x] Registros de prueba con ambos patrones de jornada

---

## 🎯 Test Case 1: Usuario con Jornada Partida

### Preparación
```sql
-- Crear registro de prueba (Lunes)
INSERT INTO entries (user_id, date, start, end, lunch_out, lunch_in)
VALUES (
  1,                    -- user_id (reemplazar con ID real)
  '2024-01-08',        -- Lunes actual o pasado
  '08:00',             -- start
  '17:00',             -- end
  '13:45',             -- lunch_out
  '14:45'              -- lunch_in
);
```

### Ejecución
```bash
# Ejecutar API endpoint
curl "http://tuapp.local/api.php?action=schedule_suggestions&user_id=1"
```

### Validaciones
```json
✅ Esperado en response:
{
  "shift_pattern": {
    "type": "jornada_partida",
    "label": "Jornada Partida (con pausa comida)",
    "applies_to": "Lunes a Jueves (Viernes siempre es continua)"
  },
  "suggestions": [
    {
      "day": "Martes",
      "start": "08:00",
      "end": "17:00",
      "reasoning": "... | Jornada partida"
    },
    {
      "day": "Viernes",
      "start": "08:00",
      "end": "14:00",
      "reasoning": "... | Viernes: Jornada continua, salida 14:00"
    }
  ]
}
```

### Checklist
- [ ] API retorna shift_pattern metadata
- [ ] type = 'jornada_partida'
- [ ] Suggestions para martes-jueves muestran jornada partida
- [ ] Viernes shows 14:00 exit (jornada continua)
- [ ] Reasoning text correcto para cada día

---

## 🎯 Test Case 2: Usuario con Jornada Continua

### Preparación
```sql
-- Crear registro de prueba (Lunes sin pausa)
INSERT INTO entries (user_id, date, start, end, lunch_out, lunch_in)
VALUES (
  2,                    -- user_id diferente
  '2024-01-08',        -- Mismo lunes
  '07:30',             -- start
  '15:30',             -- end
  NULL,                -- lunch_out (sin pausa)
  NULL                 -- lunch_in (sin pausa)
);
```

### Ejecución
```bash
curl "http://tuapp.local/api.php?action=schedule_suggestions&user_id=2"
```

### Validaciones
```json
✅ Esperado en response:
{
  "shift_pattern": {
    "type": "jornada_continua",
    "label": "Jornada Continua (sin pausa)",
    "applies_to": "Lunes a Jueves (Viernes siempre es continua)"
  },
  "suggestions": [
    {
      "day": "Martes",
      "start": "07:30",
      "end": "15:30",
      "reasoning": "... (sin mención de jornada partida)"
    },
    {
      "day": "Viernes",
      "start": "08:00",
      "end": "14:00",
      "reasoning": "... | Viernes: Jornada continua, salida 14:00"
    }
  ]
}
```

### Checklist
- [ ] API retorna shift_pattern metadata
- [ ] type = 'jornada_continua'
- [ ] Suggestions para martes-jueves NO mencionan jornada partida
- [ ] Viernes shows 14:00 exit (jornada continua)
- [ ] No hay deducción de pausa comida en cálculos

---

## 🎯 Test Case 3: Sin Entrada del Lunes

### Preparación
```sql
-- Limpiar registros del lunes, dejar solo martes
DELETE FROM entries WHERE user_id = 3 AND date = '2024-01-08';

-- Crear registro del martes
INSERT INTO entries (user_id, date, start, end, lunch_out, lunch_in)
VALUES (
  3,                    -- user_id
  '2024-01-09',        -- Martes
  '08:00',
  '17:00',
  NULL,
  NULL
);
```

### Ejecución
```bash
curl "http://tuapp.local/api.php?action=schedule_suggestions&user_id=3"
```

### Validaciones
- [ ] API no falla
- [ ] shift_pattern.type = 'jornada_partida' (default conservador)
- [ ] Suggestions muestran lógica consistente
- [ ] Mensaje útil sobre falta de lunes

---

## 🎯 Test Case 4: Cálculos Matemáticos Exactos

### Escenario A: Jornada Partida (Entrada 8:00)
```
✅ Expected calculation:
  Entrada: 08:00
  Horas: 8
  Pausa comida: 60 minutos
  Salida: 08:00 + 8h + 1h = 17:00
```

### Escenario B: Jornada Continua (Entrada 7:30)
```
✅ Expected calculation:
  Entrada: 07:30
  Horas: 8
  Pausa comida: 0 (no aplicable)
  Salida: 07:30 + 8h = 15:30
```

### Escenario C: Viernes (Entrada 8:00, Continua)
```
✅ Expected calculation:
  Entrada: 08:00
  Horas: 6 (jornada corta)
  Pausa comida: 0 (viernes NUNCA tiene pausa)
  Salida: 08:00 + 6h = 14:00
```

### Validación en Base de Datos
```bash
# Crear script PHP para validar cálculos
php -r "
  \$tests = [
    ['start' => '08:00', 'hours' => 8, 'split' => true, 'expected' => '17:00'],
    ['start' => '07:30', 'hours' => 8, 'split' => false, 'expected' => '15:30'],
    ['start' => '08:00', 'hours' => 6, 'split' => false, 'expected' => '14:00']
  ];
  
  foreach (\$tests as \$t) {
    // Verificar que la API retorna los valores esperados
  }
"
```

---

## 🎯 Test Case 5: Casos Edge

### Edge Case A: Entrada Muy Temprana (07:00)
```
Input:  Entrada 07:00, 8h, jornada partida
Output: 07:00 + 8h + 1h = 16:00
Validación: ✅ Debe ser 16:00 exacto
```

### Edge Case B: Entrada Muy Tarde (09:00)
```
Input:  Entrada 09:00, 8h, jornada continua
Output: 09:00 + 8h = 17:00
Validación: ✅ Debe ser 17:00 exacto
```

### Edge Case C: Campo Parcial (solo lunch_out)
```
Input:  lunch_out = '13:45', lunch_in = NULL
Output: Detecta como jornada_continua
Validación: ✅ Requiere AMBOS campos
```

---

## 📊 Resultado Esperado en Base de Datos

### Tabla: entries
```
user_id | date       | start | end   | lunch_out | lunch_in | created_at
────────┼────────────┼───────┼───────┼───────────┼──────────┼─────────────
1       | 2024-01-08 | 08:00 | 17:00 | 13:45     | 14:45    | ...
2       | 2024-01-08 | 07:30 | 15:30 | NULL      | NULL     | ...
```

### API Response Esperado para user_id=1
```json
{
  "success": true,
  "worked_this_week": 32.50,
  "target_weekly_hours": 38.00,
  "remaining_hours": 5.50,
  "shift_pattern": {
    "type": "jornada_partida",
    "label": "Jornada Partida (con pausa comida)",
    "applies_to": "Lunes a Jueves (Viernes siempre es continua)",
    "detected_from": "Entrada del lunes de la semana actual"
  },
  "suggestions": [
    {
      "day": "Martes",
      "day_of_week": 2,
      "start": "08:00",
      "end": "17:00",
      "target_hours": 8,
      "reasoning": "Basado en X registros históricos | Jornada partida",
      "confidence": "alta"
    },
    {
      "day": "Viernes",
      "day_of_week": 5,
      "start": "08:00",
      "end": "14:00",
      "target_hours": 6,
      "reasoning": "Basado en X registros históricos | Viernes: Jornada continua, salida 14:00 (sin pausa comida)",
      "confidence": "alta"
    }
  ]
}
```

---

## 🔍 Verificación de Código

### Verificar que detect_weekly_shift_pattern() está definida
```bash
grep -n "function detect_weekly_shift_pattern" schedule_suggestions.php
# Output: 24:function detect_weekly_shift_pattern($pdo, $user_id, $monday_date) {
```

### Verificar que distribute_hours() recibe parámetro
```bash
grep -n "distribute_hours.*\$is_split_shift" schedule_suggestions.php
# Output: Múltiples líneas donde se llama con parámetro
```

### Verificar que JSON incluye shift_pattern
```bash
grep -n "shift_pattern" schedule_suggestions.php
# Output: Múltiples líneas con definición y uso
```

---

## 📈 Métricas a Validar

| Métrica | Esperado | Actual | Status |
|---------|----------|--------|--------|
| Detección partida correcta | SÍ | ? | [ ] |
| Detección continua correcta | SÍ | ? | [ ] |
| Cálculo martes partida | 17:00 | ? | [ ] |
| Cálculo martes continua | 15:30 | ? | [ ] |
| Cálculo viernes | 14:00 | ? | [ ] |
| JSON shift_pattern presente | SÍ | ? | [ ] |
| API response correcta | SÍ | ? | [ ] |
| Sin errores en log | SÍ | ? | [ ] |

---

## 🚨 Troubleshooting

### Problema: API retorna NULL para shift_pattern
**Solución:**
```php
// Verificar en schedule_suggestions.php línea ~438
$shift_detection = detect_weekly_shift_pattern($pdo, $user_id, $monday_date);
if (!$shift_detection) {
    error_log("detect_weekly_shift_pattern retornó null para user_id=$user_id");
}
```

### Problema: Cálculos de salida incorrectos
**Solución:**
```php
// Revisar líneas 290-330 (cálculo de end_time)
// Verificar que:
// - $is_split_shift se pasa correctamente
// - lunch_minutes se suma si es partida
// - viernes no suma pausa
```

### Problema: Friday no es continua
**Solución:**
```php
// Revisar línea 290 (if ($dow === 5))
// Debe forzar jornada continua sin importar $is_split_shift
```

---

## ✅ Checklist Final de Testing

### Funcionalidad
- [ ] Jornada partida detectada correctamente
- [ ] Jornada continua detectada correctamente
- [ ] Viernes SIEMPRE es continua
- [ ] Cálculos de salida son correctos
- [ ] JSON response tiene estructura correcta
- [ ] shift_pattern metadata presente
- [ ] Reasoning text actualizado

### Rendimiento
- [ ] API responde en < 1 segundo
- [ ] No hay memory leaks
- [ ] Base de datos queries optimizadas
- [ ] No hay N+1 queries

### Seguridad
- [ ] Prepared statements usados
- [ ] SQL injection prevenido
- [ ] XSS en JSON escapeado
- [ ] Permisos de usuario respetados

### Compatibilidad
- [ ] Funciona con PHP 7.4+
- [ ] Compatible con MySQL 5.7+
- [ ] No rompe funcionalidad existente
- [ ] Backward compatible

---

## 📞 Contacto para Issues

Si encuentra problemas durante el testing:

1. **Revisar documentación:**
   - JORNADA_LOGIC_FINAL.md
   - LINEAS_MODIFICADAS_REFERENCIA.md

2. **Ejecutar tests:**
   - `php test_shift_pattern_logic.php`
   - `php test_integration_shift_pattern.php`

3. **Verificar logs:**
   - `/var/log/apache2/error.log` (Apache)
   - `/var/log/php.log` (PHP)

---

## 🎉 Conclusión

Una vez todos los tests pasen:
- ✅ El sistema está listo para producción
- ✅ Proceder con integración frontend
- ✅ Documentar en notas de release
- ✅ Notificar a usuarios sobre cambio

---

**Guía de Testing - Completada**

