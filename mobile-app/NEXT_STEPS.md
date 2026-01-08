# App React Native - Próximos pasos

## ✅ Completado

- [x] Estructura base React Native con Expo
- [x] Pantalla de Login
- [x] Pantalla Dashboard (entrada/salida)
- [x] Pantalla Historial
- [x] Pantalla Perfil
- [x] Servicios (AuthService, EntriesService)
- [x] Endpoints API documentados
- [x] Almacenamiento seguro de tokens (SecureStore)

---

## 📋 TODO Antes de usar la app

### 1. **Actualizar tu `api.php`**
   - Copiar contenido de `mobile-app/API_ENDPOINTS.php`
   - Pegarlos al final de tu `api.php` actual
   - Los endpoints necesarios son:
     - `POST /api.php/entries/checkin`
     - `POST /api.php/entries/checkout`
     - `GET /api.php/entries/today`
     - `GET /api.php/entries`
     - `GET /api.php/me`
     - `DELETE /api.php/entries/{id}`

### 2. **Configurar base de datos**
   ```php
   // Asegurate que tu tabla `fichajes` tiene:
   - id (INT PRIMARY KEY)
   - user_id (INT)
   - date (DATE)
   - entrada (TIME)
   - salida (TIME NULL)
   - created_at (TIMESTAMP)
   - updated_at (TIMESTAMP NULL)
   ```

### 3. **Configurar la URL del servidor**
   En `mobile-app/src/services/authService.ts` y `entriesService.ts`:
   ```typescript
   const API_BASE_URL = 'https://tu-servidor.com'; // ← Cambiar esto
   ```

### 4. **Instalar dependencias**
   ```bash
   cd mobile-app
   npm install
   ```

### 5. **Ejecutar en desarrollo**
   ```bash
   npm start
   # Abrir en iPhone con Expo Go
   ```

---

## 🚀 Funcionalidades para después

### Fase 2 - Biometría
- [ ] Agregar Face ID / Touch ID
- [ ] Opción "Recordar dispositivo" (auth local)
- [ ] Pantalla de configuración de biometría

### Fase 3 - UX Mejorada
- [ ] Notificaciones push (entrada/salida)
- [ ] Modo offline con sincronización
- [ ] Tema oscuro
- [ ] Animaciones
- [ ] Gráficos de horas semanales

### Fase 4 - Admin Features
- [ ] Ver a otros usuarios (si es admin)
- [ ] Editar fichajes
- [ ] Reportes

---

## 📱 Build para producción

### iOS (TestFlight/App Store)
```bash
npm install -g eas-cli
eas build --platform ios --auto-submit
```

### Android (Google Play)
```bash
eas build --platform android
```

---

## Estructura de directorio final

```
GestionHorasTrabajo/
├── api.php (con nuevos endpoints agregados)
├── db.php
├── auth.php
├── ... (archivos PHP existentes)
│
└── mobile-app/          ← Nueva carpeta
    ├── src/
    │   ├── screens/
    │   ├── services/
    │   └── context/
    ├── App.tsx
    ├── app.json
    ├── package.json
    └── README.md
```

---

## Notas importantes

- 🔐 **Seguridad**: Los tokens se guardan en Keychain (iOS) / Keystore (Android), no en localStorage
- 🌐 **CORS**: Asegúrate que tu PHP devuelve headers CORS correctos para origen de app
- 📡 **HTTPS**: En producción, SIEMPRE usar HTTPS (Expo rechaza HTTP excepto localhost)
- 🔄 **Sesiones**: La app usa JWT/Token, no cookies de sesión

