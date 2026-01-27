# /scripts - Scripts de Utilidad

Contiene scripts CLI y herramientas que NO se acceden vía web.

## Estructura

- **testing/** - Scripts de validación y testing
  - check_login.php - Verifica funcionamiento de login
  - check_friday_calc.php - Valida cálculos de viernes
  - verify_friday_constraint.php - Verifica restricciones de viernes
  - test_*.php - Otros scripts de test

- **migrations/** - Scripts de migración de BD
  - migrate_add_absence_type_column.php
  - migrate_add_incidents_table.php
  - migrate_json_to_db.php

- **seed_year.php** - Seed de datos por año

## Cómo ejecutar

```bash
cd /opt/GestionHorasTrabajo
php scripts/testing/check_login.php
php scripts/migrations/migrate_add_incidents_table.php
php scripts/seed_year.php
```

**Nota:** Estos scripts requieren acceso CLI directo a la BD. No son accesibles vía web.
