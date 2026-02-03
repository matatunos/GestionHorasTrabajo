<?php
// Función para renderizar el informe PDF con la estructura del ejemplo
function renderizarInformePDF($pdf, $datos, $info = []) {
    // Cabecera con logo y datos
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->SetTextColor(40, 40, 40);
    $pdf->Cell(0, 8, 'INFORME MENSUAL DE REGISTRO HORARIO', 0, 1, 'C');
    
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetTextColor(80, 80, 80);
    $pdf->Cell(50, 5, 'Empleado: ' . ($info['usuario'] ?? '________________________'), 0, 0, 'L');
    $pdf->Cell(0, 5, 'Período: ' . ($info['periodo'] ?? '__________'), 0, 1, 'L');
    $pdf->Ln(3);

    // Tabla de fichajes
    $pdf->SetFont('helvetica', 'B', 7);
    $pdf->SetFillColor(66, 133, 244);
    $pdf->SetTextColor(255, 255, 255);
    
    $pdf->Cell(13, 5, 'Día', 1, 0, 'C', true);
    $pdf->Cell(12, 5, 'D.S', 1, 0, 'C', true);
    $pdf->Cell(15, 5, 'Ent', 1, 0, 'C', true);
    $pdf->Cell(14, 5, 'E.C', 1, 0, 'C', true);
    $pdf->Cell(14, 5, 'V.C', 1, 0, 'C', true);
    $pdf->Cell(14, 5, 'E.L', 1, 0, 'C', true);
    $pdf->Cell(14, 5, 'V.L', 1, 0, 'C', true);
    $pdf->Cell(15, 5, 'Sal', 1, 0, 'C', true);
    $pdf->Cell(15, 5, 'Efect', 1, 0, 'C', true);
    $pdf->Cell(15, 5, 'Teó', 1, 0, 'C', true);
    $pdf->Cell(14, 5, 'Bal', 1, 0, 'C', true);
    $pdf->Cell(15, 5, 'Acum', 1, 1, 'C', true);
    
    $pdf->SetFont('helvetica', '', 7);
    $pdf->SetTextColor(0, 0, 0);

    $balance_acumulado = 0;
    $semana_actual = null;
    $totales_semana = ['efectivas' => 0, 'balance' => 0, 'esperadas' => 0];
    
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
        $diaNum = date('d', strtotime($fecha));
        
        // Si cambia la semana y no es la primera fila, mostrar totales de la semana anterior
        if ($semana_actual !== null && $semana !== $semana_actual) {
            $pdf->SetFont('helvetica', 'B', 7);
            $pdf->SetFillColor(240, 240, 240);
            $pdf->Cell(13, 5, '', 1, 0, 'C', true);
            $pdf->Cell(12, 5, '', 1, 0, 'C', true);
            $pdf->Cell(15, 5, 'S'.$semana_actual, 1, 0, 'C', true);
            $pdf->Cell(14, 5, '', 1, 0, 'C', true);
            $pdf->Cell(14, 5, '', 1, 0, 'C', true);
            $pdf->Cell(14, 5, '', 1, 0, 'C', true);
            $pdf->Cell(14, 5, '', 1, 0, 'C', true);
            $pdf->Cell(15, 5, '', 1, 0, 'C', true);
            $pdf->Cell(15, 5, minutes_to_hours_formatted($totales_semana['efectivas']), 1, 0, 'C', true);
            $pdf->Cell(15, 5, minutes_to_hours_formatted($totales_semana['esperadas']), 1, 0, 'C', true);
            $pdf->Cell(14, 5, minutes_to_hours_formatted($totales_semana['balance']), 1, 0, 'C', true);
            $pdf->Cell(15, 5, '', 1, 1, 'C', true);
            $pdf->SetFont('helvetica', '', 7);
            $totales_semana = ['efectivas' => 0, 'balance' => 0, 'esperadas' => 0];
        }
        $semana_actual = $semana;

        // Determinar día de semana abreviado
        $diasSemana = ['L', 'M', 'X', 'J', 'V', 'S', 'D'];
        $dayStr = $diasSemana[$dow - 1];
        
        // Color de fondo según tipo de día
        $isHoliday = isset($fila['is_holiday']) && $fila['is_holiday'];
        $isWeekend = $dow >= 6;
        if ($isHoliday || $isWeekend) {
            $pdf->SetFillColor(230, 230, 230);
        } else {
            $pdf->SetFillColor(255, 255, 255);
        }
        
        // Fila normal de día
        $pdf->Cell(13, 5, $diaNum, 1, 0, 'C', $isHoliday || $isWeekend);
        $pdf->Cell(12, 5, $dayStr, 1, 0, 'C', $isHoliday || $isWeekend);
        $pdf->Cell(15, 5, $fila['start'] ?? '', 1, 0, 'C', $isHoliday || $isWeekend);
        $pdf->Cell(14, 5, $fila['coffee_out'] ?? '', 1, 0, 'C', $isHoliday || $isWeekend);
        $pdf->Cell(14, 5, $fila['coffee_in'] ?? '', 1, 0, 'C', $isHoliday || $isWeekend);
        $pdf->Cell(14, 5, $fila['lunch_out'] ?? '', 1, 0, 'C', $isHoliday || $isWeekend);
        $pdf->Cell(14, 5, $fila['lunch_in'] ?? '', 1, 0, 'C', $isHoliday || $isWeekend);
        $pdf->Cell(15, 5, $fila['end'] ?? '', 1, 0, 'C', $isHoliday || $isWeekend);
        
        // Horas efectivas
        $efectivas = $fila['worked_minutes_for_display'] ?? null;
        $efectivas_fmt = $efectivas !== null ? minutes_to_hours_formatted($efectivas) : '';
        $pdf->Cell(15, 5, $efectivas_fmt, 1, 0, 'C', $isHoliday || $isWeekend);
        
        // Esperadas
        $exp = $fila['expected_empresa_minutes'] ?? 0;
        $exp_fmt = $exp > 0 ? minutes_to_hours_formatted($exp) : '';
        $pdf->Cell(15, 5, $exp_fmt, 1, 0, 'C', $isHoliday || $isWeekend);
        
        // Balance diario
        $bal = ($efectivas !== null && $exp > 0) ? ($efectivas - $exp) : null;
        $bal_fmt = $bal !== null ? minutes_to_hours_formatted($bal) : '';
        $pdf->Cell(14, 5, $bal_fmt, 1, 0, 'C', $isHoliday || $isWeekend);
        
        // Balance acumulado
        if ($bal !== null) $balance_acumulado += $bal;
        $pdf->Cell(15, 5, minutes_to_hours_formatted($balance_acumulado), 1, 1, 'C', $isHoliday || $isWeekend);

        // Acumular totales semanales
        if ($efectivas !== null) $totales_semana['efectivas'] += $efectivas;
        if ($bal !== null) $totales_semana['balance'] += $bal;
        if ($exp > 0) $totales_semana['esperadas'] += $exp;

        // Resumen mensual
        if ($exp > 0) {
            $monthStats['expected_minutes'] += $exp;
            $monthStats['workdays'] += 1;
            if ($efectivas === null) {
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

        // Si es domingo, mostrar línea separadora
        if ($dow == 7) {
            $pdf->SetLineWidth(0.1);
            $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
        }
    }
    
    // Última semana si queda abierta
    if ($semana_actual !== null) {
        $pdf->SetFont('helvetica', 'B', 7);
        $pdf->SetFillColor(240, 240, 240);
        $pdf->Cell(13, 5, '', 1, 0, 'C', true);
        $pdf->Cell(12, 5, '', 1, 0, 'C', true);
        $pdf->Cell(15, 5, 'S'.$semana_actual, 1, 0, 'C', true);
        $pdf->Cell(14, 5, '', 1, 0, 'C', true);
        $pdf->Cell(14, 5, '', 1, 0, 'C', true);
        $pdf->Cell(14, 5, '', 1, 0, 'C', true);
        $pdf->Cell(14, 5, '', 1, 0, 'C', true);
        $pdf->Cell(15, 5, '', 1, 0, 'C', true);
        $pdf->Cell(15, 5, minutes_to_hours_formatted($totales_semana['efectivas']), 1, 0, 'C', true);
        $pdf->Cell(15, 5, minutes_to_hours_formatted($totales_semana['esperadas']), 1, 0, 'C', true);
        $pdf->Cell(14, 5, minutes_to_hours_formatted($totales_semana['balance']), 1, 0, 'C', true);
        $pdf->Cell(15, 5, '', 1, 1, 'C', true);
    }

    // Resumen mensual compacto
    $pdf->Ln(4);
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetTextColor(40, 40, 40);
    $pdf->Cell(0, 7, 'RESUMEN MENSUAL', 0, 1, 'L');
    
    $mExp = intval($monthStats['expected_minutes']);
    $mWork = intval($monthStats['worked_minutes']);
    $mBal = $mWork - $mExp;
    $dietas = intval($monthStats['dietas']);
    $missing = intval($monthStats['missing_workdays']);
    $workdays = intval($monthStats['workdays']);
    
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetFillColor(220, 240, 255);
    $pdf->Cell(45, 6, 'Horas Teóricas:', 0, 0, 'L');
    $pdf->Cell(30, 6, minutes_to_hours_formatted($mExp), 0, 1, 'C');
    $pdf->Cell(45, 6, 'Horas Trabajadas:', 0, 0, 'L');
    $pdf->Cell(30, 6, minutes_to_hours_formatted($mWork), 0, 1, 'C');
    
    $balanceColor = $mBal >= 0 ? [200, 255, 200] : [255, 200, 200];
    $pdf->SetFillColor($balanceColor[0], $balanceColor[1], $balanceColor[2]);
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->Cell(45, 6, 'BALANCE MENSUAL:', 0, 0, 'L');
    $pdf->Cell(30, 6, minutes_to_hours_formatted($mBal), 0, 1, 'C');
    
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetFillColor(255, 255, 255);
    $pdf->Cell(45, 5, 'Dietas (comida ≥ ' . intval(60) . 'min):', 0, 0, 'L');
    $pdf->Cell(30, 5, $dietas, 0, 1, 'C');
    $pdf->Cell(45, 5, 'Días con registro / Incompletos:', 0, 0, 'L');
    $pdf->Cell(30, 5, $monthStats['days_with_worked'].'/'.$workdays.($missing>0 ? ' / '.$missing : ''), 0, 1, 'C');

    // Pie de página
    $pdf->Ln(8);
    $pdf->SetFont('helvetica', '', 9);
    $pdf->Cell(50, 6, 'Firma del empleado: ___________', 0, 0, 'L');
    $pdf->Cell(0, 6, 'Firma de la empresa: ___________', 0, 1, 'L');
    
    $pdf->Ln(3);
    $pdf->SetFont('helvetica', 'I', 7);
    $pdf->SetTextColor(150, 150, 150);
    $pdf->Cell(0, 5, 'Generado automáticamente por GestionHorasTrabajo', 0, 1, 'C');
}
