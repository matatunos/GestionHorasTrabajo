# Sistema Híbrido de Autenticación: Sesión + Token

## Resumen

La extensión Chrome ahora soporta **dos métodos de autenticación**:

1. **Sesión** - Para usuarios logueados en el navegador (más seguro)
2. **Token** - Para usuarios que descargan la extensión (más conveniente, 7 días)

## ¿Cómo funciona?

### Flujo 1: Sesión (usuario logueado en navegador)

```
1. Usuario hace login en GestionHorasTrabajo
2. Obtiene cookie: PHPSESSID=xyz789
3. Abre página de fichajes
4. Haz clic en extensión → POST /api.php
5. Chrome envía: Cookie: PHPSESSID=xyz789
6. Servidor valida sesión ✓
7. Datos se guardan con user_id del usuario
```

**Ventaja:** Máxima seguridad, expira cuando se desloguea

**Desventaja:** Requiere estar logueado en el navegador

### Flujo 2: Token (usuario descarga extensión)

```
1. Usuario va a profile.php
2. Presiona "Descargar extensión"
3. Sistema genera TOKEN único: "abc123def456..."
4. TOKEN se inyecta en config.js del ZIP
5. Usuario descarga ZIP e instala extensión
6. Extensión se usa: POST /api.php
7. Incluye: { entries: [...], token: "abc123def456..." }
8. Servidor valida token ✓
9. Datos se guardan con user_id asociado al token
```

**Ventaja:** Funciona sin estar logueado, por 7 días

**Desventaja:** Token expira, responsabilidad del usuario no compartirlo

## Autenticación en api.php

```php
// 1. Intentar sesión
if (!empty($_SESSION['user_id'])) {
  $user = get_current_user();  // ← Sesión válida
}

// 2. Si no hay sesión, intentar token
else if ($input['token']) {
  $user_id = validate_extension_token($input['token']);
  $user = get_user_by_id($user_id);  // ← Token válido
}

// 3. Si no hay sesión NI token
else {
  return 401 UNAUTHORIZED;
}
```

## Seguridad del Token

### Validación en servidor

```sql
SELECT user_id FROM extension_tokens
WHERE 
  token = 'provided_token' 
  AND expires_at > NOW()  -- ← Validar expiración
  AND revoked_at IS NULL  -- ← Validar no revocado
```

### Características

- ✅ **HTTPS obligatorio** - API rechaza HTTP (excepto localhost)
- ✅ **Tokens únicos** - Cada descarga genera uno nuevo
- ✅ **Expiración automática** - 7 días por defecto
- ✅ **Revocación manual** - Usuario puede revocar en extension-tokens.php
- ✅ **Registro de uso** - Se actualiza last_used_at en cada uso
- ✅ **Responsabilidad del usuario** - No compartir token es su responsabilidad

### ¿Y si el token se expone?

**Escenario:** Usuario comparte accidentalmente token con alguien

```
Token expuesto: "abc123def456..."
Tercero intenta usar: POST /api.php { token: "abc123def456..." }

Validación en servidor:
1. ¿Token válido? ✓
2. ¿No expirado? ✓ (7 días)
3. ¿No revocado? ✓
→ Autenticado como usuario_original

RESULTADO: Tercero PUEDE importar datos al usuario original
```

**Mitigation:**
1. **HTTPS obligatorio** - Protege token en tránsito
2. **Validación en servidor** - Token debe ser válido/activo
3. **Usuario revoca token** - Inmediatamente en extension-tokens.php
4. **Expiración automática** - 7 días máximo
5. **Responsabilidad del usuario** - No es problema técnico sino de cuidado

**Recomendación:** Si sospecha compromiso, usuario entra a extension-tokens.php y revoca.

## Tabla de tokens

```sql
CREATE TABLE extension_tokens (
  id INT PRIMARY KEY AUTO_INCREMENT,
  user_id INT NOT NULL,                      -- Propietario del token
  token VARCHAR(64) UNIQUE NOT NULL,         -- Token aleatorio
  name VARCHAR(255),                         -- Ej: "Laptop Juan - 2024"
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  expires_at TIMESTAMP NOT NULL,             -- 7 días desde creación
  last_used_at TIMESTAMP NULL,               -- Actualizado con cada uso
  revoked_at TIMESTAMP NULL,                 -- NULL si activo, fecha si revocado
  revoke_reason VARCHAR(255),                -- Ej: "User revoked"
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_valid_tokens (token, expires_at),
  INDEX idx_user_valid (user_id, expires_at, revoked_at)
);
```

## Funciones en lib.php

### generate_extension_token()
```php
$token = generate_extension_token();
// Retorna: "a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0u1v2w3x4y5z6"
```

### create_extension_token()
```php
$result = create_extension_token($user_id, "Mi extensión", 7);
// Retorna: [
//   'token' => '...',
//   'expires_at' => '2024-01-12',
//   'name' => 'Mi extensión'
// ]
```

