# Cómo la Extensión sabe a qué usuario inyectar datos

## Resumen rápido

```
1. Usuario hace LOGIN en GestionHorasTrabajo
   ↓
2. PHP crea SESIÓN con user_id
   ↓
3. Navegador guarda COOKIE con session_id
   ↓
4. Extensión hace POST /api.php
   ↓
5. Chrome envía AUTOMÁTICAMENTE la cookie
   ↓
6. PHP lee session_id de la cookie
   ↓
7. PHP obtiene user_id de esa sesión
   ↓
8. Datos se guardan con ese user_id
```

## Diagrama completo

```
┌─────────────────────────────────────────────────────────────┐
│ NAVEGADOR (Chrome)                                          │
├─────────────────────────────────────────────────────────────┤
│                                                               │
│  LOGIN: Usuario + Contraseña                                │
│    │                                                         │
│    └──> POST /login.php                                     │
│           ↓                                                  │
│           └──> Servidor valida contraseña                  │
│               ✅ Contraseña correcta                       │
│               └──> SESSION CREADA                          │
│                   $_SESSION['user_id'] = 123               │
│                                                              │
│    ← Respuesta + Set-Cookie: PHPSESSID=abc123              │
│                                                              │
│    🍪 Chrome GUARDA esta cookie                            │
│                                                              │
│  AHORA: Usuario ya autenticado                            │
│    └──> Abre página de fichajes                            │
│                                                              │
│                                                              │
│  EXTENSIÓN: Usuario presiona "Importar"                    │
│    │                                                         │
│    └──> POST /api.php (datos JSON)                         │
│        + Cookie: PHPSESSID=abc123 (AUTOMÁTICA ⭐)         │
│           ↓                                                  │
│           └──> Servidor recibe request                      │
│               session_start()                              │
│               $_SESSION['user_id'] = 123 (del PHPSESSID)  │
│               ✅ Usuario identificado                      │
│               └──> INSERT ... user_id=123                 │
│                                                              │
│    ← Respuesta: {"ok": true, "imported": 5}               │
│                                                              │
└─────────────────────────────────────────────────────────────┘
```

## ¿Cómo funciona técnicamente?

### 1. Login tradicional (primera vez)

```php
// login.php
if (do_login($username, $password)) {
    $_SESSION['user_id'] = 123;  // Guardar en sesión
    header('Location: index.php');  // Redirigir
}
```

PHP automaticamente:
- Crea archivo de sesión: `/tmp/sess_abc123`
- Contiene: `user_id|i:123;`
- Envía header: `Set-Cookie: PHPSESSID=abc123; path=/; httponly`

### 2. Browser guarda cookie

Chrome automáticamente almacena:
```
Dominio: localhost (o tu servidor)
Cookie: PHPSESSID=abc123
```

### 3. Extensión envía request

```javascript
// background.js
fetch(appUrl + '/api.php', {
  method: 'POST',
  credentials: 'include',  // ⭐ INCLUYE COOKIES AUTOMÁTICAMENTE
  body: JSON.stringify({ entries: [...] })
})
```

Chrome envía:
```
POST /api.php HTTP/1.1
Host: localhost
Cookie: PHPSESSID=abc123
Content-Type: application/json

{"entries": [...]}
```

### 4. Servidor identifica usuario

```php
// api.php
session_start();  // Lee PHPSESSID de la cookie
// Sesión se restaura desde /tmp/sess_abc123
// $_SESSION['user_id'] = 123

require_login();  // Verifica que existe sesión
$user = get_current_user();  // Obtiene user_id=123 de la sesión

// Insertar con user_id actual
$stmt->execute([$user['id'], $date, ...]);
```

## Código real en la aplicación

### auth.php - Funciones de autenticación

```php
<?php
session_start();  // ← Lee PHPSESSID automáticamente

function current_user() {
    // Si no hay sesión, devuelve null
    if (empty($_SESSION['user_id'])) 
        return null;
    
    // Busca el usuario en BD
    $pdo = get_pdo();
    $stmt = $pdo->prepare('SELECT id, username FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);  // ← Usa user_id de la sesión
    return $stmt->fetch();
}

function require_login() {
    // Si no hay usuario autenticado, rechaza
    if (!current_user()) {
        http_response_code(401);
        exit;
    }
}
```

### api.php - Endpoint de la extensión

