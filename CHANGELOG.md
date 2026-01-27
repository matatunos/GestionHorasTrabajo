# Changelog

Todas las versiones importantes y cambios relevantes del proyecto se documentarán aquí.

## [v1.1.1] - 2026-01-27
### Arreglado
- Corrección de problemas de login causados por cambio de credenciales incorrecto
- Actualización de rutas de archivos movidos a nuevas carpetas (/lib, /scripts, /admin, /tools, /docs)
- Corrección de permisos en carpeta /logs para que Apache pueda escribir

### Cambiado
- Reorganización conservadora del proyecto para mejorar estructura y mantenibilidad
  - Helpers y librerías movidos a /lib (JWTHelper, SecurityHeaders, LogAnalytics, LogConfig, improvements_functions)
  - Scripts de testing movidos a /scripts/testing
  - Scripts de migración movidos a /scripts/migrations
  - Herramientas de administración movidas a /admin
  - Herramientas de análisis movidas a /tools
  - Documentación reorganizada: 67 archivos .md movidos a /docs/archive (se mantienen solo README, INSTALL, CHANGELOG en raíz)

### Mejorado
- Estructura del proyecto reducida de 58 PHP en raíz a 30 (reducción 48%)
- Documentación centralizada
- Mejor separación entre código de producción y herramientas
- Facilitado el onboarding de nuevos desarrolladores

- Edición inline funcional para horas esperadas invierno/verano en la configuración de años.

### Cambiado
- El formulario de filtros ahora usa el id correcto para que el JS lo detecte.
- El selector de año tiene el id necesario para el JS.

## [v1.0.0] - versión inicial
- Primer release estable del sistema de gestión de horas de trabajo.
