# ⚡ Quick Start - App Mobile

## 🚀 Ejecutar la app en 2 minutos

### Paso 1: Navega a la carpeta
```bash
cd mobile-app
```

### Paso 2: Inicia la app
```bash
npm start
```

Verás un código QR en la terminal.

### Paso 3: Abre en iPhone
1. Abre **Expo Go** en tu iPhone (descarga gratis de App Store)
2. Presiona el icono **"Scan QR code"**
3. Escanea el código QR que aparece en la terminal

¡Listo! La app se abrirá en tu iPhone.

---

## 🔧 Configuración

**IMPORTANTE:** Antes de escanear el QR, configura tu servidor.

```bash
# Edita el archivo de configuración
nano src/config.ts
```

Cambia esta línea (está alrededor de la línea 12):
```typescript
API_URL: 'https://calendar.favala.es',  // ← Cambiar por tu servidor
```

Por ejemplo:
```typescript
API_URL: 'https://mi-servidor.com',
```

Presiona `Ctrl+O`, luego `Enter`, luego `Ctrl+X` para guardar.

---

## 🧪 Probar la app

Una vez abierta en Expo Go:

1. **Login**: 
   - Usuario: `test` (o tu usuario)
   - Contraseña: `test` (o tu contraseña)
   - Toca "Iniciar Sesión"

2. **Dashboard**:
   - Toca "Entrada" para registrar entrada
   - Toca "Salida" para registrar salida

3. **Historial**:
   - Toca la pestaña "Historial"
   - Verás todos tus fichajes

4. **Perfil**:
   - Toca la pestaña "Perfil"
   - Verás tu nombre y email
   - Toca "Cerrar Sesión" para logout

---

## ⚠️ Si algo falla

### "Cannot reach API"
```bash
# Verifica que la URL es correcta en src/config.ts
nano src/config.ts
```

Debe ser HTTPS y accesible.

### "Login failed"
Verifica que el usuario/contraseña existe en tu base de datos PHP.

### "App crashes"
Presiona `j` en la terminal (donde hiciste `npm start`) para ver los logs de error.

---

## 🛑 Detener la app

Presiona `Ctrl+C` en la terminal donde ejecutaste `npm start`.

---

## 📱 Monitorear logs en tiempo real

```bash
npm start
```

Luego presiona una de estas teclas:

- `i` - Abrir en iPhone
- `a` - Abrir en Android
- `j` - Abrir Inspector de React
- `r` - Recargar app
- `q` - Salir

---

## 🔄 Cambios en código

Si editas archivos en `src/`, la app se recargará automáticamente en tu iPhone.

Solo edita, guarda, y verás los cambios en segundos.

---

## 💡 Tips

- **Guarda la terminal abierta**: Donde corre `npm start` debe seguir abierta
- **Wifi**: Asegúrate que iPhone y PC están en la misma red
- **HTTPS**: En producción, SIEMPRE usa HTTPS (Expo lo exige)
- **Token**: Se guarda en Keychain, persiste después de cerrar app

---

## 📞 Problemas?

1. Cierra Expo Go
2. Presiona `Ctrl+C` en la terminal
3. Ejecuta de nuevo:
   ```bash
   npm start
   ```
4. Escanea el QR de nuevo

Usualmente eso lo arregla todo.

---

**¡Listo! Disfruta tu app móvil! 🚀**
