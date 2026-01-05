# Importador de HORARIO_2024.xlsx

Script PHP para importar datos de asistencia desde archivos Excel del formato HORARIO_2024.xlsx al sistema de gestión de horas de trabajo.

## Características

- Lee archivos Excel (.xlsx) usando la librería phpspreadsheet
- Procesa múltiples hojas (2024, 2025, 2026)
- Convierte fechas automáticamente al formato yyyy-mm-dd
- Valida datos antes de importar
- Soporta modo "dry-run" para verificar sin guardar
- Inserta nuevos registros o actualiza existentes
- Compatible con ejecución por línea de comandos

## Requisitos

- PHP >= 8.0
- Composer
- Acceso a la base de datos MySQL configurada en el proyecto

## Instalación

Las dependencias ya están incluidas en el repositorio. Si necesitas reinstalarlas:

```bash
composer install --no-dev
```

## Uso

### Sintaxis básica

```bash
php scripts/import_horario_2024.php --user-id=N [opciones]
```

### Opciones

- `--user-id=N` (requerido): ID del usuario para el que se importan los datos
- `--file=ruta` (opcional): Ruta al archivo Excel. Por defecto busca en:
  - `uploads/HORARIO_2024.xlsx`
  - `HORARIO_2024.xlsx` (raíz del proyecto)
- `--dry-run` (opcional): Modo de prueba que no guarda cambios en la base de datos
- `--help` (opcional): Muestra la ayuda del comando

### Ejemplos

**Modo de prueba (dry-run):**
```bash
php scripts/import_horario_2024.php --user-id=1 --dry-run
```

**Importación real:**
```bash
php scripts/import_horario_2024.php --user-id=1
```

**Con ruta de archivo personalizada:**
```bash
php scripts/import_horario_2024.php --user-id=1 --file=/ruta/al/archivo.xlsx
```

## Estructura del archivo Excel

El script espera un archivo Excel con las siguientes características:

### Hojas procesadas
- 2024
- 2025
- 2026

(Si alguna hoja no existe, se salta automáticamente)

### Estructura de datos

Los datos deben comenzar en la **fila 13** (las filas anteriores contienen encabezados).

**Columnas:**
- **B**: Día (fecha en cualquier formato reconocible por Excel)
- **D**: Hora entrada
- **E**: Café salida
- **F**: Café entrada
- **I**: Comida salida
- **J**: Comida entrada
- **L**: Hora salida

### Mapeo a la base de datos

Los datos se importan a la tabla `entries` con la siguiente correspondencia:

| Columna Excel | Campo en DB | Descripción |
|---------------|-------------|-------------|
| B | date | Fecha (convertida a yyyy-mm-dd usando el año de la hoja) |
| D | start | Hora de entrada |
| E | coffee_out | Salida para café |
| F | coffee_in | Regreso de café |
| I | lunch_out | Salida para comida |
| J | lunch_in | Regreso de comida |
| L | end | Hora de salida |

## Comportamiento

### Validación
- Se valida que el archivo exista
- Se verifica que el usuario exista en la base de datos
- Se omiten filas sin datos en columna B
- Se saltan filas sin ningún dato de tiempo

### Inserción/Actualización
- Si ya existe un registro para la fecha y usuario: **se actualiza**
- Si no existe: **se inserta un nuevo registro** con la nota "Importado desde Excel"

### Salida

El script muestra:
- Información de configuración (archivo, usuario, modo)
- Progreso por hoja y fila
- Estado de cada operación (INSERTADO, ACTUALIZADO, DRY RUN)
- Resumen final con estadísticas

**Ejemplo de salida:**
```
Archivo: /ruta/al/HORARIO_2024.xlsx
User ID: 1
Modo: DRY RUN (no se guardarán cambios)

Usuario: admin (ID: 1)

========================================
Procesando hoja: 2024
========================================
  Fila  90: 2024-03-18 | E:00:00 CO:--:-- CI:--:-- LO:--:-- LI:--:-- S:07:39 [DRY RUN]
  Fila  91: 2024-03-19 | E:00:00 CO:--:-- CI:--:-- LO:--:-- LI:--:-- S:07:39 [DRY RUN]
  ...
  Total filas procesadas en hoja '2024': 365

========================================
RESUMEN DE IMPORTACIÓN
========================================
Total filas leídas:      368
Registros insertados:    150
Registros actualizados:  0
Filas saltadas (vacías): 218
Errores:                 0
```

## Código de salida

- `0`: Éxito (sin errores)
- `1`: Error (faltan parámetros, archivo no encontrado, errores de importación, etc.)

## Notas técnicas

### Formato de tiempo
- Los tiempos se extraen de las celdas de Excel y se convierten a formato HH:MM
- Se soportan tanto valores numéricos de Excel (fracciones de día) como texto formateado

### Formato de fecha
- Las fechas de la columna B se parsean automáticamente
- El año se toma del nombre de la hoja para asegurar consistencia
- Soporta fechas numéricas de Excel y texto formateado (ej: "1-Jan", "18-Mar")

### Límites
- Máximo 500 filas por hoja
- Si la columna B está vacía, se asume fin de datos

## Troubleshooting

**Error: "No se pudo conectar a la base de datos"**
- Verifica las credenciales en `config.php` o variables de entorno
- Asegúrate de que el servidor MySQL esté corriendo

**Error: "El usuario con ID X no existe"**
- Verifica que el ID de usuario sea correcto
- Consulta los usuarios disponibles con: `SELECT id, username FROM users;`

**Error: "No se encontró el archivo Excel"**
- Verifica que el archivo esté en `uploads/HORARIO_2024.xlsx` o en la raíz
- Usa `--file=ruta` para especificar una ubicación diferente

**Advertencia: "No se encontró la hoja 'XXXX'"**
- Normal si el archivo no contiene esa hoja
- El script continúa con las hojas disponibles

## Autor

Script desarrollado para el proyecto GestionHorasTrabajo.
