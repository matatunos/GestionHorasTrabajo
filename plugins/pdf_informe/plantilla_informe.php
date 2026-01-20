<?php
// Función para renderizar el informe PDF con la estructura del ejemplo
function renderizarInformePDF($pdf, $datos, $info = []) {
    // Cabecera
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 12, 'INFORME DE FICHAJES', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 8, 'Usuario: ' . ($info['usuario'] ?? ''), 0, 1, 'L');
    $pdf->Cell(0, 8, 'Periodo: ' . ($info['periodo'] ?? ''), 0, 1, 'L');
    $pdf->Ln(4);

    // Tabla de datos (estructura ejemplo)
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(230, 230, 230);
    $pdf->Cell(30, 8, 'Fecha', 1, 0, 'C', 1);
    $pdf->Cell(30, 8, 'Entrada', 1, 0, 'C', 1);
    $pdf->Cell(30, 8, 'Salida', 1, 0, 'C', 1);
    $pdf->Cell(60, 8, 'Notas', 1, 1, 'C', 1);
    $pdf->SetFont('helvetica', '', 10);

    foreach ($datos as $fila) {
        $pdf->Cell(30, 8, $fila['date'], 1);
        $pdf->Cell(30, 8, $fila['start'] ?? '', 1);
        $pdf->Cell(30, 8, $fila['end'] ?? '', 1);
        $pdf->Cell(60, 8, $fila['note'] ?? '', 1, 1);
    }

    // Pie de página
    $pdf->Ln(10);
    $pdf->SetFont('helvetica', 'I', 9);
    $pdf->Cell(0, 8, 'Generado automáticamente por GestionHorasTrabajo', 0, 1, 'C');
}
