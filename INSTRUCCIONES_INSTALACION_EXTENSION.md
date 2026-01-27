# Instalación Correcta de Extensiones - IMPORTANTE

## ⚠️ ERROR "COMPLEMENTO DAÑADO" - SOLUCIÓN

Si al descargar las extensiones aparece "complemento dañado" o "corrupted extension", **NO es un error del ZIP**. Es un problema de instalación.

---

## 🔧 SOLUCIÓN PARA CHROME/EDGE

### Opción 1: Instalación CORRECTA ✅

1. **Descargar el ZIP** desde la aplicación
2. **NO instalar el ZIP directamente** ← AQUÍ ES EL ERROR
3. **DESCOMPRIMIRLO** en una carpeta, por ejemplo: `C:\Mis Extensiones\GestionHoras`
4. **Abrir Chrome** → `chrome://extensions/`
5. **Activar "Modo de desarrollador"** (esquina superior derecha)
6. **Clic en "Cargar extensión sin empaquetar"**
7. **Seleccionar la carpeta DESCOMPRIMIDA** (donde está manifest.json)
8. ✅ La extensión se instalará correctamente

### Opción 2: ZIP Directamente (si el navegador lo permite)

- Algunos navegadores permiten instalar el `.zip` directamente
- Si falla, usar Opción 1 (descomprimir primero)

---

## 🦊 SOLUCIÓN PARA FIREFOX

### Instalación en Firefox (Versión Normal)

1. **Descargar el ZIP** desde la aplicación
2. **Descomprimirlo** en una carpeta
3. **Escribir en barra de direcciones:** `about:debugging#/runtime/this-firefox`
4. **Clic en "Cargar complemento temporal..."**
5. **Seleccionar el archivo `manifest.json`** (dentro de la carpeta descomprimida)
6. ✅ La extensión se instalará temporalmente (válido hasta reiniciar Firefox)

### Para Instalación Permanente en Firefox

La instalación temporal es suficiente para probar. Para una instalación permanente, la extensión debe estar firmada por Mozilla.

---

## 📋 REQUISITOS ANTES DE INSTALAR

Asegúrate de que:
- ✅ El ZIP esté **COMPLETAMENTE DESCOMPRIMIDO**
- ✅ Puedas ver estos archivos en la carpeta:
  - `manifest.json`
  - `popup.html`
  - `background.js`
  - `content.js`
  - `popup.js`
  - `importFichajes.js`
  - `config.js`
  - Carpeta `images/` (con los iconos)

Si falta alguno de estos archivos, el ZIP está corrupto.

---

## ✅ VERIFICACIÓN DESPUÉS DE INSTALAR

### Chrome/Edge
- [ ] La extensión aparece en `chrome://extensions/`
- [ ] Muestra nombre: "GestionHorasTrabajo - Importador de Fichajes"
- [ ] Muestra versión: 1.2.0
- [ ] El ícono es visible
- [ ] Puedes hacer clic en el ícono para abrir el popup

### Firefox
- [ ] La extensión aparece en `about:addons`
- [ ] Muestra nombre: "GestionHorasTrabajo - Firefox"
- [ ] El ícono es visible en la barra de herramientas
- [ ] Puedes hacer clic en el ícono para abrir el popup

---

## 🐛 SI AÚN DA ERROR "DAÑADO"

1. **Descarga nuevamente el ZIP** (puede estar corrupto)
2. **Verifica que sea un archivo ZIP válido:**
   - En Windows: Click derecho → Comprobar con antivirus
   - En Mac/Linux: `unzip -t archivo.zip`
3. **Prueba a:**
   - Descomprimir en una carpeta diferente
   - Usar una herramienta diferente para descomprimir (7-Zip, WinRAR, etc.)
   - En Firefox: Usar `about:debugging` en lugar de instalar directamente

4. **Si nada funciona:**
   - Limpia la caché del navegador
   - Desinstala cualquier versión anterior de la extensión
   - Reinicia el navegador
   - Intenta de nuevo

---

## 📧 SOPORTE

Si el problema persiste después de seguir estos pasos:
1. Verifica los logs de la consola (F12 → Console)
2. Captura el error exacto que aparece
3. Contacta con soporte
