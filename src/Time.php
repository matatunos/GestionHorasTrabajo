<?php
namespace App;

/**
 * Utilidades de tiempo unificadas.
 *
 * Centraliza las 3 implementaciones que existían dispersas:
 *  - lib.php: time_to_minutes() / minutes_to_hours_formatted()
 *  - admin/data_quality.php y tools/data_quality.php: timeToMinutes() / minutesToTime()
 */
final class Time
{
    /** "HH:MM" o "HH.MM" → minutos desde medianoche; null si vacío/ inválido. */
    public static function toMinutes(?string $time): ?int
    {
        if (!$time) return null;
        $time = str_replace('.', ':', $time); // admite "." como separador
        $parts = explode(':', $time);
        if (count($parts) < 2) return null;
        return intval($parts[0]) * 60 + intval($parts[1]);
    }

    /** Minutos → "H:MM" con signo (p.ej. "-1:30"). Para balances. */
    public static function format(?int $min): string
    {
        if ($min === null) return '';
        $sign = $min < 0 ? '-' : '';
        $m = abs($min);
        return $sign . sprintf('%d:%02d', intdiv($m, 60), $m % 60);
    }

    /** Minutos → "HH:MM" sin signo, con cero a la izquierda. Para horas de reloj. */
    public static function toClock(?int $min): string
    {
        if ($min === null) return '';
        $min = (int) $min;
        return str_pad((string) intdiv($min, 60), 2, '0', STR_PAD_LEFT)
             . ':' . str_pad((string) ($min % 60), 2, '0', STR_PAD_LEFT);
    }
}
