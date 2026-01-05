#!/bin/bash
# Script para crear un ZIP de la extensión Chrome

CHROME_EXT_DIR="chrome-extension"
OUTPUT_FILE="GestionHorasTrabajo-ChromeExtension.zip"

if [ ! -d "$CHROME_EXT_DIR" ]; then
    echo "Error: Carpeta $CHROME_EXT_DIR no encontrada"
    exit 1
fi

echo "Creando ZIP de la extensión..."
# Cambiar a la carpeta chrome-extension y crear ZIP con los archivos en raíz
cd "$CHROME_EXT_DIR"
zip -r "../$OUTPUT_FILE" . -x ".DS_Store" "*.git*"
cd ..

if [ -f "$OUTPUT_FILE" ]; then
    echo "✅ ZIP creado exitosamente: $OUTPUT_FILE"
    ls -lh "$OUTPUT_FILE"
else
    echo "❌ Error al crear el ZIP"
    exit 1
fi
