# Instalación de la Extensión Chrome - GestionHorasTrabajo

## ✅ INSTALACIÓN RÁPIDA (3 pasos)

### 1. Abrir configuración de extensiones
- Abre Chrome/Edge
- Escribe en la barra: `chrome://extensions/`
- Activa **"Modo de desarrollador"** (esquina superior derecha)

### 2. Cargar la extensión
- Haz clic en **"Cargar extensión sin empaquetar"**
- Navega a: `/opt/GestionHorasTrabajo/chrome-extension`
- Haz clic en **"Seleccionar carpeta"**

### 3. ¡Listo!
La extensión ahora aparecerá en tu barra de herramientas con el icono de GestionHorasTrabajo.

---

## 📋 Uso

### Capturar datos de fichajes:

1. **Visita una página HTML** con tabla de fichajes
2. **Haz clic en el icono** de la extensión en la barra
3. **Presiona "📥 Capturar datos"**
4. Revisa los datos capturados en la vista previa
5. **Presiona "🚀 Importar a GestionHorasTrabajo"**
6. ¡Los datos se importarán automáticamente!

### Configuración de URL:

Si tu aplicación NO está en `https://calendar.favala.es`:

1. Haz clic en el icono de la extensión
2. Haz clic en **"⚙️ Configuración"**
3. Cambia la URL de la aplicación
4. Haz clic en **"💾 Guardar"**

---

## 🔧 Formatos soportados

La extensión detecta automáticamente dos formatos:

### Formato EXTERNAL
Tabla HTML con ID `tabla_fichajes` y clases CSS específicas.

### Formato Estándar
Tabla HTML con columnas: Fecha, Entrada, Salida, etc.

---

## ❓ Solución de problemas

### "No se encontraron datos de fichajes"
- ✅ Verifica que estás en una página con tabla de fichajes
- ✅ Abre la consola del navegador (F12) para ver detalles
- ✅ La tabla debe tener el formato esperado

### "Error: No se pudo comunicar con la página"
- ✅ Recarga la página (F5)
- ✅ Asegúrate de NO estar en una página `chrome://` o `edge://`
- ✅ Recarga la extensión en `chrome://extensions/`

### "Error de importación"
- ✅ Verifica que la URL de la aplicación sea correcta
- ✅ Asegúrate de estar autenticado en GestionHorasTrabajo
- ✅ Abre la consola (F12) y busca errores de red

---

## 🔐 Permisos

La extensión requiere:

- **activeTab**: Para leer datos de la página actual
- **scripting**: Para ejecutar scripts de captura
- **storage**: Para guardar tu configuración de URL
- **tabs**: Para comunicarse entre popup y content script
- **host_permissions**: Para acceder a calendar.favala.es y localhost

---

## 📦 Versión actual

**v1.2.0** - Configurada para https://calendar.favala.es

## 🆘 Soporte

Si tienes problemas, abre la consola del navegador (F12) y busca mensajes con `[GestionHoras]`.