### validate_extension_token()
```php
$user_id = validate_extension_token($token);
// Retorna: 123 (si válido) o null (si inválido/expirado)
// SIDE EFFECT: Actualiza last_used_at
```

### get_user_extension_tokens()
```php
$tokens = get_user_extension_tokens($user_id);
// Retorna: Array de tokens con status is_active
```

### revoke_extension_token()
```php
revoke_extension_token($token_id, $user_id, "User revoked");
// Establece revoked_at = NOW()
```

## Página extension-tokens.php

**Ubicación:** /extension-tokens.php

**Qué muestra:**
- Lista de todos los tokens del usuario
- Para cada token:
  - Nombre: "Chrome Extension - 2024-01-05 15:30"
  - Creado: "05/01/2024 15:30"
  - Expira: "12/01/2024" (7 días)
  - Último uso: "05/01/2024 17:15" (o "Nunca" si no se ha usado)
  - Estado: "✓ Activo" o "✗ Inactivo"
  - Botón: "Revocar" (si activo)

**Acciones:**
- Revocar token: Lo hace inactivo inmediatamente
- Crear nuevo token: Descargar extensión desde perfil

**Seguridad:**
- Solo el usuario propietario puede ver/revocar sus tokens
- No se muestra el token en texto plano (por seguridad)
- Solo se ve en el ZIP al descargar

## Flujo de descarga

```
1. Usuario en profile.php
2. Presiona "📥 Descargar extensión"
3. download-addon.php:
   a) Verifica HTTPS (rechaza HTTP en producción)
   b) Genera token: create_extension_token()
   c) Inyecta token en config.js
   d) Empaqueta ZIP con archivos + config.js
   e) Envia ZIP al navegador
   f) Registra en logs
4. Usuario descarga GestionHorasTrabajo-ChromeExtension.zip
5. Extrae ZIP
6. En Chrome: chrome://extensions
7. Carga carpeta descomprimida
8. Extensión lista (con token + URL preconfigurados)
```

## Background.js: Envío de token

```javascript
// Si existe EXTENSION_TOKEN, lo incluye en el payload
const body = { entries: entries };

if (typeof EXTENSION_TOKEN !== 'undefined' && EXTENSION_TOKEN) {
  body.token = EXTENSION_TOKEN;
}

fetch('/api.php', {
  method: 'POST',
  credentials: 'include',    // Envía cookie de sesión si existe
  body: JSON.stringify(body)  // Incluye token en JSON
})
```

**Prioridad de autenticación:**
1. Si existe sesión válida → usa sesión
2. Sino, si existe token válido → usa token
3. Sino → rechaza (401)

## Casos de uso

### Caso 1: Usuario logueado en navegador
```
Estado: Sesión activa (PHPSESSID válida)
Comportamiento: Usa sesión para importar
Duración: Mientras sesión esté activa
```

### Caso 2: Usuario cerró sesión pero tiene extensión
```
Estado: No hay sesión, pero token válido (< 7 días)
Comportamiento: Usa token para importar
Duración: Hasta que token expire (7 días)
```

### Caso 3: Token expirado
```
Estado: Sesión ausente, token expirado (> 7 días)
Comportamiento: ❌ Rechaza con 401
Acción: Usuario debe descargar extensión nueva
```

### Caso 4: Token revocado manualmente
```
Estado: Usuario revocó token en extension-tokens.php
Comportamiento: ❌ Rechaza inmediatamente
Acción: Usuario debe descargar extensión nueva
```

## Cambios en la extensión

### config.js (antes: solo URL)
```javascript
// AHORA INCLUYE:
const DEFAULT_APP_URL = 'http://192.168.1.100';
const EXTENSION_TOKEN = 'a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6...';
```

### background.js
```javascript
// ANTES:
fetch(appUrl + '/index.php', { data })

// AHORA:
fetch(appUrl + '/api.php', {
  data: { entries, token: EXTENSION_TOKEN }
})
```

## Resumen de seguridad

| Aspecto | Implementación |
|---------|-----------------|
| **Transporte** | HTTPS obligatorio (rechaza HTTP) |
| **Generación de token** | random_bytes(32) = 64 caracteres |
| **Almacenamiento** | BD con UNIQUE constraint |
| **Validación** | Sesión o Token válido + no expirado + no revocado |
| **Expiración** | 7 días automático |
| **Revocación** | Manual en extension-tokens.php |
| **Registro** | last_used_at + logs de descarga |
| **Responsabilidad** | Usuario no comparte token (documentado) |

## Próximas mejoras posibles

- [ ] Rate limiting por token
- [ ] IP whitelist (opcional)
- [ ] Refresh tokens
- [ ] Scopes limitados (ej: solo lectura)
- [ ] Expiration customizable
- [ ] Token rotation automático
