<?php
// Script para monitorear e importar automáticamente el archivo subido

require_once 'vendor/autoload.php';
require_once 'import.php';
require_once 'db.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$pdo = get_pdo();

// Buscar el archivo más reciente en uploads
$files = glob('uploads/*.xlsx');
if (empty($files)) {
    die("No hay archivos XLSX en uploads/\n");
}

// Ordenar por fecha de modificación (más reciente primero)
usort($files, function($a, $b) {
    return filemtime($b) - filemtime($a);
});

$file = $files[0];
$filename = basename($file);
$filesize = filesize($file);
$mtime = date('Y-m-d H:i:s', filemtime($file));

echo "=== Auto-Import ===\n\n";
echo "Archivo: $filename\n";
echo "Tamaño: " . round($filesize / 1024, 2) . " KB\n";
echo "Modificado: $mtime\n\n";

try {
    $spreadsheet = IOFactory::load($file);
    $sheet = $spreadsheet->getActiveSheet();
    $sheetName = $sheet->getTitle();
    
    // Detectar año
    $sheetYear = null;
    if (preg_match('/\b(20\d{2})\b/', $sheetName, $matches)) {
        $sheetYear = (int)$matches[1];
    }
    
    echo "Hoja: $sheetName\n";
    echo "Año: $sheetYear\n";
    echo "Filas detectadas: " . $sheet->getHighestDataRow() . "\n\n";
    
    // Preparar statement
    $sql = "INSERT INTO entries (user_id, date, start, coffee_out, coffee_in, lunch_out, lunch_in, end, note)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
            start=VALUES(start), coffee_out=VALUES(coffee_out), coffee_in=VALUES(coffee_in),
            lunch_out=VALUES(lunch_out), lunch_in=VALUES(lunch_in), end=VALUES(end), note=VALUES(note)";
    
    $stmt = $pdo->prepare($sql);
    
    $insertCount = 0;
    $errorCount = 0;
    $errors = [];
    
    // Procesar filas (skip header)
    for ($row = 2; $row <= $sheet->getHighestDataRow(); $row++) {
        $usuario = trim($sheet->getCell('A' . $row)->getValue() ?? '');
        $fecha = $sheet->getCell('B' . $row)->getValue();
        $nota = trim($sheet->getCell('C' . $row)->getValue() ?? '');
        
        if (!$usuario || !$fecha) continue;
        
        // Parsear fecha
        $fechaISO = null;
        if (is_object($fecha)) {
            $fechaISO = $fecha->format('Y-m-d');
        } else {
            try {
                $fechaStr = (string)$fecha;
                $dt = \DateTime::createFromFormat('d-m-Y', $fechaStr);
                if (!$dt) $dt = \DateTime::createFromFormat('d/m/Y', $fechaStr);
                if (!$dt) $dt = \DateTime::createFromFormat('d.m.Y', $fechaStr);
                if (!$dt) $dt = \DateTime::createFromFormat('j-M', $fechaStr);
                
                if ($dt) {
                    $dtYear = (int)$dt->format('Y');
                    // Validar año
                    if ($dtYear < 2000 || $dtYear > 2026) {
                        $dt->setDate($sheetYear ?: 2024, $dt->format('m'), $dt->format('d'));
                    }
                    $fechaISO = $dt->format('Y-m-d');
                }
            } catch (\Exception $e) {
                // Ignorar
            }
        }
        
        if (!$fechaISO) {
            $errorCount++;
            $errors[] = "Fila $row: No se pudo parsear fecha '$fecha'";
            continue;
        }
        
        // Extraer horas (columnas D, E, F, I, J, L)
        $horas = [];
        foreach (['D', 'E', 'F', 'I', 'J', 'L'] as $col) {
            $h = trim($sheet->getCell($col . $row)->getValue() ?? '');
            if ($h && $h !== '') {
                $horas[] = $h;
            }
        }
        
        // Mapear a slots
        $slots = mapTimesToSlots($horas);
        
        // Buscar o crear usuario
        $userStmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $userStmt->execute([$usuario]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            // Crear usuario
            $createUser = $pdo->prepare("INSERT INTO users (username, password, is_admin) VALUES (?, ?, ?)");
            try {
                $createUser->execute([$usuario, password_hash('temp', PASSWORD_DEFAULT), 0]);
                $userId = $pdo->lastInsertId();
            } catch (Exception $e) {
                $errorCount++;
                $errors[] = "Fila $row: Error creando usuario '$usuario': " . $e->getMessage();
                continue;
            }
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
        } catch (PDOException $e) {
            $errorCount++;
            $errors[] = "Fila $row: " . $e->getMessage();
        }
    }
    
    echo "Procesamiento completado.\n\n";
    echo "=== Resultados ===\n";
    echo "✓ Insertados/Actualizados: $insertCount\n";
    echo "✗ Errores: $errorCount\n";
    
    if (!empty($errors)) {
        echo "\nDetalles de errores:\n";
        foreach (array_slice($errors, 0, 5) as $err) {
            echo "  - $err\n";
        }
        if (count($errors) > 5) {
            echo "  ... y " . (count($errors) - 5) . " más\n";
        }
    }
    
    // Verificar registros finales
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM entries");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "\nRegistros totales en BD: " . $result['count'] . "\n";
    
    // Mostrar resumen por año
    echo "\nRegistros por año:\n";
    $stmt = $pdo->query("SELECT YEAR(date) as year, COUNT(*) as count FROM entries GROUP BY YEAR(date) ORDER BY year DESC");
    $years = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($years as $row) {
        echo "  " . $row['year'] . ": " . $row['count'] . "\n";
    }
    
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
?>
