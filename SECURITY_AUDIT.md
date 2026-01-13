# 🔒 AUDITORÍA DE SEGURIDAD - GestionHorasTrabajo

## ✅ PASÓ

### SQL Injection
- ✅ Todas las consultas usan prepared statements (?)
- ✅ Los parámetros se pasan por array a execute()
- ✅ No hay concatenación de strings en SQL

### CSRF Protection
- ✅ API requiere X-Requested-With header (AJAX check)
- ✅ Bearer token basado en JWT para móvil
- ✅ Session-based para web

### Autenticación
- ✅ Sesiones requieren login previo
- ✅ Tokens con expiración (30 días)
- ✅ Contraseñas con password_verify()

---

## ⚠️ PROBLEMAS ENCONTRADOS

### 1. CORS Header Injection (CRÍTICO)
**Archivo:** api.php línea 40
**Problema:** El header CORS usa directamente `$origin` sin validación

```php
// ❌ MAL - Vulnerable a CORS header injection
if ($should_allow || strpos($origin, 'chrome-extension://') === 0) {
  header('Access-Control-Allow-Origin: ' . $origin);  // Aquí se inyecta directamente
```

**Fix:** Solo permitir origins específicos de whitelist
```php
// ✅ BIEN
$allowed_origins = [
  'https://calendar.favala.es',
  'chrome-extension://[specific-id]'
];
// Solo usar $origin si está en whitelist
if (in_array($origin, $allowed_origins, true)) {
  header('Access-Control-Allow-Origin: ' . $origin);
}
```

---

### 2. Validación de Entrada Débil
**Archivo:** api.php línea 106
**Problema:** No se valida el tipo/formato de username

```php
// ❌ Debería validar formato
$username = $global_input['username'] ?? null;
$password = $global_input['password'] ?? null;
```

**Fix:** Agregar validación
```php
// ✅ BIEN
$username = trim($global_input['username'] ?? '');
$password = $global_input['password'] ?? '';

if (!$username || !$password) {
  // error
}

if (strlen($username) > 255 || strlen($password) > 255) {
  // error - entrada muy larga
}
```

---

### 3. Token JWT Inseguro
**Archivo:** api.php línea 120
**Problema:** Se usa una clave secreta hardcodeada

```php
// ❌ MAL - Secreto hardcodeado
$signature = base64_encode(hash_hmac('sha256', "$header.$payload", 'gestion_horas_secret_key', true));
```

**Fix:** Usar variable de entorno
```php
// ✅ BIEN
$secret_key = getenv('JWT_SECRET_KEY') ?: $_ENV['JWT_SECRET_KEY'] ?? 'default-key-change-me';
$signature = base64_encode(hash_hmac('sha256', "$header.$payload", $secret_key, true));
```

---

### 4. Error Handling Expone Info
**Archivo:** api.php + admin-settings.php
**Problema:** Los errores de BD pueden exponer estructura de tablas

```php
// ❌ MAL - Expone error de BD
} catch (Exception $e) {
  http_response_code(500);
  echo json_encode(['error' => $e->getMessage()]);  // Mensaje completo
}
```

**Fix:** Mensaje genérico en producción
```php
// ✅ BIEN
} catch (Exception $e) {
  error_log($e->getMessage());  // Log privado
  http_response_code(500);
  echo json_encode(['error' => 'Database error']);  // Mensaje genérico
}
```

---

### 5. Validación de JWT Incompleta
**Archivo:** api.php línea 179
**Problema:** La validación del JWT es manual y podría fallar

```php
// ❌ Validación incompleta
if (count($parts) === 3) {
  $payload = json_decode(base64_decode($parts[1]), true);
  if ($payload && isset($payload['user_id']) && $payload['exp'] > time()) {
    // OK
  }
}
```

**Fix:** Validar firma también
```php
// ✅ BIEN - Validar firma
$expected_sig = base64_encode(hash_hmac('sha256', "$parts[0].$parts[1]", $secret_key, true));
if (base64_decode($parts[2]) === base64_decode($expected_sig)) {
  // Firma válida
}
```

---

## 📊 RESUMEN

| Categoría | Estado | Crítico |
|-----------|--------|---------|
| SQL Injection | ✅ SEGURO | No |
| CSRF | ✅ SEGURO | No |
| CORS | ⚠️ VULNERABLE | Sí |
| Input Validation | ⚠️ DÉBIL | Medio |
| JWT Security | ⚠️ DÉBIL | Sí |
| Error Handling | ⚠️ EXPONE INFO | Medio |
| Autenticación | ✅ SEGURO | No |

---

## 🔧 RECOMENDACIONES

1. **URGENTE:** Fijar whitelist CORS específico
2. **URGENTE:** Usar JWT_SECRET_KEY en .env
3. **IMPORTANTE:** Mejorar validación de entrada
4. **IMPORTANTE:** Validar firma de JWT
5. **IMPORTANTE:** Ocultar errores en producción
6. **RECOMENDADO:** Agregar rate limiting en login
7. **RECOMENDADO:** Usar biblioteca JWT profesional (firebase/php-jwt)

---

## ✅ NEXT STEPS

- [ ] Corregir CORS header injection
- [ ] Mover secrets a .env
- [ ] Mejorar validación de entrada
- [ ] Validar firma JWT
- [ ] Ocultar errores internos
- [ ] Agregar logging de seguridad
- [ ] Hacer commit con fixes de seguridad

