# Guía de Importación desde Excel

## Formatos Soportados

El sistema puede leer archivos Excel (`.xlsx`) en dos formatos:

### Formato 1: HORARIO_YYYY.xlsx (Recomendado)

Estructura esperada:
- **Hojas**: 2024, 2025, 2026 (o cualquier combinación)
- **Datos a partir de fila**: 13
- **Columnas de datos**:
  - **B**: Fecha (en cualquier formato)
  - **D**: Hora entrada
  - **E**: Salida café
  - **F**: Entrada café
  - **I**: Salida comida
  - **J**: Entrada comida
  - **L**: Hora salida

Ejemplo:
```
Fila 11: Encabezados (B=Día, D=Entrada, E=Salida Café, etc.)
Fila 12: (vacío o continuación)
Fila 13: 2024-01-08, 08:00, 10:30, 10:50, 13:00, 14:00, 17:30
Fila 14: 2024-01-09, 08:15, 10:45, 11:00, 13:30, 14:30, 17:45
...
```

### Formato 2: Flexible

Si el archivo no tiene la estructura exacta, el sistema intentará:
1. Detectar automáticamente la fila de inicio (buscando datos en columna B)
2. Procesar todas las hojas del archivo
3. Buscar fechas en la columna B
4. Extraer horarios de cualquier columna después de B que contenga formato HH:MM

## Formatos de Fecha Soportados

- `YYYY-MM-DD` (2024-01-08)
- `DD/MM/YYYY` (08/01/2024)
- `YYYY/MM/DD` (2024/01/08)
- `DD-MM-YYYY` (08-01-2024)
- `MM/DD/YYYY` (01/08/2024)
- Números de Excel (conversión automática)

## Solución de Problemas

### "No se encontraron datos en las hojas del archivo Excel"

Posibles causas:
1. **La columna B está vacía**: Asegúrate de que la columna B contenga fechas
2. **Los horarios no tienen formato correcto**: Deben ser HH:MM (08:00, 10:30, etc.)
3. **El archivo está corrupto**: Intenta guardar el archivo nuevamente como .xlsx
4. **Las filas de datos son muy tardías**: Si los datos comienzan después de fila 30, el sistema podría no encontrarlos

### Soluciones:

1. Verifica que el archivo cumple con el Formato 1 (recomendado)
2. Abre el archivo en Excel y confirma:
   - Las fechas están en la columna B
   - Los horarios están en formato HH:MM
   - No hay caracteres especiales o espacios extra
3. Guarda como "Excel Workbook (*.xlsx)" - NO uses .xls
4. Intenta con el archivo de prueba: `uploads/HORARIO_COMPLETO.xlsx`

## Archivos de Prueba

### HORARIO_TEST.xlsx
- 3 registros de ejemplo
- Formato básico
- Bueno para verificar que el sistema funciona

### HORARIO_COMPLETO.xlsx
- 5 registros con diferentes combinaciones
- Hojas 2024 y 2025
- Incluye registros con horas parciales

## Características de Importación

✓ **Detección automática de año**: Se extrae de la fecha en el archivo
✓ **Mapeo inteligente de horas**: Si hay 6 horas, se mapean automáticamente a:
  - slot 0: Entrada
  - slot 1: Salida café
  - slot 2: Entrada café
  - slot 3: Salida comida
  - slot 4: Entrada comida
  - slot 5: Salida

✓ **UPSERT automático**: Inserta nuevos registros o actualiza existentes
✓ **Previsualización**: Ver datos antes de importar
✓ **Máximo 10MB** por archivo

## Uso en Interfaz Web

1. Ir a `https://example.com/import.php`
2. Seleccionar "Importar desde archivo Excel (.xlsx)"
3. Cargar archivo
4. Revisar previsualización
5. Confirmar importación

## Uso por Línea de Comandos

```bash
php scripts/import_horario_2024.php --user-id=1 --dry-run
php scripts/import_horario_2024.php --user-id=1
```

Ver `scripts/README_IMPORT_HORARIO.md` para más detalles.
