<?php
require_once __DIR__ . '/../db.php';

$pdo = get_pdo();
if (!$pdo) {
    fwrite(STDERR, "No se pudo conectar a la base de datos. Comprueba la configuración.\n");
    exit(1);
}

$stdin = fopen('php://stdin', 'r');
fwrite(STDOUT, "Este script eliminará TODAS las filas de la tabla 'entries' (todos los años).\n");
fwrite(STDOUT, "Si estás seguro, escribe YES (en mayúsculas) y pulsa Enter: ");
$confirm = trim(fgets($stdin));
if ($confirm !== 'YES') {
    fwrite(STDOUT, "Operación cancelada. Ningún dato fue modificado.\n");
    exit(0);
}

try {
    // Borrado sin transacción para evitar problemas con drivers/config
    $affected = $pdo->exec('DELETE FROM entries');
    // reset auto-increment
    $pdo->exec('ALTER TABLE entries AUTO_INCREMENT = 1');
    fwrite(STDOUT, "Eliminadas $affected filas de la tabla 'entries'.\n");
} catch (Exception $e) {
    fwrite(STDERR, "Error al limpiar la base de datos: " . $e->getMessage() . "\n");
    exit(2);
}

fwrite(STDOUT, "Operación completada.\n");
