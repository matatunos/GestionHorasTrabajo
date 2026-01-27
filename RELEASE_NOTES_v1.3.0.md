# Release v1.3.0 - Mejoras UI y Reorganización

**Fecha:** 27 de enero de 2026

## ✨ Nuevas Características

### Dashboard Rediseñado
- Colores suavizados con gradientes pastel (azules, verdes, púrpuras)
- Fondo blanco puro para mejor ergonomía visual
- Reducción de tensión ocular mediante colores menos saturados
- Diseño responsivo mejorado

### Compatibilidad Cross-Browser
- Firefox Service Worker ahora totalmente compatible
- Extensión Chrome y Firefox usan capas de abstracción de API
- Soporte para ambos namespaces: `browser.*` y `chrome.*`

## 🔧 Mejoras Técnicas

### Reorganización de Estructura
- Movimiento de herramientas utilitarias a carpeta `/tools`
- 16 archivos reorganizados por categoría:
  - **Analysis**: analyze_data_summary.php, analyze_excel.php, data_quality.php
  - **Import**: import.php, import-calendar-beta.php, auto_import.php
  - **Database**: db_add_expected_daily_hours.php, db_read_2026.php, db_set_expected_daily_hours.php
  - **Configuration/Cleanup**: fix_config_2026.php, fix_config_2026_full.php, clean_entries.php
  - **Processing**: dashboard_merged.php, chrome-addon-help.php, firefox-addon-help.php, ocr_processor.php

### Limpieza del Repositorio
- Eliminación definitiva de honeycomb-widget (no incluido en esta versión)
- Actualización de .gitignore para prevenir reintroducción
- Raíz del proyecto reducida a 30 archivos PHP esenciales

## 📊 Cambios en dashboard.php
- 927 líneas de código rediseñado
- 7 secciones principales: encabezado, alertas, resumen semanal, estadísticas anuales, calidad de datos, tabla mensual, análisis de seguridad
- Paleta de colores: #dbeafe, #dcfce7, #ede9fe, #fed7aa, #fee2e2

## 🐛 Correcciones
- Servicio worker Firefox ya no usa chrome.* APIs directamente
- Gradientes suavizados para reducir fatiga visual
- Alertas con opacidad reducida (0.06 en lugar de 0.08)
- Badges con mejor contraste

## 📦 Commits Incluidos
- a9db30e: Dashboard rediseñado con colores suaves
- ab89ee1: Herramientas utilitarias movidas a /tools
- 017d0eb: Marker file para honeycomb-widget removido
- c3f2cfb: .gitignore actualizado

## Notas de Instalación
```bash
git clone https://github.com/matatunos/GestionHorasTrabajo.git
cd GestionHorasTrabajo
git checkout v1.3.0
```

---
