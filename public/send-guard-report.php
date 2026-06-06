<?php
/**
 * send-guard-report.php — Informe mensual de guardias
 *
 * Genera y envía por email el resumen de guardias de un mes dado.
 * Festivos y fines de semana se unifican como "Festivos" (equivalentes a efectos de guardia).
 *
 * Uso web  (botón UI): POST JSON {mes, year, email}  — requiere sesión activa
 * Uso CLI  (cron):     php send-guard-report.php YYYY-MM correo@ejemplo.com
 *
 * El email se envía vía PHP mail(). Para que funcione, el servidor necesita un MTA local
 * (ej: msmtp, ssmtp, postfix) configurado como relay a tu SMTP (DonDominio, etc.).
 * En Debian:  apt install msmtp-mta  y configurar /etc/msmtprc
 */

$is_cli = (php_sapi_name() === 'cli');

if (!$is_cli) {
    require_once __DIR__ . '/auth.php';
    require_login();
    header('Content-Type: application/json');
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/config.php';

$meses_es = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio',
             'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];

$pdo = get_pdo();

// --- Determinar mes/año, email y usuario ---
if ($is_cli) {
    $arg = $argv[1] ?? null;
    if ($arg && preg_match('/^(\d{4})-(\d{1,2})$/', $arg, $m)) {
        $year = (int)$m[1];
        $mes  = (int)$m[2];
    } else {
        $prev = new DateTime('first day of last month');
        $year = (int)$prev->format('Y');
        $mes  = (int)$prev->format('n');
    }
    // Email como segundo argumento o variable de entorno GUARD_REPORT_EMAIL
    $email_destino = $argv[2] ?? (getenv('GUARD_REPORT_EMAIL') ?: '');
    if (!$email_destino) {
        fwrite(STDERR, "ERROR: Indica el email: php send-guard-report.php YYYY-MM correo@ejemplo.com\n");
        exit(1);
    }
    $current  = $pdo->query('SELECT id, username FROM users LIMIT 1')->fetch();
    $usuarios = [['id' => $current['id'], 'username' => $current['username'], 'email' => $email_destino, 'name' => $current['username']]];
} else {
    // Leer parámetros desde el body JSON (POST desde el botón del modal)
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $year          = isset($body['year']) ? (int)$body['year'] : 0;
    $mes           = isset($body['mes'])  ? (int)$body['mes']  : 0;
    $email_destino = trim($body['email'] ?? '');

    if ($mes < 1 || $mes > 12 || $year < 2000 || $year > 2100) {
        echo json_encode(['ok' => false, 'error' => 'Parámetros inválidos']);
        exit;
    }
    if (!filter_var($email_destino, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['ok' => false, 'error' => 'Email destino inválido']);
        exit;
    }

    $current  = current_user();
    $stmt     = $pdo->prepare('SELECT id, username FROM users WHERE id = ?');
    $stmt->execute([$current['id']]);
    $u        = $stmt->fetch();
    $usuarios = [['id' => $u['id'], 'username' => $u['username'], 'email' => $email_destino, 'name' => $u['username']]];
}

// --- Generar y enviar informe para cada usuario ---
$resultados = [];

foreach ($usuarios as $usuario) {
    $uid = $usuario['id'];

    // Consultar guardias del mes con clasificación de día
    $stmt = $pdo->prepare("
        SELECT
            h.date,
            DAYOFWEEK(h.date) AS dow,
            CASE
                WHEN EXISTS (
                    SELECT 1 FROM holidays f
                    WHERE f.date = h.date
                      AND f.type = 'holiday'
                      AND (f.user_id IS NULL OR f.user_id = :uid)
                ) THEN 'festivo'
                WHEN DAYOFWEEK(h.date) IN (1, 7) THEN 'finde'
                ELSE 'laborable'
            END AS tipo_dia,
            COALESCE((
                SELECT f.label FROM holidays f
                WHERE f.date = h.date
                  AND f.type = 'holiday'
                  AND (f.user_id IS NULL OR f.user_id = :uid2)
                LIMIT 1
            ), '') AS label_festivo
        FROM holidays h
        WHERE h.type = 'guardia'
          AND YEAR(h.date)  = :year
          AND MONTH(h.date) = :mes
          AND (h.user_id IS NULL OR h.user_id = :uid3)
        ORDER BY h.date
    ");
    $stmt->execute([
        ':uid'  => $uid, ':uid2' => $uid, ':uid3' => $uid,
        ':year' => $year, ':mes'  => $mes,
    ]);
    $guardias = $stmt->fetchAll();

    // Clasificar: festivos (festivo+finde) y laborables
    $total      = count($guardias);
    $festivos   = 0;
    $laborables = 0;

    foreach ($guardias as $g) {
        if ($g['tipo_dia'] === 'laborable') {
            $laborables++;
        } else {
            $festivos++;
        }
    }

    $mes_nombre = $meses_es[$mes];
    $nombre     = $usuario['name'] ?: $usuario['username'];
    $to         = $usuario['email'];
    $rango_str  = rangos_guardias($guardias, $mes_nombre, $year);
    $asunto     = "🛡️ Guardias {$mes_nombre} {$year}";
    $cuerpo     = construir_email($nombre, $mes_nombre, $year, $rango_str, $total, $festivos, $laborables);

    $enviado = enviar_email($to, $asunto, $cuerpo);

    $resultados[] = [
        'usuario'    => $usuario['username'],
        'email'      => $to,
        'total'      => $total,
        'festivos'   => $festivos,
        'laborables' => $laborables,
        'enviado'    => $enviado,
    ];

    if ($is_cli) {
        $estado = $enviado ? 'OK' : 'ERROR';
        echo "[{$estado}] {$usuario['username']} <{$to}> — {$total} guardias ({$festivos} festivos, {$laborables} laborables)\n";
    }
}

if (!$is_cli) {
    $r  = $resultados[0] ?? [];
    $ok = !empty($r['enviado']);
    echo json_encode([
        'ok'         => $ok,
        'total'      => $r['total']      ?? 0,
        'festivos'   => $r['festivos']   ?? 0,
        'laborables' => $r['laborables'] ?? 0,
        'mes'        => $meses_es[$mes],
        'year'       => $year,
        'error'      => $ok ? null : 'Error al enviar el email. Comprueba que el servidor de correo esté configurado (ver comentario en send-guard-report.php).',
    ]);
}

// --- Construye el cuerpo HTML del email ---
// Agrupa fechas de guardias en rangos consecutivos y devuelve cadena legible.
// Ej: "del 8 al 14, del 28 al 30 de Abril de 2026"  /  "día 5, del 8 al 10 de Abril de 2026"
function rangos_guardias(array $guardias, string $mes_nombre, int $year): string {
    if (empty($guardias)) return '';

    $timestamps = array_map(fn($g) => strtotime($g['date']), $guardias);
    sort($timestamps);

    $rangos  = [];
    $ini     = $timestamps[0];
    $fin     = $timestamps[0];
    $un_dia  = 86400;

    for ($i = 1, $n = count($timestamps); $i < $n; $i++) {
        if ($timestamps[$i] - $fin === $un_dia) {
            $fin = $timestamps[$i];
        } else {
            $rangos[] = [$ini, $fin];
            $ini = $timestamps[$i];
            $fin = $timestamps[$i];
        }
    }
    $rangos[] = [$ini, $fin];

    $partes = [];
    foreach ($rangos as [$d_ini, $d_fin]) {
        $n_ini = (int)date('j', $d_ini);
        $n_fin = (int)date('j', $d_fin);
        $partes[] = ($n_ini === $n_fin) ? "día {$n_ini}" : "del {$n_ini} al {$n_fin}";
    }

    return implode(', ', $partes) . " de {$mes_nombre} de {$year}";
}

function construir_email($nombre, $mes, $year, $rango_str, $total, $festivos, $laborables) {
    $esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

    $sin_guardias = $total === 0
        ? '<p style="color:#a0aec0;font-style:italic;">Sin guardias registradas en ' . $esc($mes) . ' ' . $year . '.</p>'
        : '';

    return "<!DOCTYPE html>
<html lang=\"es\">
<head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"></head>
<body style=\"margin:0;padding:0;background:#f7f9fc;font-family:Arial,Helvetica,sans-serif;color:#2d3748;\">
<div style=\"max-width:580px;margin:32px auto;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.08);\">

  <div style=\"background:linear-gradient(135deg,#2d3748,#1a202c);padding:24px 28px;\">
    <h1 style=\"margin:0;font-size:20px;color:#fff;\">🛡️ Guardias {$esc($mes)} {$year}</h1>
    <p style=\"margin:6px 0 0;color:#a0aec0;font-size:13px;\">{$esc($rango_str)}</p>
  </div>

  <div style=\"padding:24px 28px;\">
    <p style=\"margin:0 0 20px;color:#4a5568;font-size:14px;\">Resumen de guardias de <strong>{$esc($mes)} {$year}</strong>:</p>

    <table style=\"width:100%;border-collapse:collapse;margin:0 0 20px;\">
      <thead>
        <tr style=\"background:#edf2f7;\">
          <th style=\"padding:9px 12px;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#718096;\">Categoría</th>
          <th style=\"padding:9px 12px;text-align:center;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#718096;\">Días</th>
        </tr>
      </thead>
      <tbody>
        <tr style=\"border-bottom:1px solid #edf2f7;\">
          <td style=\"padding:11px 12px;font-weight:700;\">Total guardias</td>
          <td style=\"padding:11px 12px;text-align:center;font-size:20px;font-weight:700;\">{$total}</td>
        </tr>
        <tr style=\"background:#fff5f5;border-bottom:1px solid #edf2f7;\">
          <td style=\"padding:10px 12px;\">🔴 <strong>Festivos</strong> <span style=\"font-size:12px;color:#718096;\">(festivos + fines de semana)</span></td>
          <td style=\"padding:10px 12px;text-align:center;font-weight:700;color:#c53030;font-size:17px;\">{$festivos}</td>
        </tr>
        <tr style=\"background:#ebf8ff;\">
          <td style=\"padding:10px 12px;\">🔵 <strong>Laborables</strong></td>
          <td style=\"padding:10px 12px;text-align:center;font-weight:700;color:#2b6cb0;font-size:17px;\">{$laborables}</td>
        </tr>
      </tbody>
    </table>

    {$sin_guardias}
  </div>
</div>
</body>
</html>";
}

// --- Envía el email via curl + SMTPS (DonDominio) ---
function enviar_email(string $to, string $asunto, string $html): bool {
    // Credenciales desde .env (nunca hardcodeadas)
    $smtp_url  = getenv('SMTP_URL')  ?: 'smtps://smtp.dondominio.com:465';
    $smtp_user = getenv('SMTP_USER') ?: 'homelab@favala.es';
    $smtp_pass = getenv('SMTP_PASS') ?: '';
    $from      = getenv('SMTP_FROM') ?: ($smtp_user ?: 'homelab@favala.es');
    $from_name = getenv('SMTP_FROM_NAME') ?: 'GestionHoras';

    // Construir mensaje MIME completo en un fichero temporal
    $boundary = '----=_Part_' . uniqid();
    $asunto_b64 = '=?UTF-8?B?' . base64_encode($asunto) . '?=';
    $mensaje = implode("\r\n", [
        "From: {$from_name} <{$from}>",
        "To: {$to}",
        "Subject: {$asunto_b64}",
        "MIME-Version: 1.0",
        "Content-Type: text/html; charset=UTF-8",
        "Content-Transfer-Encoding: base64",
        "",
        chunk_split(base64_encode($html)),
    ]);

    $tmp = tempnam(sys_get_temp_dir(), 'mail_');
    file_put_contents($tmp, $mensaje);

    $cmd = implode(' ', [
        'curl -s --ssl-reqd',
        '--url ' . escapeshellarg($smtp_url),
        '--user ' . escapeshellarg("{$smtp_user}:{$smtp_pass}"),
        '--mail-from ' . escapeshellarg($from),
        '--mail-rcpt ' . escapeshellarg($to),
        '--upload-file ' . escapeshellarg($tmp),
        '2>&1',
    ]);

    $output = shell_exec($cmd);
    unlink($tmp);

    // curl devuelve string vacío en éxito; cualquier output indica error
    return (trim($output ?? '') === '');
}
