<?php
/**
 * hours-by-month.php — API interna: horas trabajadas y horas teóricas por mes
 *
 * Solo accesible desde red local (192.168.x.x / 10.x.x.x).
 * Usado por CT172 para cruzar con datos de nóminas y calcular €/hora.
 *
 * Parámetros GET (opcionales):
 *   user_id (int) — usuario a consultar (default: 1)
 *
 * Respuesta JSON:
 *   { ok: true, data: [{ year, month, days, hours, expected_hours }] }
 *
 * expected_hours = días laborables del mes (lun-vie) × horas contractuales por día
 *   según temporada (invierno/verano) de year_configs.
 *   Verano: 15-jun al 30-sep. Días festivos incluidos (el salario los cubre).
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// --- Solo acceso LAN ---
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if (!preg_match('/^(192\.168\.|10\.|127\.|::1)/', $ip)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Solo acceso desde red local']);
    exit;
}

require_once __DIR__ . '/db.php';

$user_id = isset($_GET['user_id']) ? max(1, (int)$_GET['user_id']) : 1;

try {
    $pdo = get_pdo();

    // Horas netas por mes: TIMEDIFF(end, start) menos pausa de comida si existe.
    // Se excluyen ausencias (absence_type IS NULL) y entradas sin fichar (start='00:00:00').
    $stmt = $pdo->prepare("
        SELECT
            YEAR(date)    AS year,
            MONTH(date)   AS month,
            COUNT(*)      AS days,
            ROUND(SUM(
                TIME_TO_SEC(TIMEDIFF(`end`, `start`)) / 3600
                - IF(lunch_out IS NOT NULL AND lunch_in IS NOT NULL,
                     TIME_TO_SEC(TIMEDIFF(lunch_in, lunch_out)) / 3600,
                     0)
            ), 2) AS hours
        FROM entries
        WHERE user_id       = ?
          AND absence_type  IS NULL
          AND `start`       IS NOT NULL
          AND `end`         IS NOT NULL
          AND `start`       != '00:00:00'
        GROUP BY YEAR(date), MONTH(date)
        ORDER BY YEAR(date), MONTH(date)
    ");
    $stmt->execute([$user_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Convertir strings numéricos a int/float
    foreach ($rows as &$r) {
        $r['year']  = (int)$r['year'];
        $r['month'] = (int)$r['month'];
        $r['days']  = (int)$r['days'];
        $r['hours'] = (float)$r['hours'];
    }
    unset($r);

    // --- Horas teóricas por mes (horas contractuales × días laborables lun-vie) ---
    // Configuración por año desde year_configs; fallback a app_settings si no existe.
    $year_configs = [];
    $ycStmt = $pdo->query("SELECT year, mon_thu, friday, summer_mon_thu, summer_friday,
                                  expected_daily_hours_winter, expected_daily_hours_summer
                           FROM year_configs");
    foreach ($ycStmt->fetchAll(PDO::FETCH_ASSOC) as $yc) {
        $year_configs[(int)$yc['year']] = $yc;
    }

    // Fechas de inicio/fin del período verano (del app_settings global)
    $summer_start_md = '06-15'; // 15 de junio
    $summer_end_md   = '09-30'; // 30 de septiembre
    try {
        $cfgRow = $pdo->query("SELECT value FROM app_settings WHERE name='site_config'")->fetchColumn();
        $cfg = json_decode($cfgRow, true);
        if (!empty($cfg['summer_start'])) $summer_start_md = $cfg['summer_start'];
        if (!empty($cfg['summer_end']))   $summer_end_md   = $cfg['summer_end'];
    } catch (Exception $e) { /* usar defaults */ }

    // Calcular expected_hours para cada mes que aparece en los resultados
    // También calcular para todos los años/meses con nómina aunque no haya fichajes
    $months_needed = array_map(fn($r) => [$r['year'], $r['month']], $rows);

    // Función: ¿es una fecha período verano?
    $isSummer = function(int $y, int $m, int $d) use ($summer_start_md, $summer_end_md): bool {
        $date_val  = $y * 10000 + $m * 100 + $d;
        [$sm, $sd] = array_map('intval', explode('-', $summer_start_md));
        [$em, $ed] = array_map('intval', explode('-', $summer_end_md));
        $start_val = $y * 10000 + $sm * 100 + $sd;
        $end_val   = $y * 10000 + $em * 100 + $ed;
        return $date_val >= $start_val && $date_val <= $end_val;
    };

    // Añadir expected_hours a cada fila
    foreach ($rows as &$r) {
        $y = $r['year'];
        $m = $r['month'];
        $yc = $year_configs[$y] ?? null;

        // Horas por día según temporada, del year_configs del año correspondiente
        $h_winter_mon_thu = $yc ? (float)$yc['mon_thu']         : 8.0;
        $h_winter_fri     = $yc ? (float)$yc['friday']          : 6.0;
        $h_summer_mon_thu = $yc ? (float)$yc['summer_mon_thu']  : 7.5;
        $h_summer_fri     = $yc ? (float)$yc['summer_friday']   : 6.0;

        // Recorrer todos los días del mes y sumar horas contractuales para lun-vie
        $days_in_month = (int)date('t', mktime(0, 0, 0, $m, 1, $y));
        $expected = 0.0;
        for ($d = 1; $d <= $days_in_month; $d++) {
            $dow = (int)date('N', mktime(0, 0, 0, $m, $d, $y)); // 1=lun … 7=dom
            if ($dow >= 6) continue; // sábado o domingo → no laborable
            $summer = $isSummer($y, $m, $d);
            if ($dow === 5) { // viernes
                $expected += $summer ? $h_summer_fri : $h_winter_fri;
            } else {          // lunes-jueves
                $expected += $summer ? $h_summer_mon_thu : $h_winter_mon_thu;
            }
        }
        $r['expected_hours'] = round($expected, 2);
    }
    unset($r);

    echo json_encode(['ok' => true, 'data' => $rows]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
