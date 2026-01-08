# Guía React Native - GestionHorasTrabajo Mobile

## 🚀 Setup Inicial

### 1. Instalar dependencias

```bash
cd mobile-app
npm install
# o con yarn
yarn install
```

### 2. Configurar la URL del servidor

Abre `src/config.ts` y configura tu URL:

```typescript
const ENV = {
  dev: {
    API_URL: 'http://localhost:8000',  // Para desarrollo local
  },
  staging: {
    API_URL: 'https://staging.tu-dominio.com',
  },
  prod: {
    API_URL: 'https://tu-dominio.com',  // ← Cambiar por tu servidor
  },
};

const CURRENT_ENV = 'prod'; // Cambiar a 'dev' si estás desarrollando
```

### 3. Ejecutar en desarrollo

```bash
npm start
```

Esto abrirá un código QR. Escanéalo con tu iPhone usando **Expo Go** (app gratuita en App Store).

---

## 📁 Estructura del Proyecto

```
mobile-app/
├── src/
│   ├── screens/           # Pantallas de la app
│   │   ├── LoginScreen.tsx       - Login con usuario/contraseña
│   │   ├── DashboardScreen.tsx   - Entrada/salida de hoy
│   │   ├── HistoryScreen.tsx     - Historial de fichajes
│   │   └── ProfileScreen.tsx     - Perfil del usuario
│   ├── services/          # Servicios (API, autenticación)
│   │   ├── authService.ts         - Login y manejo de tokens
│   │   ├── entriesService.ts      - Operaciones de fichajes
│   │   └── userService.ts         - Datos del usuario
│   ├── context/           # React Context (estado global)
│   │   └── AuthContext.ts
│   ├── config.ts          # Configuración de URLs
│   └── components/        # Componentes reutilizables
├── App.tsx                # Punto de entrada
├── app.json               # Configuración de Expo
├── package.json
└── README.md
```

---

## 📱 Pantallas

### 🔐 LoginScreen
- Login con usuario/contraseña
- Guarda token de forma segura en Keychain (iOS) / Keystore (Android)

### 📊 DashboardScreen (Hoy)
- Muestra entrada/salida del día
- Botones para registrar entrada/salida
- Muestra horas trabajadas
- Pull-to-refresh para actualizar

### 📋 HistoryScreen (Historial)
- Listado de últimos fichajes
- Duración de cada jornada
- Pull-to-refresh
- Paginación automática

### 👤 ProfileScreen (Perfil)
- Muestra nombre y email del usuario
- Botón cerrar sesión

---

## 🔌 Endpoints de API necesarios en tu `api.php`

Tu `api.php` **YA TIENE** los endpoints necesarios. Se agregaron automáticamente:

```php
GET /api.php/me
POST /api.php/entries/checkin
POST /api.php/entries/checkout  
GET /api.php/entries/today
GET /api.php/entries?limit=30&offset=0
DELETE /api.php/entry/{date}
```

---

## 🔐 Flujo de autenticación

1. **Login**: Usuario introduce usuario/contraseña
2. **Token**: Se obtiene un token JWT del servidor
3. **Almacenamiento**: Token se guarda en **Secure Store** (Keychain en iOS)
4. **Sesión**: Token se envía en cada petición a la API
5. **Logout**: Token se elimina de Secure Store

```
Login → Token → SecureStore → API requests → Logout
```

---

## 🔑 Variables de entorno (Opcional)

Si necesitas diferentes URLs por entorno:

```bash
# En tu terminal al ejecutar Expo
EXPO_PUBLIC_API_URL=https://mi-servidor.com npm start
```

---

## 📦 Build para producción

### iOS (TestFlight/App Store)

```bash
npm install -g eas-cli
eas login
eas build --platform ios
```

Luego sube el archivo `.ipa` a TestFlight o App Store Connect.

### Android (Google Play)

```bash
npm install -g eas-cli
eas login
eas build --platform android
```

Luego sube el `.aab` a Google Play Console.

---

## 🐛 Debugging

### Ver logs en tiempo real
```bash
npm start
# Presiona 'j' para abrir inspector de React
# Presiona 'i' para iOS o 'a' para Android
```

### Problemas comunes

**"Cannot connect to API"**
- Verifica que `API_URL` en `src/config.ts` es correcta
- Asegúrate que el servidor está corriendo
- En iOS, HTTPS es obligatorio en producción

**"Login failed"**
- Verifica que tu usuario/contraseña existen en la BD
- Revisa que `login.php` devuelve un token

**"Token expired"**
- El token se guarda localmente, pero puede expirar
- Implementar refresh token (TODO en Fase 2)

---

## 📝 Próximas mejoras (Roadmap)

### Fase 2 - Biometría ✨
- [ ] Face ID (iOS)
- [ ] Touch ID (iOS)
- [ ] Biometría Android
- [ ] Opción "Recordar dispositivo"

### Fase 3 - UX Mejorada
- [ ] Notificaciones push (entrada/salida)
- [ ] Modo offline con sincronización
- [ ] Tema oscuro
- [ ] Animaciones suaves
- [ ] Gráficos de horas semanales

### Fase 4 - Admin Features  
- [ ] Ver otros usuarios (si es admin)
- [ ] Editar fichajes
- [ ] Reportes y estadísticas
- [ ] Exportar CSV

---

## 🔗 Recursos útiles

- [React Native Docs](https://reactnative.dev/)
- [Expo Docs](https://docs.expo.io/)
- [React Navigation](https://reactnavigation.org/)
- [Axios Documentation](https://axios-http.com/)

---

## 📞 Soporte

Si encuentras problemas:

1. Revisa los logs en la consola
2. Comprueba que tu servidor está accesible
3. Verifica la configuración en `src/config.ts`
4. Asegúrate que los endpoints en `api.php` existen

---

**Última actualización:** 8 de Enero 2026