```php
<?php
require_once '/auth.php';
require_login();  // ← Verifica que hay sesión válida

$user = get_current_user();  // ← Obtiene user_id de la sesión

foreach ($input['entries'] as $entry) {
    $stmt = $pdo->prepare(
        'INSERT INTO entries (user_id, date, start, end, ...) 
         VALUES (?, ?, ?, ?, ...)'
    );
    $stmt->execute([
        $user['id'],   // ← Aquí va el user_id de la sesión
        $entry['date'],
        $entry['start'],
        ...
    ]);
}
```

## ¿Es seguro?

### ✅ SÍ, por estas razones:

1. **Sesión basada en servidor**
   - El session_id es solo un identificador aleatorio
   - Los datos reales están en el servidor `/tmp/sess_*`
   - No se puede falsificar sin acceso al servidor

2. **HttpOnly cookie**
   - La cookie `PHPSESSID` tiene flag `httponly`
   - JavaScript NO puede leerla (protege contra XSS)
   - Solo se envía en requests HTTP

3. **Validación en servidor**
   - Cada request valida que la sesión exista
   - Cada operación verifica `$user['id']`
   - No confía en datos del cliente

4. **HTTPS en producción** (opcional pero recomendado)
   - Encripta la cookie en tránsito
   - `Secure` flag previene envío por HTTP

### ❌ Lo que NO puede pasar:

- ❌ La extensión NO puede inyectar datos a otro usuario
  - Si la sesión es del Usuario A, siempre se guardan con user_id_A
  
- ❌ La extensión NO necesita conocer contraseña
  - La sesión ya prueba autenticación
  
- ❌ No se puede falsificar la cookie
  - Cada navegador tiene sus propias cookies
  - No se pueden compartir entre navegadores

## Ejemplo real

### Usuario 1 (ID=123)
```
1. Hace login en localhost
2. Obtiene: Cookie: PHPSESSID=abc123
3. Importa fichajes
4. POST /api.php + Cookie: PHPSESSID=abc123
5. Servidor: $_SESSION['user_id'] = 123
6. Datos se guardan con user_id=123
```

### Usuario 2 (ID=456)
```
1. Hace login en otra sesión/pestaña
2. Obtiene: Cookie: PHPSESSID=xyz789
3. Importa fichajes
4. POST /api.php + Cookie: PHPSESSID=xyz789
5. Servidor: $_SESSION['user_id'] = 456
6. Datos se guardan con user_id=456
```

**Los datos de Usuario 1 y Usuario 2 NUNCA se mezclan** porque cada uno tiene su propia sesión.

## Flujo completo visual

```
┌─────────────┐
│ Usuario 1   │
│             │
│ Abre login  │
└──────┬──────┘
       │
       v
   ┌───────────────┐
   │ POST login.php│
   │ user:pass     │
   └───────┬───────┘
           │
           v
    ┌──────────────────────────┐
    │ Servidor PHP             │
    │ ✓ Valida contraseña      │
    │ $_SESSION['user_id']=123 │
    │ Crea: /tmp/sess_abc123   │
    │ Envía: Set-Cookie        │
    └──────┬───────────────────┘
           │
           v
    Chrome almacena:
    PHPSESSID=abc123
           │
           v
    ┌──────────────────────────┐
    │ Usuario ve página        │
    │ Presiona "Importar"      │
    └──────┬───────────────────┘
           │
           v
    ┌──────────────────────────┐
    │ POST /api.php            │
    │ Cookie: PHPSESSID=abc123 │
    │ (AUTOMÁTICO)             │
    └──────┬───────────────────┘
           │
           v
    ┌──────────────────────────┐
    │ Servidor recibe:         │
    │ session_start()          │
    │ Lee /tmp/sess_abc123     │
    │ $_SESSION['user_id']=123 │
    │ ✓ Válido                 │
    │                          │
    │ INSERT entries ...       │
    │ user_id=123              │
    └──────┬───────────────────┘
           │
           v
    ✅ Datos guardados con user_id=123
```

## Resumen de seguridad

| Aspecto | Cómo se protege |
|---------|-----------------|
| **Identificación** | Session ID en cookie |
| **Verificación** | require_login() valida sesión |
| **user_id** | Se obtiene de $_SESSION, no del cliente |
| **Falsificación** | Session ID es aleatorio, no se puede adivinar |
| **XSS** | Cookie es HttpOnly, JavaScript no puede leerla |
| **CSRF** | Se podría agregar token (opcional) |

## Próximas mejoras (opcionales)

Si quieres mayor seguridad, podrías agregar:

1. **CSRF Token** - Token único para cada request
2. **Rate limiting** - Máximo de requests por minuto
3. **IP validation** - Requiere misma IP que login
4. **Request signing** - HMAC de la request
5. **Audit log** - Registra quién importó qué

¿Necesitas implementar alguna de estas medidas? 🔒
