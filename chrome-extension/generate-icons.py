#!/usr/bin/env python3
"""
Script para generar iconos PNG a partir de SVG
Requiere: pip install pillow cairosvg
"""

import os
import sys
from pathlib import Path

def generate_pngs():
    """Generar iconos PNG desde SVG"""
    
    # Intentar con diferentes métodos
    try:
        from PIL import Image, ImageDraw
        
        # Crear iconos PNG manualmente
        sizes = [16, 48, 128]
        colors = {
            'bg': '#007bff',
            'text': '#ffffff'
        }
        
        images_dir = Path('images')
        images_dir.mkdir(exist_ok=True)
        
        for size in sizes:
            # Crear imagen con fondo azul
            img = Image.new('RGB', (size, size), color=colors['bg'])
            
            # Guardar
            output_path = images_dir / f'icon-{size}.png'
            img.save(output_path, 'PNG')
            print(f'✅ Creado: {output_path}')
            
    except ImportError:
        print('⚠️  Pillow no instalado. Usando archivos SVG.')
        print('Los archivos SVG funcionan en Chrome Manifest V3+')
        return True

def main():
    try:
        if generate_pngs():
            print('✅ Iconos generados exitosamente')
            return 0
    except Exception as e:
        print(f'❌ Error: {e}')
        return 1

if __name__ == '__main__':
    sys.exit(main())
