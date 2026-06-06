<?php
// Plugin: pdf_informe - Generador de informes PDF con estructura personalizada
require __DIR__ . '/../../../vendor/autoload.php';

// Incluir funciones y conexión del sistema principal
require_once __DIR__ . '/../../lib.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/plantilla_informe.php';



// 1. Obtener datos de la base de datos (ejemplo: fichajes de un usuario)
function obtenerDatosEjemplo($pdo, $usuarioId, $fechaInicio, $fechaFin) {
    $stmt = $pdo->prepare('SELECT * FROM entries WHERE user_id = ? AND date BETWEEN ? AND ? ORDER BY date ASC');
    $stmt->execute([$usuarioId, $fechaInicio, $fechaFin]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}


// 2. Conexión a la base de datos reutilizando la función del sistema
$pdo = get_pdo();
if (!$pdo) {
    die("No se pudo conectar a la base de datos.\n");
}


// 3. Parámetros desde argumentos de línea de comandos o POST
$usuarioId = 1; // Puedes adaptar esto para que sea dinámico si lo deseas
if (isset($argv) && count($argv) >= 3) {
    $anio = intval($argv[1]);
    $mes = intval($argv[2]);
} else {
    $anio = intval(date('Y'));
    $mes = intval(date('n'));
}
$fechaInicio = sprintf('%04d-%02d-01', $anio, $mes);
$fechaFin = date('Y-m-t', strtotime($fechaInicio));
$datos = obtenerDatosEjemplo($pdo, $usuarioId, $fechaInicio, $fechaFin);

// 4. Generar el PDF usando la plantilla
$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('GestionHorasTrabajo');
$pdf->SetAuthor('Sistema');
$pdf->SetTitle('Informe de Fichajes');
$pdf->SetMargins(15, 20, 15);
$pdf->AddPage();

// Llama a la función de plantilla para renderizar el contenido
renderizarInformePDF($pdf, $datos, [
    'usuario' => 'Nombre Apellido',
    'periodo' => "$fechaInicio a $fechaFin"
]);

$pdf->Output(__DIR__ . '/informe_generado.pdf', 'F');
echo "Informe generado: plugins/pdf_informe/informe_generado.pdf\n";
