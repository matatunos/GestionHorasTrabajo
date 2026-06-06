<?php
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../lib.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/plantilla_informe.php';

require_login();
$user = current_user();

$anio = isset($_GET['anio']) ? intval($_GET['anio']) : date('Y');
$mes = isset($_GET['mes']) ? intval($_GET['mes']) : date('n');
$usuarioId = $user['id']; // Usar el usuario actual

// Obtener datos
$pdo = get_pdo();
if (!$pdo) die('No se pudo conectar a la base de datos.');
$fechaInicio = sprintf('%04d-%02d-01', $anio, $mes);
$fechaFin = date('Y-m-t', strtotime($fechaInicio));

// Obtener configuración anual para el usuario
$config = get_year_config($anio, $usuarioId);


// Cargar festivos del año para el usuario
$holidayMap = [];
try {
    $hstmt = $pdo->prepare('SELECT date,label,type,annual,user_id FROM holidays WHERE (YEAR(date) = ? OR annual = 1) AND (user_id IS NULL OR user_id = ?)');
    $hstmt->execute([$anio, $usuarioId]);
    foreach ($hstmt->fetchAll() as $h) {
        $keyDate = $h['date'];
        if (!empty($h['annual'])) {
            $keyDate = sprintf('%04d-%s', $anio, substr($h['date'],5));
        }
        $holidayMap[$keyDate] = ['label' => $h['label'], 'type' => $h['type']];
    }
} catch (Throwable $e) { /* ignorar si la tabla no existe */ }

$diasMes = (int)date('t', strtotime($fechaInicio));
$datos = [];
for ($dia = 1; $dia <= $diasMes; $dia++) {
    $fecha = sprintf('%04d-%02d-%02d', $anio, $mes, $dia);
    // Buscar entrada para ese día
    $stmt = $pdo->prepare('SELECT * FROM entries WHERE user_id = ? AND date = ?');
    $stmt->execute([$usuarioId, $fecha]);
    $fila = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['date' => $fecha, 'user_id' => $usuarioId];
    // Marcar festivo o ausencia
    if (isset($holidayMap[$fecha])) {
        $fila['is_holiday'] = true;
        $fila['special_type'] = $holidayMap[$fecha]['type'] ?? 'holiday';
    }
    // Enriquecer con balances y totales usando compute_day
    $calc = compute_day($fila, $config);
    $datos[] = array_merge($fila, $calc);
}

// Generar PDF en memoria
require __DIR__ . '/../../../vendor/autoload.php';
$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('GestionHorasTrabajo');
$pdf->SetAuthor('Sistema');
$pdf->SetTitle('Informe de Fichajes');
$pdf->SetMargins(15, 20, 15);
$pdf->AddPage();
renderizarInformePDF($pdf, $datos, [
    'usuario' => $user['username'] ?? 'Empleado',
    'periodo' => "$fechaInicio a $fechaFin"
]);

// Descargar directamente
$pdf->Output('informe_generado.pdf', 'D');
exit;
