<?php
/**
 * ⚠️ DEPRECATED: API alternativa sin validación de HTTPS
 * 
 * Este archivo existe solo por compatibilidad histórica.
 * NUNCA debe usarse en producción.
 * 
 * Reemplaza este endpoint por la API segura en api.php
 * 
 * Si lo necesitas en desarrollo, asegúrate de que:
 * 1. Solo sea accesible en entorno local/development
 * 2. Nunca esté expuesto en producción
 * 3. Siempre uses HTTPS en production
 */

// Redirige a la API segura
header('Location: api.php', true, 301);
exit();
?>
