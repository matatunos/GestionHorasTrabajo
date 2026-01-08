<?php
/**
 * Sample JSON response showing lunch break information
 */

// Simulate lunch break data structure
$suggestions_sample = [
    [
        'date' => '2026-01-08',
        'day_name' => 'Jueves',
        'day_of_week' => 4,
        'start' => '08:00',
        'end' => '17:15',
        'hours' => '9:15',  // 9h 15m of work + lunch break
        'confidence' => 'alta',
        'pattern_count' => 8,
        'shift_type' => 'partida',
        'shift_label' => 'Jornada Partida',
        'has_lunch_break' => true,
        'lunch_duration_minutes' => 60,
        'lunch_start' => '13:45',
        'lunch_end' => '14:45',
        'lunch_note' => 'Pausa comida: 60 min (13:45-14:45)',
        'reasoning' => 'Basado en 8 registros históricos | Jornada partida'
    ],
    [
        'date' => '2026-01-09',
        'day_name' => 'Viernes',
        'day_of_week' => 5,
        'start' => '07:42',
        'end' => '13:42',
        'hours' => '6:00',
        'confidence' => 'alta',
        'pattern_count' => 8,
        'shift_type' => 'continua',
        'shift_label' => 'Jornada Continua',
        'has_lunch_break' => false,
        'lunch_duration_minutes' => 0,
        'lunch_start' => null,
        'lunch_end' => null,
        'lunch_note' => 'Sin pausa comida',
        'reasoning' => 'Basado en 8 registros históricos | Viernes: Jornada continua, salida 13:45-14:10 (sin pausa comida, restricción operativa)'
    ]
];

echo "=== RESPUESTA JSON - INFORMACIÓN DE PAUSA COMIDA ===\n\n";
echo json_encode($suggestions_sample, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

echo "=== CAMPOS DISPONIBLES ===\n\n";
echo "Por cada sugerencia de horario, los siguientes campos indican pausa comida:\n\n";
echo "1. has_lunch_break (booleano)\n";
echo "   - true: Hay pausa comida\n";
echo "   - false: Sin pausa comida\n\n";

echo "2. shift_type (string)\n";
echo "   - 'partida': Jornada partida (CON pausa)\n";
echo "   - 'continua': Jornada continua (SIN pausa)\n\n";

echo "3. shift_label (string)\n";
echo "   - 'Jornada Partida': Con pausa\n";
echo "   - 'Jornada Continua': Sin pausa\n\n";

echo "4. lunch_start (string | null)\n";
echo "   - Hora de inicio de pausa (ej: '13:45')\n";
echo "   - null si no hay pausa\n\n";

echo "5. lunch_end (string | null)\n";
echo "   - Hora de fin de pausa (ej: '14:45')\n";
echo "   - null si no hay pausa\n\n";

echo "6. lunch_duration_minutes (número)\n";
echo "   - Duración en minutos (ej: 60)\n";
echo "   - 0 si no hay pausa\n\n";

echo "7. lunch_note (string)\n";
echo "   - Descripción legible:\n";
echo "     'Pausa comida: 60 min (13:45-14:45)' si hay pausa\n";
echo "     'Sin pausa comida' si no hay pausa\n\n";

echo "=== EJEMPLO DE USO ===\n\n";
echo "Frontend puede mostrar de forma clara:\n";
echo "  Jueves 8:\n";
echo "    ✓ 08:00 - 17:15 (9h 15m)\n";
echo "    ✓ Tipo: Jornada Partida\n";
echo "    ✓ 🍽️  Pausa comida: 13:45-14:45 (60 min)\n\n";

echo "  Viernes 9:\n";
echo "    ✓ 07:42 - 13:42 (6h)\n";
echo "    ✓ Tipo: Jornada Continua\n";
echo "    ✓ Sin pausa comida\n";
