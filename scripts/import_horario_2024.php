<?php
/**
 * Importador de HORARIO_2024.xlsx
 * 
 * Este script importa datos de asistencia desde el archivo HORARIO_2024.xlsx
 * ubicado en la carpeta uploads/ o en la raíz del proyecto.
 * 
 * Procesa las hojas: 2024, 2025, 2026
 * Lee datos desde la fila 13 de cada hoja (filas 11-12 son encabezados):
 *   - Columna B: Día (se convierte a formato yyyy-mm-dd)
 *   - Columna D: Hora entrada
 *   - Columna E: Café salida
 *   - Columna F: Café entrada
 *   - Columna I: Comida salida
 *   - Columna J: Comida entrada
 *   - Columna L: Hora salida
 * 
 * Uso:
 *   php scripts/import_horario_2024.php [--user-id=N] [--file=ruta] [--dry-run]
 * 
 * Opciones:
 *   --user-id=N    ID del usuario para el que se importan los datos (requerido)
 *   --file=ruta    Ruta al archivo Excel (por defecto: uploads/HORARIO_2024.xlsx o HORARIO_2024.xlsx)
 *   --dry-run      Modo de prueba, no guarda datos en la base de datos
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

// Configuración
const SHEETS_TO_PROCESS = ['2024', '2025', '2026'];
const DATA_START_ROW = 13; // Los datos empiezan en la fila 13 (fila 11 son headers parte 1, fila 12 headers parte 2)
const MAX_ROWS = 500; // Máximo de filas a procesar por hoja

// Parse command line arguments
$options = getopt('', ['user-id:', 'file:', 'dry-run', 'help']);

if (isset($options['help'])) {
    $fileContent = file_get_contents(__FILE__);
    echo substr($fileContent, 0, strpos($fileContent, '*/') + 2);
    exit(0);
}

$userId = isset($options['user-id']) ? intval($options['user-id']) : null;
$dryRun = isset($options['dry-run']);

// Validar user_id
if (!$userId) {
    fwrite(STDERR, "Error: Se requiere el parámetro --user-id\n");
    fwrite(STDERR, "Uso: php scripts/import_horario_2024.php --user-id=N [--file=ruta] [--dry-run]\n");
    exit(1);
}

// Determinar ruta del archivo
$filePath = null;
if (isset($options['file'])) {
    $filePath = $options['file'];
} else {
    // Intentar ubicaciones por defecto
    $possiblePaths = [
        __DIR__ . '/../uploads/HORARIO_2024.xlsx',
        __DIR__ . '/../HORARIO_2024.xlsx',
    ];
    foreach ($possiblePaths as $path) {
        if (file_exists($path)) {
            $filePath = $path;
            break;
        }
    }
}

if (!$filePath || !file_exists($filePath)) {
    fwrite(STDERR, "Error: No se encontró el archivo Excel.\n");
    fwrite(STDERR, "Intentado en: uploads/HORARIO_2024.xlsx y HORARIO_2024.xlsx\n");
    fwrite(STDERR, "Use --file=ruta para especificar una ubicación diferente.\n");
    exit(1);
}

echo "Archivo: $filePath\n";
echo "User ID: $userId\n";
echo "Modo: " . ($dryRun ? "DRY RUN (no se guardarán cambios)" : "IMPORTACIÓN REAL") . "\n";
echo "\n";

// Conectar a la base de datos
$pdo = get_pdo();
if (!$pdo) {
    fwrite(STDERR, "Error: No se pudo conectar a la base de datos.\n");
    exit(1);
}

// Verificar que el usuario existe
$stmt = $pdo->prepare('SELECT id, username FROM users WHERE id = ?');
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    fwrite(STDERR, "Error: El usuario con ID $userId no existe.\n");
    exit(1);
}

echo "Usuario: {$user['username']} (ID: {$user['id']})\n\n";

// Cargar el archivo Excel
try {
    echo "Cargando archivo Excel...\n";
    $spreadsheet = IOFactory::load($filePath);
} catch (Exception $e) {
    fwrite(STDERR, "Error al cargar el archivo Excel: " . $e->getMessage() . "\n");
    exit(1);
}

// Estadísticas
$stats = [
    'total_rows' => 0,
    'inserted' => 0,
    'updated' => 0,
    'skipped' => 0,
    'errors' => 0,
];

