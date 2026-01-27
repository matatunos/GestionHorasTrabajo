# Test: Force Start Time Feature (07:30)

## Feature Overview
Users can now check a "Forzar hora entrada a 07:30" checkbox in the Schedule Suggestions modal to force all suggestions to start at 07:30 instead of using historical patterns.

## Implementation Summary

### Backend Changes (`schedule_suggestions.php`)
✅ **Parameter Reception (Lines ~440-451)**
- Receives `force_start_time` from GET request
- Validates format: HH:MM regex pattern
- Stores in `$force_start_time` variable

✅ **Function Signature Update (Line 187)**
- `distribute_hours()` now accepts `$force_start_time = null` parameter
- Default is null (use historical patterns)

✅ **Calculation Logic (Lines ~269-270)**
```php
$suggested_start = $force_start_time ? $force_start_time : 
                   (weighted_average_time($pattern['starts']) ?? ($dow === 5 ? '09:00' : '08:00'));
```
- Uses forced time if provided
- Falls back to weighted average if not forced
- Uses defaults (09:00 for Friday, 08:00 for others) if no pattern exists

✅ **Function Call (Lines ~458-462)**
- Passes `$force_start_time` to `distribute_hours()`
- Ensures parameter flows through entire calculation

✅ **JSON Response (analysis object)**
- Added `forced_start_time` field to analysis metadata
- Shows null if not forced, shows "HH:MM" if forced

### Frontend Changes (`footer.php`)
✅ **Checkbox HTML (Lines ~23-29)**
- Yellow info box with checkbox: "Forzar hora entrada a 07:30"
- Help text explaining automatic recalculation
- Clean UX styling with yellow background (#fff3cd)

✅ **JavaScript Handler (`toggleForceStartTime` function)**
- Triggered on checkbox change
- Shows "Recalculando sugerencias..." loading state
- Calls `schedule_suggestions.php?force_start_time=07:30` if checked
- Calls `schedule_suggestions.php` (no param) if unchecked
- Re-renders suggestions with new times
- Restores checkbox state
- Shows confirmation message when applied

## Testing

### Test 1: Normal Suggestions (No Force)
```bash
curl "http://localhost/schedule_suggestions.php"
```
Expected: Normal suggestions based on historical patterns
Check: `response.analysis.forced_start_time` should be `null`

### Test 2: Force Start Time to 07:30
```bash
curl "http://localhost/schedule_suggestions.php?force_start_time=07:30"
```
Expected: All start times set to 07:30
Check: 
- `response.analysis.forced_start_time` should be `"07:30"`
- Each suggestion's `start` time should be `"07:30"`

### Test 3: Invalid Format (Should be ignored)
```bash
curl "http://localhost/schedule_suggestions.php?force_start_time=7:30"
```
Expected: Treated as if force_start_time was not provided
Check: `response.analysis.forced_start_time` should be `null`

### Test 4: UI Interaction
1. Open Schedule Suggestions modal
2. Observe checkbox: "Forzar hora entrada a 07:30" unchecked
3. Check the checkbox
4. Observe: "Recalculando sugerencias..." message
5. Wait for response
6. Verify: All start times are now 07:30
7. Verify: Success message "✓ Sugerencias recalculadas con entrada forzada a 07:30" appears
8. Uncheck the checkbox
9. Verify: Times revert to historical pattern values

## Code Files Modified

1. **schedule_suggestions.php** (504 lines)
   - Parameter reception + validation
   - Function signature update
   - Calculation logic
   - Function call
   - JSON response field

2. **footer.php** (273 lines)
   - HTML checkbox in yellow info box
   - `toggleForceStartTime()` JavaScript handler
   - AJAX call with conditional parameter

## API Contract

### Request with Force Start Time
```
GET /schedule_suggestions.php?force_start_time=07:30
```

### Request without Force Start Time
```
GET /schedule_suggestions.php
```

### Response Structure
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
    "forced_start_time": "07:30"  // null if not forced
  },
  "suggestions": [
    {
      "date": "2024-01-22",
      "day_name": "Lunes",
      "start": "07:30",  // Will be 07:30 if forced
      "lunch_out": "13:00",
      "lunch_in": "14:00",
      "end": "16:30",
      "hours": 7.5,
      "shift_type": "jornada_partida",
      "shift_label": "Jornada Partida (con pausa comida)",
      "has_lunch_break": true,
      "lunch_duration_minutes": 60,
      "lunch_note": "Pausa de comida automática"
    }
  ]
}
```

## Validation Checklist

- [x] PHP syntax valid (`php -l` passes)
- [x] Parameter reception working
- [x] Format validation (HH:MM regex)
- [x] Function signature updated
- [x] Calculation logic implemented
- [x] JSON response includes forced_start_time field
- [x] HTML checkbox added with proper styling
- [x] AJAX handler implemented with loading state
- [x] Re-renders suggestions correctly
- [x] Shows confirmation message

## Notes

- Format validation ensures only valid HH:MM times are accepted
- Invalid formats silently revert to normal suggestions (no error thrown)
- Forced time applies to all days except Friday (Friday is always jornada_continua)
- Lunch breaks and end times adjust automatically based on the shifted start time
- User experience: smooth loading state, clear confirmation of applied force
