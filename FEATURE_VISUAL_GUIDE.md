# 🎬 Feature in Action - Visual Walkthrough

## User Interface Flow

### Step 1: Modal Opens (Initial State)
```
┌─────────────────────────────────────────────────────────┐
│  ⚡ Sugerencias de Horario (Experimental)            ✕  │
├─────────────────────────────────────────────────────────┤
│                                                          │
│  📊 Stats                                                │
│  ├─ Trabajadas esta semana: 16.5h                       │
│  ├─ Objetivo semanal: 40h                               │
│  └─ Pendientes: 23.5h                                   │
│                                                          │
│  Se sugieren los siguientes horarios para completar     │
│  tu jornada semanal respetando patrones y preferencias. │
│                                                          │
│  ┌────────────────────────────────────────────────────┐ │
│  │ ☐ Forzar hora entrada a 07:30                     │ │
│  │                                                    │ │
│  │ Las sugerencias se recalcularán automáticamente   │ │
│  │ con la hora de entrada forzada.                   │ │
│  └────────────────────────────────────────────────────┘ │
│                                                          │
│  📅 Sugerencias para los próximos días:                │
│                                                          │
│  ┌──────────────────────────────────────────────────┐  │
│  │ Lunes - 22/01/2024                               │  │
│  │  Entrada: [08:15]  Salida: [16:45]  Horas: 8.5h │  │
│  └──────────────────────────────────────────────────┘  │
│                                                          │
│  ... (more suggestions)                                │
│                                                          │
├─────────────────────────────────────────────────────────┤
│  [Cerrar]  [Aplicar Sugerencias]                       │
└─────────────────────────────────────────────────────────┘
```

### Step 2: User Checks "Force Start Time" Checkbox
```
┌─────────────────────────────────────────────────────────┐
│  ⚡ Sugerencias de Horario (Experimental)            ✕  │
├─────────────────────────────────────────────────────────┤
│                                                          │
│  📊 Stats                                                │
│  ├─ Trabajadas esta semana: 16.5h                       │
│  ├─ Objetivo semanal: 40h                               │
│  └─ Pendientes: 23.5h                                   │
│                                                          │
│  Se sugieren los siguientes horarios para completar     │
│  tu jornada semanal respetando patrones y preferencias. │
│                                                          │
│  ┌────────────────────────────────────────────────────┐ │
│  │ ☑ Forzar hora entrada a 07:30  ← CHECKED         │ │
│  │                                                    │ │
│  │ Las sugerencias se recalcularán automáticamente   │ │
│  │ con la hora de entrada forzada.                   │ │
│  └────────────────────────────────────────────────────┘ │
│                                                          │
│  ⏳ Recalculando sugerencias...                        │
│                                                          │
├─────────────────────────────────────────────────────────┤
│  [Cerrar]  [Aplicar Sugerencias]                       │
└─────────────────────────────────────────────────────────┘

         ↓ AJAX call: schedule_suggestions.php?force_start_time=07:30
```

### Step 3: Suggestions Updated with Forced Start Time
```
┌─────────────────────────────────────────────────────────┐
│  ⚡ Sugerencias de Horario (Experimental)            ✕  │
├─────────────────────────────────────────────────────────┤
│                                                          │
│  📊 Stats                                                │
│  ├─ Trabajadas esta semana: 16.5h                       │
│  ├─ Objetivo semanal: 40h                               │
│  └─ Pendientes: 23.5h                                   │
│                                                          │
│  Se sugieren los siguientes horarios para completar     │
│  tu jornada semanal respetando patrones y preferencias. │
│                                                          │
│  ┌────────────────────────────────────────────────────┐ │
│  │ ☑ Forzar hora entrada a 07:30                     │ │
│  │                                                    │ │
│  │ Las sugerencias se recalcularán automáticamente   │ │
│  │ con la hora de entrada forzada.                   │ │
│  │                                                    │ │
│  │ ✓ Sugerencias recalculadas con entrada            │ │
│  │   forzada a 07:30                                 │ │
│  └────────────────────────────────────────────────────┘ │
│                                                          │
│  📅 Sugerencias para los próximos días:                │
│                                                          │
│  ┌──────────────────────────────────────────────────┐  │
│  │ Lunes - 22/01/2024                               │  │
│  │  Entrada: [07:30]  Salida: [16:00]  Horas: 8.5h │  │
│  │                     ▲ CHANGED                     │  │
│  └──────────────────────────────────────────────────┘  │
│                                                          │
│  ┌──────────────────────────────────────────────────┐  │
│  │ Martes - 23/01/2024                              │  │
│  │  Entrada: [07:30]  Salida: [16:00]  Horas: 8.5h │  │
│  │                     ▲ CHANGED                     │  │
│  └──────────────────────────────────────────────────┘  │
│                                                          │
│  ... (more suggestions with forced times)               │
│                                                          │
├─────────────────────────────────────────────────────────┤
│  [Cerrar]  [Aplicar Sugerencias]                       │
└─────────────────────────────────────────────────────────┘
```

