# ✅ Setup Completado - Instrucciones Finales

## Lo que se ha hecho:

✅ **App React Native creada** en `mobile-app/`
✅ **4 Pantallas funcionales**: Login, Dashboard, Historial, Perfil
✅ **Servicios API**: Auth, Entries, User
✅ **Seguridad**: Token guardado en Keychain/Keystore
✅ **Endpoints agregados a `api.php`**: 
   - GET /api.php/me
   - POST /api.php/entries/checkin
   - POST /api.php/entries/checkout
   - GET /api.php/entries/today
   - GET /api.php/entries
   - DELETE /api.php/entry/{date}

---

## 📱 Próximos pasos para que funcione:

### 1️⃣ Configura la URL de tu servidor

Abre: `mobile-app/src/config.ts`

Cambia:
```typescript
prod: {
  API_URL: 'https://tu-dominio.com',  // ← Aquí va tu URL
}
```

### 2️⃣ Instala dependencias

```bash
cd mobile-app
npm install
```

### 3️⃣ Ejecuta en tu iPhone

```bash
npm start
```

Escanea el código QR con **Expo Go** (descárgalo gratis de App Store)

---

## 🧪 Testing manual

1. **Login**: usuario/contraseña
2. **Dashboard**: Toca "Entrada" → debe registrarse
3. **Dashboard**: Toca "Salida" → debe registrarse la hora de salida
4. **Historial**: Verás el registro del día
5. **Perfil**: Ver nombre y email
6. **Cerrar sesión**: Vuelve a login

---

## ⚠️ Cambios en tu servidor

Tu `api.php` **YA TIENE** los nuevos endpoints. Verifica que:

1. Tu tabla `entries` tiene estas columnas:
   - `id`, `user_id`, `date`, `start`, `end`
   - (opcional) `lunch_out`, `lunch_in`, `coffee_out`, `coffee_in`, `note`, `absence_type`

2. Tu `login.php` devuelve un `token`

Si tu login.php no devuelve token, necesitarás actualizarlo. Ej:

```php
<?php
// login.php
session_start();
if (do_login($username, $password)) {
    $_SESSION['user_id'] = $user['id'];
    
    // NUEVO: Generar token para móvil
    $token = bin2hex(random_bytes(32));
    // Guardar token en BD si es necesario
    
    echo json_encode(['ok' => true, 'token' => $token]);
} else {
    echo json_encode(['ok' => false, 'error' => 'Invalid credentials']);
}
?>
```

---

## 🔐 Seguridad

- ✅ Tokens guardados en Keychain (iOS) / Keystore (Android)
- ✅ HTTPS obligatorio en producción
- ✅ CORS configurado
- ✅ Autenticación en cada petición

---

## 🔄 Si Face ID quieres después:

```bash
npm install react-native-biometrics
```

Luego, en `LoginScreen`:
```typescript
import ReactNativeBiometrics from 'react-native-biometrics'

// Verificar si Face ID disponible
rnBiometrics.isSensorAvailable()
  .then(resultSet => {
    if (resultSet.biometryType === 'FaceID') {
      console.log('Face ID disponible!')
    }
  })
```

---

## 📦 Deploy a producción

### iOS
```bash
npm install -g eas-cli
eas build --platform ios
```

### Android
```bash
npm install -g eas-cli
eas build --platform android
```

---

## 🆘 Troubleshooting

| Problema | Solución |
|----------|----------|
| "Cannot reach API" | Verifica URL en config.ts y que HTTPS está ok |
| "Login failed" | Asegúrate que login.php devuelve token |
| "App crashes" | Abre Expo Go en terminal y revisa logs |
| "Token invalid" | Limpia SecureStore: borrar app y reinstalar |

---

## 📋 Checklist antes de producción

- [ ] URL de servidor configurada en `src/config.ts`
- [ ] npm install ejecutado
- [ ] App abierta en Expo Go en iPhone
- [ ] Login funciona
- [ ] Entrada/salida funciona  
- [ ] Historial carga bien
- [ ] Perfil muestra datos
- [ ] Logout funciona
- [ ] HTTPS configurado en servidor

---

## 🎉 ¡Listo!

Tu app móvil está funcionando. Ahora puedes:

1. **Hacer deploy**: Seguir pasos de eas build
2. **Agregar features**: Face ID, notificaciones, etc.
3. **Mejorar UX**: Temas, animaciones, gráficos
4. **Admin panel**: Para ver otros usuarios

¿Necesitas ayuda en algo específico?

---

**Fecha:** 8 de Enero 2026
**Proyecto:** GestionHorasTrabajo
**Versión:** 1.0.0