// Procesar cada hoja
foreach (SHEETS_TO_PROCESS as $sheetName) {
    echo "\n========================================\n";
    echo "Procesando hoja: $sheetName\n";
    echo "========================================\n";
    
    try {
        $sheet = $spreadsheet->getSheetByName($sheetName);
    } catch (Exception $e) {
        echo "  Advertencia: No se encontró la hoja '$sheetName'. Saltando...\n";
        continue;
    }
    
    if (!$sheet) {
        echo "  Advertencia: No se encontró la hoja '$sheetName'. Saltando...\n";
        continue;
    }
    
    $year = intval($sheetName);
    $rowsProcessed = 0;
    
    // Procesar filas de datos
    for ($row = DATA_START_ROW; $row < DATA_START_ROW + MAX_ROWS; $row++) {
        $cellB = $sheet->getCell('B' . $row);
        $colBValue = $cellB->getValue();
        
        // Si la celda B está vacía, asumimos que no hay más datos
        if (empty($colBValue)) {
            break;
        }
        
        $stats['total_rows']++;
        $rowsProcessed++;
        
        // Convertir el valor de la columna B a fecha
        $date = null;
        try {
            if (Date::isDateTime($cellB)) {
                // Es una fecha de Excel
                $dateObj = Date::excelToDateTimeObject($colBValue);
                // Usar el año de la hoja para asegurar consistencia
                $dateObj->setDate($year, $dateObj->format('m'), $dateObj->format('d'));
                $date = $dateObj->format('Y-m-d');
            } else {
                // Intentar parsear como texto
                $formattedValue = $cellB->getFormattedValue();
                $dateObj = DateTime::createFromFormat('d-M', $formattedValue);
                if ($dateObj) {
                    $dateObj->setDate($year, $dateObj->format('m'), $dateObj->format('d'));
                    $date = $dateObj->format('Y-m-d');
                } else {
                    throw new Exception("No se pudo parsear la fecha: $formattedValue");
                }
            }
        } catch (Exception $e) {
            echo "  Advertencia fila $row: " . $e->getMessage() . "\n";
            $stats['errors']++;
            continue;
        }
        
        // Leer las columnas de tiempo
        $start = formatTimeValue($sheet->getCell('D' . $row));
        $coffeeOut = formatTimeValue($sheet->getCell('E' . $row));
        $coffeeIn = formatTimeValue($sheet->getCell('F' . $row));
        $lunchOut = formatTimeValue($sheet->getCell('I' . $row));
        $lunchIn = formatTimeValue($sheet->getCell('J' . $row));
        $end = formatTimeValue($sheet->getCell('L' . $row));
        
        // Validar que al menos un campo de tiempo tenga datos
        if (empty($start) && empty($coffeeOut) && empty($coffeeIn) && 
            empty($lunchOut) && empty($lunchIn) && empty($end)) {
            $stats['skipped']++;
            continue;
        }
        
        // Mostrar información de la fila
        echo sprintf(
            "  Fila %3d: %s | E:%s CO:%s CI:%s LO:%s LI:%s S:%s",
            $row,
            $date,
            $start ?: '--:--',
            $coffeeOut ?: '--:--',
            $coffeeIn ?: '--:--',
            $lunchOut ?: '--:--',
            $lunchIn ?: '--:--',
            $end ?: '--:--'
        );
        
        if ($dryRun) {
            echo " [DRY RUN]\n";
            $stats['inserted']++;
            continue;
        }
        
        try {
            // Verificar si ya existe un registro para esta fecha y usuario
            $stmt = $pdo->prepare('SELECT id FROM entries WHERE user_id = ? AND date = ?');
            $stmt->execute([$userId, $date]);
            $existing = $stmt->fetch();
            
            if ($existing) {
                // Actualizar registro existente
                $stmt = $pdo->prepare(
                    'UPDATE entries SET start=?, coffee_out=?, coffee_in=?, lunch_out=?, lunch_in=?, end=? WHERE id=?'
                );
                $stmt->execute([
                    $start ?: null,
                    $coffeeOut ?: null,
                    $coffeeIn ?: null,
                    $lunchOut ?: null,
                    $lunchIn ?: null,
                    $end ?: null,
                    $existing['id']
                ]);
                echo " [ACTUALIZADO]\n";
                $stats['updated']++;
            } else {
                // Insertar nuevo registro
                $stmt = $pdo->prepare(
                    'INSERT INTO entries (user_id, date, start, coffee_out, coffee_in, lunch_out, lunch_in, end, note) 
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $userId,
                    $date,
                    $start ?: null,
                    $coffeeOut ?: null,
                    $coffeeIn ?: null,
                    $lunchOut ?: null,
                    $lunchIn ?: null,
                    $end ?: null,
                    'Importado desde Excel'
                ]);
                echo " [INSERTADO]\n";
                $stats['inserted']++;
            }
        } catch (PDOException $e) {
            echo " [ERROR: " . $e->getMessage() . "]\n";
            $stats['errors']++;
        }
    }
    
    echo "  Total filas procesadas en hoja '$sheetName': $rowsProcessed\n";
}

// Mostrar resumen
echo "\n========================================\n";
echo "RESUMEN DE IMPORTACIÓN\n";
echo "========================================\n";
echo "Total filas leídas:      {$stats['total_rows']}\n";
echo "Registros insertados:    {$stats['inserted']}\n";
echo "Registros actualizados:  {$stats['updated']}\n";
echo "Filas saltadas (vacías): {$stats['skipped']}\n";
echo "Errores:                 {$stats['errors']}\n";
echo "\n";

if ($dryRun) {
    echo "NOTA: Modo DRY RUN - No se guardaron cambios en la base de datos.\n";
    echo "Ejecute sin --dry-run para guardar los datos.\n";
}

exit($stats['errors'] > 0 ? 1 : 0);

/**
 * Formatea un valor de tiempo de una celda de Excel
 * 
 * @param \PhpOffice\PhpSpreadsheet\Cell\Cell $cell
 * @return string|null Tiempo en formato HH:MM o null si está vacío
 */
function formatTimeValue($cell) {
    $value = $cell->getValue();
    
    // Si está vacío, retornar null
    if ($value === null || $value === '') {
        return null;
    }
    
    // Si es un valor numérico (tiempo de Excel)
    if (is_numeric($value)) {
        // Excel almacena tiempos como fracciones de día
        if ($value >= 0 && $value < 1) {
            $hours = floor($value * 24);
            $minutes = round(($value * 24 * 60) % 60);
            return sprintf('%02d:%02d', $hours, $minutes);
        }
    }
    
    // Intentar obtener el valor formateado
    $formatted = $cell->getFormattedValue();
    
    // Si parece un tiempo (contiene :)
    if (strpos($formatted, ':') !== false) {
        // Extraer HH:MM del formato
        if (preg_match('/(\d{1,2}):(\d{2})/', $formatted, $matches)) {
            $hours = intval($matches[1]);
            $minutes = intval($matches[2]);
            return sprintf('%02d:%02d', $hours, $minutes);
        }
    }
    
    return null;
}