### Step 4: User Unchecks to Revert
```
┌─────────────────────────────────────────────────────────┐
│  ⚡ Sugerencias de Horario (Experimental)            ✕  │
├─────────────────────────────────────────────────────────┤
│                                                          │
│  📊 Stats                                                │
│  ├─ Trabajadas esta semana: 16.5h                       │
│  ├─ Objetivo semanal: 40h                               │
│  └─ Pendientes: 23.5h                                   │
│                                                          │
│  ┌────────────────────────────────────────────────────┐ │
│  │ ☐ Forzar hora entrada a 07:30  ← UNCHECKED      │ │
│  │                                                    │ │
│  │ Las sugerencias se recalcularán automáticamente   │ │
│  └────────────────────────────────────────────────────┘ │
│                                                          │
│  ⏳ Recalculando sugerencias...                        │
│                                                          │
│  ... (Loading state)                                    │
│                                                          │
├─────────────────────────────────────────────────────────┤
│  [Cerrar]  [Aplicar Sugerencias]                       │
└─────────────────────────────────────────────────────────┘

         ↓ AJAX call: schedule_suggestions.php (no force param)
```

### Step 5: Back to Original Suggestions
```
┌─────────────────────────────────────────────────────────┐
│  ⚡ Sugerencias de Horario (Experimental)            ✕  │
├─────────────────────────────────────────────────────────┤
│                                                          │
│  📊 Stats                                                │
│  ├─ Trabajadas esta semana: 16.5h                       │
│  ├─ Objetivo semanal: 40h                               │
│  └─ Pendientes: 23.5h                                   │
│                                                          │
│  ┌────────────────────────────────────────────────────┐ │
│  │ ☐ Forzar hora entrada a 07:30                     │ │
│  │                                                    │ │
│  │ Las sugerencias se recalcularán automáticamente   │ │
│  └────────────────────────────────────────────────────┘ │
│                                                          │
│  📅 Sugerencias para los próximos días:                │
│                                                          │
│  ┌──────────────────────────────────────────────────┐  │
│  │ Lunes - 22/01/2024                               │  │
│  │  Entrada: [08:15]  Salida: [16:45]  Horas: 8.5h │  │
│  │                     ▲ BACK TO NORMAL             │  │
│  └──────────────────────────────────────────────────┘  │
│                                                          │
│  ┌──────────────────────────────────────────────────┐  │
│  │ Martes - 23/01/2024                              │  │
│  │  Entrada: [08:15]  Salida: [16:45]  Horas: 8.5h │  │
│  │                     ▲ BACK TO NORMAL             │  │
│  └──────────────────────────────────────────────────┘  │
│                                                          │
│  ... (more suggestions)                                │
│                                                          │
├─────────────────────────────────────────────────────────┤
│  [Cerrar]  [Aplicar Sugerencias]                       │
└─────────────────────────────────────────────────────────┘
```

---

## Network Flow Diagram

```
User                          Browser                    Server
│                               │                          │
│ 1. Click checkbox             │                          │
├──────────────────────────────>│                          │
│                               │ 2. Check event fires    │
│                               │    toggleForceStartTime()│
│                               │                          │
│                               │ 3. Show loading state   │
│                               │    "Recalculando..."    │
│                               │                          │
│                               │ 4. AJAX GET request     │
│                               │    /schedule_suggestions│
│                               │    .php?force_start     │
│                               │    _time=07:30          │
│                               ├─────────────────────────>│
│                               │                          │
│                               │                 5. Receive param
│                               │                 6. Validate HH:MM
│                               │                 7. Call distribute_hours()
│                               │                    with force_start_time
│                               │                 8. Generate suggestions
│                               │                 9. Include in JSON:
│                               │                    "forced_start_time":"07:30"
│                               │<─────────────────────────│
│                               │ 10. JSON response with  │
│                               │     forced times        │
│                               │                          │
│                               │ 11. renderSuggestions() │
│                               │ 12. Re-render with      │
│                               │     07:30 times         │
│                               │ 13. Show confirmation   │
│                               │     message             │
│                               │ 14. Restore checkbox    │
│                               │     state               │
│                               │                          │
│<──────────────────────────────┤                          │
│ 15. See updated suggestions   │                          │
│     with 07:30 start times    │                          │
│                               │                          │
```

