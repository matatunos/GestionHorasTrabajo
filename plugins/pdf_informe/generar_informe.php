<?php
// Plugin: pdf_informe - Generador de informes PDF con estructura personalizada
require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/plantilla_informe.php';

use TCPDF;

// 1. Obtener datos de la base de datos (ejemplo: fichajes de un usuario)
function obtenerDatosEjemplo($pdo, $usuarioId, $fechaInicio, $fechaFin) {
    $stmt = $pdo->prepare('SELECT * FROM fichajes WHERE user_id = ? AND date BETWEEN ? AND ? ORDER BY date ASC');
    $stmt->execute([$usuarioId, $fechaInicio, $fechaFin]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 2. Conexión a la base de datos (ajusta según tu config)
$pdo = new PDO('mysql:host=localhost;dbname=gestionhoras', 'usuario', 'password');

// 3. Parámetros de ejemplo
$usuarioId = 1;
$fechaInicio = '2026-01-01';
$fechaFin = '2026-01-31';
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
