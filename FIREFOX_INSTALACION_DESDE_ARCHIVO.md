# ⚠️ FIREFOX: Instalación Desde Archivo - GUÍA ESPECÍFICA

## El Problema

Si intentas "Instalar desde archivo" en Firefox y buscas un `.zip`, Firefox dirá "**complemento dañado**" porque:

**Firefox NO acepta archivos `.zip` directamente con "Instalar desde archivo"**

Firefox solo acepta:
- ❌ Archivos `.zip` sin firmar → **ERROR "dañado"**
- ❌ Archivos `.xpi` sin firmar → **ERROR "dañado"**
- ✅ Archivos `.xpi` **firmados por Mozilla** → OK
- ✅ Carpetas descomprimidas con `manifest.json` → **MEJOR OPCIÓN**

---

## ✅ SOLUCIÓN: Instalación Correcta para Firefox

### OPCIÓN 1: Cargar desde Carpeta (RECOMENDADO - 100% Funciona)

**Esto es lo que debes hacer:**

1. **Descargar el ZIP** desde la aplicación
   - URL: `/download-firefox-addon.php`

2. **Descomprimir el ZIP** en una carpeta
   - Ejemplo: `C:\Mis Extensiones\GestionHoras-Firefox`

3. **Abrir Firefox** y escribir en la barra de direcciones:
   ```
   about:debugging#/runtime/this-firefox
   ```

4. **Clic en "Cargar complemento temporal"**
   
   ![Paso 4 imagen]

5. **Seleccionar el archivo `manifest.json`** dentro de la carpeta descomprimida
   - Ruta: `C:\Mis Extensiones\GestionHoras-Firefox\manifest.json`

6. ✅ **¡Listo!** La extensión se cargará inmediatamente

---

### OPCIÓN 2: Instalar desde Archivo (No Recomendado - Requiere Firma)

Si insistes en usar "Instalar desde archivo":

1. Solo funciona con archivos `.xpi` **firmados por Mozilla**
2. Nuestro `.xpi` no está firmado (solo para desarrollo local)
3. Para distribución en producción, necesitaría:
   - Cuenta Mozilla Developer
   - Proceso de firma con AMO (Mozilla Add-ons)
   - Revisión de código

**Para desarrollo local: Usa Opción 1 (Cargar desde carpeta)**

---

## 📋 Tabla Comparativa: Métodos de Instalación en Firefox

| Método | Tipo de Archivo | Requiere Firma | Funciona Offline | Dificultad |
|--------|-----------------|----------------|------------------|-----------|
| Carpeta descomprimida | Carpeta | ❌ No | ✅ Sí | ⭐ Muy fácil |
| Instalar desde archivo | .xpi sin firmar | ❌ No | ❌ No | ⭐⭐⭐ Difícil |
| Firefox Add-ons Store | .xpi firmado | ✅ Sí | ✅ Sí | ⭐⭐⭐⭐ Muy difícil |

---

## 🔍 ¿Por Qué da Error "Complemento Dañado"?

Firefox rechaza el archivo porque:

```
[Intento de instalar]
    ↓
[Firefox busca firma de Mozilla]
    ↓
[No encuentra firma]
    ↓
[Rechaza como "dañado"]
    ↓
❌ Error
```

**Esto NO significa que el archivo esté corrupto.** 
Solo que Firefox requiere firma para instalar desde archivo.

---

## ✅ Procedimiento Paso a Paso (Opción 1)

### Paso 1: Descargar
```
Entra en la aplicación GestionHorasTrabajo
→ Extensiones
→ Descargar Extensión Firefox
→ Guardar el archivo: gestionhoras-firefox-extension.zip
```

### Paso 2: Descomprimir
```
Windows:
  Click derecho en ZIP
  → Extraer todo
  → Selecciona carpeta de destino

Mac:
  Doble click en ZIP (se descomprime automáticamente)

Linux:
  unzip gestionhoras-firefox-extension.zip
```

### Paso 3: Abrir about:debugging
```
Firefox
→ Barra de direcciones
→ Escribir: about:debugging#/runtime/this-firefox
→ Presionar Enter
```

### Paso 4: Cargar complemento temporal
```
about:debugging
→ Clic en "Cargar complemento temporal..."
→ Navegar a carpeta descomprimida
→ Seleccionar manifest.json
→ Abrir
```

### Paso 5: Verificar
```
✅ Debe aparecer en about:addons
✅ El icono debe estar visible en la barra de herramientas
✅ Puede hacer clic y abrirse el popup
```

---

## ⚠️ Si Sigue Dando Error

### 1. Verificar que descomprimiste correctamente
```bash
# Linux/Mac - verifica que manifest.json esté en la raíz
ls -la carpeta-descomprimida/manifest.json

# Debe existir el archivo
```

### 2. Verificar permisos
```bash
# Linux/Mac - dar permisos de lectura
chmod -R 755 carpeta-descomprimida
```

### 3. Limpiar caché
```
Firefox
→ Preferences → Privacy
→ "Borrar datos"
→ Cerrar y reiniciar Firefox
→ Intentar de nuevo
```

### 4. Usar archivo .xpi
```bash
# Si tienes el archivo firefox-extension.xpi:
1. about:debugging#/runtime/this-firefox
2. Clic "Cargar complemento temporal..."
3. Selecciona firefox-extension.xpi
# (Funcionará igual que la carpeta)
```

---

## 📚 Diferencias: Chrome vs Firefox

| Aspecto | Chrome | Firefox |
|---------|--------|---------|
| Instalar desde ZIP | ✅ Sí (descomprimido) | ✅ Sí (descomprimido) |
| Instalar desde .zip | ❌ No | ❌ No |
| Instalar desde .crx | ✅ Sí (firmado) | ❌ No |
| Instalar desde .xpi | ❌ No | ✅ Sí (solo si está firmado) |
| Cargar carpeta temporal | ✅ Sí (chrome://extensions/) | ✅ Sí (about:debugging) |
| Sin firmar | ✅ Permite | ❌ No permite (de archivo) |

---

## 🚀 Resumen Final

**Para Firefox, siempre:**
1. ✅ Descarga el `.zip`
2. ✅ Descomprime en una carpeta
3. ✅ Abre `about:debugging#/runtime/this-firefox`
4. ✅ Carga el `manifest.json` desde la carpeta

**Eso es. Nada de "instalar desde archivo" con ZIP.**

Si necesitas firmar la extensión para distribución en producción, necesitarías:
- Mozilla Developer Account
- Pasar proceso de review
- Firma oficial de Mozilla

Pero para **desarrollo y pruebas locales**: La Opción 1 funciona perfectamente.

---

## 📞 Soporte

Si sigue sin funcionar:
1. Verifica que manifest.json esté en la raíz de la carpeta
2. Verifica que haya un archivo manifest.json (no carpeta)
3. Revisa la consola (F12) para mensajes de error
4. Intenta con el archivo .xpi en lugar de la carpeta