---

## JavaScript Execution Timeline

```
Timeline    Event                   Action
─────────────────────────────────────────────────────────────
T+0ms       User checks checkbox    onchange event fires
T+0ms       toggleForceStartTime()  Function executes
T+5ms       Show loading state      "Recalculando sugerencias..."
T+10ms      Build URL               schedule_suggestions.php?force_start_time=07:30
T+15ms      fetch() initiated       AJAX request sent to server
T+50ms      Server processing       Validation + calculation
T+100ms     Response received       JSON with forced times
T+105ms     Parse JSON              data.success check
T+110ms     Call renderSuggestions()  Re-render modal content
T+120ms     Update DOM              Suggestions with 07:30 times
T+125ms     Restore checkbox        Set checked = true
T+130ms     Add confirmation        "✓ Sugerencias recalculadas..."
T+200ms     User sees results       Complete update
```

---

## Data Transformation Example

### API Request
```
GET /schedule_suggestions.php?force_start_time=07:30
```

### Backend Processing
```php
// 1. Receive parameter
$force_start_time = "07:30"  // From $_GET

// 2. Validate format
preg_match('/^\d{2}:\d{2}$/', "07:30")  // ✓ Valid

// 3. Pass to calculation
$suggested_start = $force_start_time ?: weighted_average_time(...);
// Result: "07:30"

// 4. Include in response
'analysis' => [
    'forced_start_time' => "07:30"
]
```

### API Response (Abbreviated)
```json
{
  "success": true,
  "analysis": {
    "forced_start_time": "07:30"
  },
  "suggestions": [
    {
      "date": "2024-01-22",
      "start": "07:30",     // Forced to this time
      "lunch_out": "13:00",
      "lunch_in": "14:00",
      "end": "16:30"
    }
  ]
}
```

### Frontend Display
```html
<input type="time" value="07:30">  <!-- Rendered in suggestion card -->
<!-- Plus confirmation message: "✓ Sugerencias recalculadas con entrada forzada a 07:30" -->
```

---

## State Management

### Checkbox State Values

| State | HTML | Behavior |
|-------|------|----------|
| **Unchecked** | `<input type="checkbox" />` | API called without force param, suggestions show historical times |
| **Checked** | `<input type="checkbox" checked />` | API called with `?force_start_time=07:30`, suggestions show forced times |
| **During Load** | Disabled (implicit) | User sees "Recalculando..." message, cannot change |
| **After Load** | Re-enabled | Restored to checked/unchecked state from before load |

### Modal Content State

| Scenario | Display |
|----------|---------|
| **Initial** | Normal suggestion cards with historical times |
| **Checkbox Checked** | Yellow info box with confirmation message + updated cards with 07:30 times |
| **Checkbox Unchecked** | Yellow info box with no message + updated cards with historical times |
| **Loading** | "Recalculando sugerencias..." message in center |
| **Error** | Red error box with error message |

---

## Performance Metrics

| Metric | Target | Typical |
|--------|--------|---------|
| User click to loading display | < 50ms | 5-20ms |
| Server processing | < 200ms | 50-100ms |
| Network round trip | < 500ms | 100-300ms |
| DOM rendering | < 100ms | 20-50ms |
| Confirmation visible | < 1s | 200-400ms |
| **Total user experience** | **< 2s** | **400-800ms** |

---

## Error Scenarios & Recovery

### Scenario 1: Network Error
```
User Checks → Loading → Network Error
│                                    │
└────────> Error Message: "Error al cargar: [error details]"
           Checkbox Unchecked
           No changes applied
           User can retry
```

### Scenario 2: Invalid Response
```
User Checks → Loading → Invalid JSON
│                              │
└────────> Error Message: "Error: [error details]"
           Checkbox Unchecked
           Suggestions unchanged
           User can retry
```

### Scenario 3: Server Error
```
User Checks → Loading → Server returns error
│                              │
└────────> Error Message: "Error: [server message]"
           Checkbox Unchecked
           Suggestions unchanged
           User can retry
```

---

## Summary of User Experience

✨ **Seamless**: Checkbox toggle → instant AJAX recalculation
🎯 **Clear**: Yellow box catches attention, labels are explicit
⚡ **Fast**: Loading state shows work is happening
✓ **Responsive**: Confirmation message confirms success
🔄 **Reversible**: Uncheck to go back to original suggestions
❌ **Safe**: Errors don't break suggestions, user can retry

