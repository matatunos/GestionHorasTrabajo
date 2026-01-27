<?php
// Función para renderizar el informe PDF con la estructura del ejemplo
function renderizarInformePDF($pdf, $datos, $info = []) {
    // Cabecera con logo y datos
    if (file_exists(__DIR__ . '/logo.png')) {
        $pdf->Image(__DIR__ . '/logo.png', 15, 10, 30, 0, '', '', '', false, 300);
        $pdf->SetXY(50, 10);
    } else {
        $pdf->SetXY(15, 10);
    }
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 10, 'INFORME MENSUAL DE REGISTRO HORARIO', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 6, 'Nombre: ' . ($info['usuario'] ?? '________________________'), 0, 1, 'L');
    $pdf->Cell(0, 6, 'Periodo: ' . ($info['periodo'] ?? '__________'), 0, 1, 'L');
    $pdf->Ln(2);

    // Tabla de fichajes detallada
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetFillColor(220, 220, 220);
    $pdf->Cell(18, 8, 'Fecha', 1, 0, 'C', 1);
    $pdf->Cell(16, 8, 'Entrada', 1, 0, 'C', 1);
    $pdf->Cell(16, 8, 'Salida', 1, 0, 'C', 1);
    $pdf->Cell(16, 8, 'Entrada', 1, 0, 'C', 1);
    $pdf->Cell(16, 8, 'Salida', 1, 0, 'C', 1);
    $pdf->Cell(16, 8, 'Entrada', 1, 0, 'C', 1);
    $pdf->Cell(16, 8, 'Salida', 1, 0, 'C', 1);
    $pdf->Cell(18, 8, 'Efectivas', 1, 0, 'C', 1);
    $pdf->Cell(18, 8, 'Balance', 1, 0, 'C', 1);
    $pdf->Cell(18, 8, 'Acum.', 1, 1, 'C', 1);
    $pdf->SetFont('helvetica', '', 9);


    $balance_acumulado = 0;
    $semana_actual = null;
    $totales_semana = [
        'efectivas' => 0,
        'balance' => 0,
        'esperadas' => 0
    ];
    // Para resumen mensual
    $monthStats = [
        'expected_minutes' => 0,
        'worked_minutes' => 0,
        'workdays' => 0,
        'days_with_worked' => 0,
        'missing_workdays' => 0,
        'dietas' => 0,
        'coffee_excess_total' => 0,
        'coffee_excess_days' => 0,
    ];
    foreach ($datos as $fila) {
        $fecha = $fila['date'];
        $dow = date('N', strtotime($fecha)); // 1=lunes ... 7=domingo
        $semana = date('W', strtotime($fecha));

        // Si cambia la semana y no es la primera fila, mostrar totales de la semana anterior
        if ($semana_actual !== null && $semana !== $semana_actual) {
            // Fila separadora de totales semanales
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->SetFillColor(200, 255, 200);
            $pdf->Cell(98, 7, 'Total semana '.$semana_actual, 1, 0, 'R', 1);
            $pdf->Cell(18, 7, minutes_to_hours_formatted($totales_semana['efectivas']), 1, 0, 'C', 1);
            $pdf->Cell(18, 7, minutes_to_hours_formatted($totales_semana['balance']), 1, 0, 'C', 1);
            $pdf->Cell(18, 7, '', 1, 1, 'C', 1);
            $pdf->SetFont('helvetica', '', 9);
            // Reset totales
            $totales_semana = [ 'efectivas' => 0, 'balance' => 0, 'esperadas' => 0 ];
        }
        $semana_actual = $semana;

        // Fila normal de día
        $pdf->Cell(18, 7, $fecha, 1);
        $pdf->Cell(16, 7, $fila['start'] ?? '', 1);
        $pdf->Cell(16, 7, $fila['coffee_out'] ?? '', 1);
        $pdf->Cell(16, 7, $fila['coffee_in'] ?? '', 1);
        $pdf->Cell(16, 7, $fila['lunch_out'] ?? '', 1);
        $pdf->Cell(16, 7, $fila['lunch_in'] ?? '', 1);
        $pdf->Cell(16, 7, $fila['end'] ?? '', 1);
        // Horas efectivas
        $efectivas = $fila['worked_minutes_for_display'] ?? null;
        $efectivas_fmt = $efectivas !== null ? minutes_to_hours_formatted($efectivas) : '';
        $pdf->Cell(18, 7, $efectivas_fmt, 1);
        // Balance diario
        $bal = $fila['day_balance'] ?? null;
        $bal_fmt = $bal !== null ? minutes_to_hours_formatted($bal) : '';
        $pdf->Cell(18, 7, $bal_fmt, 1);
        // Balance acumulado
        if ($bal !== null) $balance_acumulado += $bal;
        $pdf->Cell(18, 7, minutes_to_hours_formatted($balance_acumulado), 1, 1);

        // Acumular totales semanales
        if ($efectivas !== null) $totales_semana['efectivas'] += $efectivas;
        if ($bal !== null) $totales_semana['balance'] += $bal;
        // Calcular esperadas por día (asumimos 8h/día laborable, puedes ajustar)
        $exp = $fila['expected_minutes'] ?? 0;
        if ($exp > 0) $totales_semana['esperadas'] += $exp;

        // Resumen mensual (igual que index.php)
        if ($exp > 0) {
            $monthStats['expected_minutes'] += $exp;
            $monthStats['workdays'] += 1;
            if (($fila['worked_minutes_for_display'] ?? null) === null) {
                $monthStats['missing_workdays'] += 1;
            }
        }
        if ($efectivas !== null) {
            $monthStats['worked_minutes'] += $efectivas;
            $monthStats['days_with_worked'] += 1;
        }
        $lb = $fila['lunch_balance'] ?? null;
        if ($lb !== null && intval($lb) >= 0) $monthStats['dietas'] += 1;
        $cb = $fila['coffee_balance'] ?? null;
        if ($cb !== null && intval($cb) > 0) {
            $monthStats['coffee_excess_total'] += intval($cb);
            $monthStats['coffee_excess_days'] += 1;
        }

        // Si es domingo, mostrar totales de la semana
        if ($dow == 7) {
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->SetFillColor(200, 255, 200);
            $pdf->Cell(98, 7, 'Total semana '.$semana, 1, 0, 'R', 1);
            $pdf->Cell(18, 7, minutes_to_hours_formatted($totales_semana['efectivas']), 1, 0, 'C', 1);
            $pdf->Cell(18, 7, minutes_to_hours_formatted($totales_semana['balance']), 1, 0, 'C', 1);
            $pdf->Cell(18, 7, minutes_to_hours_formatted($totales_semana['esperadas']), 1, 1, 'C', 1);
            // Resumen semanal
            $pdf->SetFont('helvetica', 'I', 8);
            $pdf->Cell(0, 6, 'Resumen semanal: Efectivas '.minutes_to_hours_formatted($totales_semana['efectivas']).' / Esperadas '.minutes_to_hours_formatted($totales_semana['esperadas']).' / Balance '.minutes_to_hours_formatted($totales_semana['balance']), 0, 1, 'R');
            $pdf->SetFont('helvetica', '', 9);
            $pdf->Ln(3);
            $totales_semana = [ 'efectivas' => 0, 'balance' => 0, 'esperadas' => 0 ];
        }
    }

    // Resumen mensual
    $pdf->Ln(6);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(0, 8, '📅 Resumen mensual', 0, 1, 'L');
    $mExp = intval($monthStats['expected_minutes']);
    $mWork = intval($monthStats['worked_minutes']);
    $mBal = $mWork - $mExp;
    $dietas = intval($monthStats['dietas']);
    $coffeeExCount = intval($monthStats['coffee_excess_days']);
    $coffeeExAvg = ($coffeeExCount > 0) ? intdiv(intval($monthStats['coffee_excess_total']), $coffeeExCount) : 0;
    $missing = intval($monthStats['missing_workdays']);
    $workdays = intval($monthStats['workdays']);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(60, 7, 'Balance', 1, 0, 'L');
    $pdf->Cell(30, 7, minutes_to_hours_formatted($mBal), 1, 1, 'C');
    $pdf->Cell(60, 7, 'Esperadas', 1, 0, 'L');
    $pdf->Cell(30, 7, minutes_to_hours_formatted($mExp), 1, 1, 'C');
    $pdf->Cell(60, 7, 'Hechas', 1, 0, 'L');
    $pdf->Cell(30, 7, minutes_to_hours_formatted($mWork), 1, 1, 'C');
    $pdf->Cell(60, 7, 'Dietas', 1, 0, 'L');
    $pdf->Cell(30, 7, $dietas, 1, 1, 'C');
    $pdf->Cell(60, 7, 'Café exceso medio', 1, 0, 'L');
    $pdf->Cell(30, 7, $coffeeExCount > 0 ? minutes_to_hours_formatted($coffeeExAvg) : '—', 1, 1, 'C');
    $pdf->Cell(60, 7, 'Días con datos', 1, 0, 'L');
    $pdf->Cell(30, 7, $monthStats['days_with_worked'].'/'.$workdays.($missing>0 ? ' · Incompletos '.$missing : ''), 1, 1, 'C');

    // Pie de página con espacio para firma
    $pdf->Ln(10);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 8, 'Firma trabajador: ___________________________', 0, 1, 'L');
    $pdf->Cell(0, 8, 'Firma empresa:   ___________________________', 0, 1, 'L');
    $pdf->Ln(4);
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->Cell(0, 6, 'Generado automáticamente por GestionHorasTrabajo', 0, 1, 'C');
}
