<?php
/**
 * caldav-vacaciones.php — Sincronización CalDAV de vacaciones con Nextcloud
 *
 * Proporciona dos funciones públicas:
 *   caldav_add_vacation(string $date, string $type)  — crea/actualiza evento en Nextcloud
 *   caldav_remove_vacation(string $date, string $type) — elimina evento de Nextcloud
 *
 * Los tipos soportados son:
 *   'vacation'         → emoji 🏖️  etiqueta "Vacaciones"
 *   'LibreDisposicion' → emoji 📋  etiqueta "Libre Disposición"
 *
 * Configuración: las credenciales se leen de /opt/GestionHorasTrabajo/.env
 * (o de las variables de entorno ya cargadas al incluir este fichero).
 * Las constantes NC_* se definen aquí si no vienen del entorno.
 *
 * Dependencias: PHP + extensión curl (no se requiere ninguna librería CalDAV).
 *
 * Log: /var/log/caldav-vacaciones.log (errores y confirmaciones)
 */

// --- Configuración CalDAV (credenciales desde .env, nunca hardcodeadas) ---
define('CALDAV_NC_BASE',     getenv('CALDAV_NC_BASE') ?: 'https://nextcloud.favala.es/remote.php/dav');
define('CALDAV_NC_USER',     getenv('CALDAV_NC_USER') ?: '');
define('CALDAV_NC_PASS',     getenv('CALDAV_NC_PASS') ?: '');
define('CALDAV_CAL_SLUG',    getenv('CALDAV_CAL_SLUG') ?: 'vacaciones-trabajo');
define('CALDAV_LOG',         '/var/log/caldav-vacaciones.log');
define('CALDAV_PRODID',      '-//GestionHorasTrabajo//Vacaciones//ES');

// ---------------------------------------------------------------------------
// Funciones públicas
// ---------------------------------------------------------------------------

/**
 * Crea o actualiza un evento de día completo en el calendario Nextcloud.
 *
 * @param string $date  Fecha en formato YYYY-MM-DD
 * @param string $type  'vacation' | 'LibreDisposicion'
 * @return bool         true si la operación CalDAV tuvo éxito (HTTP 201 o 204)
 */
function caldav_add_vacation(string $date, string $type = 'vacation'): bool {
    $uid     = _caldav_uid($date, $type);
    $url     = _caldav_event_url($uid);
    $ical    = _caldav_build_ical($date, $type, $uid);
    $code    = _caldav_put($url, $ical);
    $success = in_array($code, [201, 204]);
    _caldav_log($success ? 'ADD OK' : "ADD FAIL HTTP $code", $date, $type);
    return $success;
}

/**
 * Elimina el evento CalDAV correspondiente a la fecha y tipo dados.
 *
 * @param string $date  Fecha en formato YYYY-MM-DD
 * @param string $type  'vacation' | 'LibreDisposicion'
 * @return bool         true si se eliminó (HTTP 204) o no existía (HTTP 404)
 */
function caldav_remove_vacation(string $date, string $type = 'vacation'): bool {
    $uid     = _caldav_uid($date, $type);
    $url     = _caldav_event_url($uid);
    $code    = _caldav_delete($url);
    $success = in_array($code, [204, 404]);
    _caldav_log($success ? 'DEL OK' : "DEL FAIL HTTP $code", $date, $type);
    return $success;
}

// ---------------------------------------------------------------------------
// Funciones internas
// ---------------------------------------------------------------------------

/** UID único por fecha y tipo — estable entre re-ejecuciones */
function _caldav_uid(string $date, string $type): string {
    $slug = ($type === 'LibreDisposicion') ? 'ld' : 'vac';
    return "gestionhoras-{$slug}-{$date}";
}

/** URL completa del recurso CalDAV */
function _caldav_event_url(string $uid): string {
    return CALDAV_NC_BASE
        . '/calendars/' . CALDAV_NC_USER . '/' . CALDAV_CAL_SLUG
        . '/' . $uid . '.ics';
}

/** Construye el bloque iCal (VCALENDAR con VEVENT de día completo) */
function _caldav_build_ical(string $date, string $type, string $uid): string {
    // DTEND es el día siguiente (exclusivo) para eventos de todo el día
    $dtstart  = str_replace('-', '', $date);
    $dtend    = str_replace('-', '', date('Y-m-d', strtotime($date . ' +1 day')));
    $dtstamp  = gmdate('Ymd\THis\Z');
    $summary  = ($type === 'LibreDisposicion') ? 'Libre Disposición' : 'Vacaciones';
    $emoji    = ($type === 'LibreDisposicion') ? '📋' : '🏖️';
    $category = ($type === 'LibreDisposicion') ? 'LIBRE-DISPOSICION' : 'VACACIONES';

    return implode("\r\n", [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:' . CALDAV_PRODID,
        'CALSCALE:GREGORIAN',
        'BEGIN:VEVENT',
        'UID:' . $uid . '@gestionhoras',
        'DTSTAMP:' . $dtstamp,
        'DTSTART;VALUE=DATE:' . $dtstart,
        'DTEND;VALUE=DATE:' . $dtend,
        'SUMMARY:' . $emoji . ' ' . $summary,
        'CATEGORIES:' . $category,
        'TRANSP:TRANSPARENT',
        'END:VEVENT',
        'END:VCALENDAR',
        '',
    ]);
}

/** Realiza un PUT CalDAV y devuelve el código HTTP */
function _caldav_put(string $url, string $ical_body): int {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_USERPWD        => CALDAV_NC_USER . ':' . CALDAV_NC_PASS,
        CURLOPT_CUSTOMREQUEST  => 'PUT',
        CURLOPT_POSTFIELDS     => $ical_body,
        CURLOPT_HTTPHEADER     => ['Content-Type: text/calendar; charset=utf-8'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,   // cert propio Nextcloud
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code;
}

/** Realiza un DELETE CalDAV y devuelve el código HTTP */
function _caldav_delete(string $url): int {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_USERPWD        => CALDAV_NC_USER . ':' . CALDAV_NC_PASS,
        CURLOPT_CUSTOMREQUEST  => 'DELETE',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code;
}

/** Escribe una línea en el log de CalDAV */
function _caldav_log(string $status, string $date, string $type): void {
    $line = sprintf("[%s] %s | date=%s type=%s\n",
        date('Y-m-d H:i:s'), $status, $date, $type);
    @file_put_contents(CALDAV_LOG, $line, FILE_APPEND | LOCK_EX);
}
