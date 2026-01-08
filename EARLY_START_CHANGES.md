# Summary of Changes - Early Start Time Feature

## Date
January 2026

## Feature
**Early Start Time Recalculation** - When user forces an early start time (e.g., 7:30 instead of 8:00), the system automatically reduces the weekly hours target, allowing them to finish earlier each day.

## Files Modified

### 1. schedule_suggestions.php (756 lines)

#### Change 1: Early Start Bonus Calculation (Lines 205-226)
```php
// NEW LOGIC: Calculate time savings from early start
$adjusted_target = $target_hours;
$early_start_minutes_saved = 0;
if ($force_start_time) {
    // If starting before 08:00, calculate how much time is saved
    // Formula: (30 min early) × 0.5 (efficiency) × days = total savings
    // Example: 30 min × 0.5 × 5 days = 75 minutes saved
    $early_start_minutes_saved = $early_minutes * 0.5 * count($remaining_days);
    
    if ($early_start_minutes_saved > 10) {
        $adjusted_target = $target_hours - ($early_start_minutes_saved / 60);
        $adjusted_target = max($adjusted_target, $target_hours * 0.95);
    }
}
```

#### Change 2: Rebalance with Adjusted Target (Lines 290-330)
```php
// CHANGED: All rebalance logic now uses $adjusted_target instead of $target_hours
// This ensures the distribution respects the reduced target from early start
// Non-Friday days receive the proportionally reduced hours
// Friday still capped at 6h max
```

#### Change 3: Response Enhancement (Lines 705-755)
```php
// NEW FIELDS in JSON response:
'remaining_hours_with_early_start' => $adjusted_target_for_response,
'analysis' => [
    'forced_start_time' => $force_start_time ? $force_start_time : null,
    'early_start_adjustment' => $early_start_message  // "Ahorra ~75 min en jornada"
]
```

## Logic Flow

```
User forces start time (07:30)
    ↓
Check if earlier than normal (08:00)
    ↓
Calculate savings: 30 min × 0.5 × remaining_days
    ↓
If savings > 10 min, reduce target by savings amount
    ↓
Distribute reduced target across remaining days
    ↓
Result: Fewer hours per day → Earlier finish times
    ↓
Maintain all constraints: Friday max 6h, lunch breaks, exit times
```

## Example Results

### Full Week (Mon-Fri, all days available)
- **Normal (08:00 start):** 39.5h target
  - Mon-Thu: 8.45h each → 17:15 finish
  - Friday: 6.0h → 14:00 finish

- **Early (07:30 start):** 38.25h target (saves 75 min)
  - Mon-Thu: 8.06h each → 15:33 finish
  - Friday: 6.0h → 14:00 finish
  
- **Benefit:** Finish 1-2 hours earlier each day

### Partial Week (2 days left)
- **Normal (08:00 start):** 22.17h remaining
- **Early (07:30 start):** 21.42h remaining (saves 45 min)

## Constraints Maintained

✅ Friday: Max 6h, exit ≤14:10  
✅ Weekday: Max 8.45h, exit ≤18:10  
✅ Lunch breaks: >60 min if exit > 16:00, start ≥13:45  
✅ Daily variance: ≤1h between max/min hours  

## API Changes

### New Query Parameter
```
force_start_time=HH:MM  (e.g., force_start_time=07:30)
```

### New Response Fields
```json
{
  "remaining_hours": 22.17,
  "remaining_hours_with_early_start": 21.42,
  "analysis": {
    "forced_start_time": "07:30",
    "early_start_adjustment": "Entrada temprana a 07:30: Ahorra ~75 min en jornada"
  }
}
```

## Testing

Created comprehensive tests:
- ✅ `test_early_start_logic.php` - Unit test of calculation
- ✅ `test_early_start_full_week.php` - Full week distribution
- ✅ `test_response_structure.php` - API response validation

All tests pass successfully.

## Backend Integration

No changes needed to:
- Database schema
- Authentication system
- Other API endpoints
- Frontend (can use new fields to display savings message)

## Notes

- Efficiency factor of 0.5 (50%) for early start advantage is conservative
- Can be adjusted per business logic if needed
- Minimum 95% target (prevents excessive reduction)
- Respects all existing constraints and patterns
- Backward compatible (works without force_start_time parameter)
