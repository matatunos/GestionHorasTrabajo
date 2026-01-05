<?php
// Test completo de import: lectura Excel + mapeo + inserción BD

require_once 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PDO;

// Funciones locales
function mapTimesToSlots($times) {
    $timeCount = count($times);
    $horas_slots = [null, null, null, null, null, null];
    if ($timeCount === 0) return $horas_slots;
    $horas_slots[0] = $times[0];
    if ($timeCount === 1) return $horas_slots;
    if ($timeCount === 2) {
        $horas_slots[5] = $times[1];
        return $horas_slots;
    }
    if ($timeCount === 3) {
        $horas_slots[1] = $times[1];
        $horas_slots[5] = $times[2];
        return $horas_slots;
    }
    $intermediateIndex = 1;
    for ($i = 1; $i < $timeCount - 1; $i++) {
        if ($intermediateIndex <= 4) {
            $horas_slots[$intermediateIndex] = $times[$i];
            $intermediateIndex++;
        }
    }
    $horas_slots[5] = $times[$timeCount - 1];
    return $horas_slots;
}

// Conexión a BD
$dbHost = 'localhost';
$dbName = 'gestion_horas';
$dbUser = 'app_user';
$dbPass = 'app_pass';

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Error BD: " . $e->getMessage());
}

echo "=== Test Completo de Import ===\n\n";

$file = 'uploads/DEMO_IMPORT_2024.xlsx';
$insertCount = 0;
$errorCount = 0;
$errors = [];

try {
    $spreadsheet = IOFactory::load($file);
    $sheet = $spreadsheet->getActiveSheet();
    $sheetName = $sheet->getTitle();
    
    // Detectar año
    $sheetYear = null;
    if (preg_match('/\b(20\d{2})\b/', $sheetName, $matches)) {
        $sheetYear = (int)$matches[1];
    }
    
    echo "Archivo: $file\n";
    echo "Hoja: $sheetName\n";
    echo "Año detectado: $sheetYear\n\n";
    
    // Preparar statement
    $sql = "INSERT INTO entries (user_id, date, start, coffee_out, coffee_in, lunch_out, lunch_in, end, note)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
            start=VALUES(start), coffee_out=VALUES(coffee_out), coffee_in=VALUES(coffee_in),
            lunch_out=VALUES(lunch_out), lunch_in=VALUES(lunch_in), end=VALUES(end), note=VALUES(note)";
    
    $stmt = $pdo->prepare($sql);
    
    // Procesar filas
    for ($row = 2; $row <= $sheet->getHighestDataRow(); $row++) {
        $usuario = trim($sheet->getCell('A' . $row)->getValue() ?? '');
        $fecha = $sheet->getCell('B' . $row)->getValue();
        $nota = trim($sheet->getCell('C' . $row)->getValue() ?? '');
        
        if (!$usuario || !$fecha) continue;
        
        // Parsear fecha
        $fechaStr = $fecha;
        if (is_object($fecha)) {
            $fechaISO = $fecha->format('Y-m-d');
        } else {
            try {
                $dt = \DateTime::createFromFormat('d-m-Y', $fechaStr);
                if (!$dt) {
                    $dt = \DateTime::createFromFormat('d/m/Y', $fechaStr);
                }
                $fechaISO = $dt ? $dt->format('Y-m-d') : null;
            } catch (\Exception $e) {
                $fechaISO = null;
            }
        }
        
        if (!$fechaISO) {
            $errorCount++;
            $errors[] = "Fila $row: No se pudo parsear fecha '$fechaStr'";
            continue;
        }
        
        // Extraer horas
        $horas = [];
        foreach (['D', 'E', 'F', 'I', 'J', 'L'] as $col) {
            $h = trim($sheet->getCell($col . $row)->getValue() ?? '');
            if ($h) {
                $horas[] = $h;
            }
        }
        
        // Mapear a slots
        $slots = mapTimesToSlots($horas);
        
        // Buscar user_id
        $userStmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $userStmt->execute([$usuario]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            // Crear usuario si no existe
            $createUser = $pdo->prepare("INSERT INTO users (username, password, is_admin) VALUES (?, ?, ?)");
            $createUser->execute([$usuario, password_hash('test', PASSWORD_DEFAULT), 0]);
            $userId = $pdo->lastInsertId();
        } else {
            $userId = $user['id'];
        }
        
        // Insertar entry
        try {
            $stmt->execute([
                $userId,
                $fechaISO,
                $slots[0] ?: null,
                $slots[1] ?: null,
                $slots[2] ?: null,
                $slots[3] ?: null,
                $slots[4] ?: null,
                $slots[5] ?: null,
                $nota ?: null
            ]);
            $insertCount++;
            echo "✓ Fila $row: $usuario - $fechaISO [" . implode(",", array_filter($horas)) . "]\n";
        } catch (PDOException $e) {
            $errorCount++;
            $errors[] = "Fila $row: " . $e->getMessage();
            echo "✗ Fila $row: ERROR - " . $e->getMessage() . "\n";
        }
    }
    
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n=== Resultados ===\n";
echo "Insertados: $insertCount\n";
echo "Errores: $errorCount\n";

if (!empty($errors)) {
    echo "\nDetalles de errores:\n";
    foreach ($errors as $err) {
        echo "  - $err\n";
    }
}

// Verificar registros en BD
$stmt = $pdo->query("SELECT COUNT(*) as count FROM entries");
$result = $stmt->fetch(PDO::FETCH_ASSOC);
echo "\nRegistros totales en entries: " . $result['count'] . "\n";

?>
