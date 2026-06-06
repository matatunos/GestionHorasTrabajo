<?php
namespace App;

/**
 * Clase trivial para validar que el autoloader PSR-4 de App\ funciona
 * tras la reestructuración a docroot public/.
 */
final class Health
{
    public static function ok(): string
    {
        return 'App autoload OK';
    }
}
