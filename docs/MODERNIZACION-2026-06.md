# Modernización de GestionHorasTrabajo — Junio 2026

> Documento de trabajo completo: auditoría, decisiones, cambios aplicados, verificaciones,
> acciones manuales pendientes y cómo revertir. Sirve de base para el CHANGELOG del homelab
> y como referencia personal de todo lo realizado.

**Fecha de inicio:** 2026-06-06
**Entorno de trabajo:** CT40012 `calendar-dev` (`192.168.1.19`), publicado en `dev.calendar.favala.es`
(NPM proxy host #56 → `.19:80`, cert Let's Encrypt #82). Clon de la VM40006 de producción.
**Modelo de despliegue acordado:** cuando todo esté validado, **el propio dev pasa a producción**
(se repunta el NPM de `calendar.favala.es` de `.17` → `.19`) y la **VM40006 queda apagada**.
NO hay rsync/deploy a la VM de producción.
**Control de cambios:** rama git `modernizacion` en la copia de trabajo
(`/backup_pool/Nacho/gestionhoras-dev` en el host Proxmox) + snapshot LXC `pre_modernizacion`.

---

## 1. Resumen ejecutivo

La app (registro horario / vacaciones / guardias TRAGSA, PHP 8.4 + MariaDB + Apache) se desarrolló
de forma incremental y arrastraba: **vulnerabilidades críticas** (estaba expuesta a internet),
**no responsive** en móvil, **CSS y código duplicado** y mucho cruft. El trabajo se organiza en:

1. **Reestructuración a docroot `public/`** + autoloader PSR-4 + limpieza de cruft.
2. **Endurecimiento de seguridad** (cierre de exposición, sesión, fuerza bruta, secretos).
3. **Refactor backend profundo** (clases de dominio) — *en curso*.
4. **Rediseño visual moderno mobile-first** + unificación de CSS — *pendiente de elección de paleta*.

Estado a fecha de este documento: **fases 1 y 2 (estructura + seguridad core) completadas y
verificadas en vivo**. Pendientes: clases de dominio, CSRF, y el rediseño visual.

---

## 2. Auditoría inicial — hallazgos

Investigación con 3 exploraciones paralelas (frontend, seguridad, duplicación) + verificación
manual por HTTP. Todos los hallazgos eran **idénticos en producción** (mismo código).

### 2.1 Seguridad (verificado por HTTP real, no solo lectura de código)

| Severidad | Hallazgo | Evidencia |
|---|---|---|
| 🔴 Crítico | `.env` servido en claro por HTTP | `GET /.env` → 200, devolvía `DB_*`, `SMTP_PASS`, etc. |
| 🔴 Crítico | Repositorio `.git` completo descargable | `GET /.git/config` → 200; `.git` = 7.9 MB |
| 🔴 Crítico | `config.php` filtraba la contraseña real de BD | bloque final `print_r(get_config())` al acceso directo |
| 🔴 Crítico | Contraseña de Nextcloud hardcodeada | `caldav-vacaciones.php` `define('CALDAV_NC_PASS', ...)` |
| 🔴 Crítico | Sin rate-limiting en login | fuerza bruta libre en `login.php` y `api.php` |
| 🟠 Alto | `*.bak`, `cookies.txt`, `composer.json`, `*.md` servidos | `GET /api.php.bak` → 200, etc. |
| 🟠 Alto | Sin CSRF en formularios POST | settings/users/holidays/… |
| 🟠 Alto | Cookies de sesión sin flags | `session_start()` sin `HttpOnly/Secure/SameSite` |
| 🟠 Alto | Contraseña temporal hardcodeada `Temporal123!` | `settings.php` |
| 🟠 Alto | `phpinfo.php` accesible (tras login) | exposición de entorno PHP |
| 🟡 Medio | Cabeceras de seguridad solo en `api.php` | faltaban en páginas HTML |
| 🟡 Medio | `api-insecure.php` (endpoint legacy) | solo un redirect 301, pero ruido |

El `.htaccess` original solo evitaba interpretar `.js/.css/.txt`; **no protegía** `.env`, `.git`,
`.bak`, `.md`, `composer.json`, etc.

### 2.2 Frontend / responsividad

- **3 ficheros CSS** (`styles.css` 82 KB/2305 líneas, `css/dashboard-theme.css` 605, `css/settings.css` 70).
- **314 `!important`** (181 + 133) → cascada frágil.
- Decenas de `<style>` inline y cientos de `style="..."` (p.ej. 76 en `settings.php`).
- Breakpoints inconsistentes: 900/768/720/700/600/520/480 px.
- Tablas **no responsivas** (caso crítico: `index.php`, registro horario, 1658 líneas).
- Sidebar fija de 250 px; colores hardcodeados sin variables.
- Sí existía ya un shell compartido (`header.php`/`footer.php` con `.app-container`/`.sidebar`).
- Sin framework (vanilla), FontAwesome local, PWA (`manifest.json` + `sw.js`).

### 2.3 Duplicación / cruft

- ~15 ficheros cruft: `*.bak`, `*.bak-*`, `cookies.txt`, `honeycomb-widget.REMOVED`,
  ficheros basura `PDO::ERRMODE_EXCEPTION,` y `PDO::FETCH_ASSOC,`, notas `PRUEBA_*.txt`, etc.
- `lib/improvements_functions.php`: código muerto (no incluido por nadie).
- `tools/data_quality.php` = copia idéntica de `admin/data_quality.php`.
- `CREATE TABLE IF NOT EXISTS app_settings` repetido en **7 sitios**; `year_configs` en 4.
- 3 implementaciones del mismo concepto de tiempo (`timeToMinutes`/`minutesToTime` en `admin/` y
  `tools/`, `time_to_minutes` en `lib.php`).
- Boilerplate de includes repetido en los 38 entrypoints.
- `composer.json` minimalista, sin autoloading PSR-4 pese a tener `vendor/`.
- Bug preexistente: el menú enlaza `/data_quality.php` (no existe en raíz → 404) y
  `homer-status.php` usaba `dirname(__DIR__)` esperando estar en un subdir `api/` (roto).

---

## 3. Cambios aplicados

### Fase 0 — Red de seguridad
- Snapshot LXC: `pct snapshot 40012 pre_modernizacion`.
- Backup BD: `/root/gestion_horas_baseline.sql` en el CT.
- Copia de trabajo + git local (`git init`, rama `modernizacion`, commit baseline).

### Fase 1+2 (estructura) — reestructura a `public/`
**Nuevo layout** (raíz de la app = `/opt/GestionHorasTrabajo`, webroot = `…/public`):

```
/opt/GestionHorasTrabajo/          ← raíz (FUERA del webroot)
├── .env  .env.example  .git       ← ya NO servibles por HTTP
├── composer.json  composer.lock  vendor/
├── src/                           ← clases PSR-4 App\  (nuevo)
├── scripts/  docs/  deploy/
├── *.md  *.sh  LICENSE  HORARIO_2024.xlsx
└── public/                        ← DocumentRoot
    ├── *.php (38 entrypoints + auth/db/config/lib/header/footer)
    ├── admin/  tools/  plugins/  lib/
    ├── assets/  css/  js/  images/  uploads/  logs/
    ├── styles.css  manifest.json  sw.js  favicon.ico
    └── .htaccess                  ← endurecido
```

- **Cruft eliminado** (~15 ficheros + `lib/improvements_functions.php`).
- **DocumentRoot** del vhost cambiado a `…/public`.
- **Autoloader**: `composer.json` con `autoload.psr-4 {App\\: src/}` **y** un
  `spl_autoload_register` en `db.php` (carga las clases `App\` sin necesitar `composer dump-autoload`
  en cada despliegue). `vendor/autoload.php` se carga globalmente desde `db.php`.
- **Rutas ajustadas** por el movimiento: `db.php` (`.env` y `vendor` en `dirname(__DIR__)`),
  5 ficheros con `vendor/autoload` (tools/ y plugins/pdf_informe/), `homer-status.php` (`__DIR__`).
- **Fix leak**: eliminado el `print_r(get_config())` de `config.php`.

### Fase 1 (seguridad) — endurecimiento
- **`.htaccess` nuevo** en `public/`: `Require all denied` para dotfiles, `*.bak`, `*.sql`, `*.sh`,
  `*.md`, `*.log`, `*.lock`, `*.example`, patrones `*.bak-*`; `Options -Indexes`. Más
  `public/logs/.htaccess` (deny) y `public/uploads/.htaccess` (PHP engine off, anti-ejecución de subidas).
- **`.env` y `.git` fuera del webroot** → inaccesibles por HTTP de raíz (no dependen de reglas).
- **Cookies de sesión seguras**: `session_set_cookie_params` con `HttpOnly`, `Secure`
  (según `X-Forwarded-Proto` tras NPM) y `SameSite=Lax` en `auth.php`.
- **`session_regenerate_id(true)`** al autenticar (anti session fixation).
- **Cabeceras de seguridad** en todas las páginas (vía `auth.php`): `X-Frame-Options: DENY`,
  `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`,
  `X-XSS-Protection: 0`.
- **Rate-limiting de login** (web `login.php` + API `api.php`): tabla `login_attempts`, máximo
  5 fallos por IP en ventana de 15 min → **HTTP 429**. IP real del cliente vía `X-Forwarded-For`
  (confiada solo cuando la petición procede de NPM `.24`).
- **Credenciales CalDAV a `.env`** (`CALDAV_NC_*`); `caldav-vacaciones.php` ya no las hardcodea.
- **Eliminados** `phpinfo.php` y `api-insecure.php` (+ `admin/phpinfo.php`).

### Fase 2 (refactor) — clases de dominio y dedup
- **`App\Time`** (`src/Time.php`): unifica las 3 implementaciones de funciones de tiempo
  (`time_to_minutes`/`minutes_to_hours_formatted` en `lib.php` ahora delegan; se eliminaron las
  copias `timeToMinutes`/`minutesToTime`). `lib.php` pasa a incluir `db.php` para garantizar el autoloader.
- **`App\Schema`** (`src/Schema.php`): **fuente única del esquema**. Define las tablas con el esquema
  REAL de la BD (verificado por `SHOW CREATE TABLE`) y se invoca una vez por petición desde `get_pdo()`.
  Eliminados los `CREATE TABLE IF NOT EXISTS` inline y **obsoletos** de `config.php` (app_settings y
  year_configs — este último creaba un esquema viejo con `id/user_id` que ya no existe) y de `auth.php`
  (login_attempts). Corrección de paso: el esquema canónico de `year_configs` ahora es el real (PK `year`).
- **Cruft/duplicados eliminados**: `tools/data_quality.php` (copia idéntica de `admin/`),
  `admin/{admin-backup,backup_handler,clean_entries,extension-tokens}.php` (duplicados rotos no
  referenciados; las versiones activas están en la raíz), `admin/phpinfo.php`.
- **Bugs preexistentes corregidos**: `homer-status.php` (`__DIR__`), enlace del menú
  `/data_quality.php` → `/admin/data_quality.php` y sus includes (`dirname(__DIR__)`).

### Fase 3 (rediseño, en curso)
- **Re-skin global a Paleta A "Índigo Sereno"**: tokens `:root` de `styles.css` + sustitución de la
  familia de azul de marca por índigo en los 3 CSS (`#2563eb→#4f46e5`, `#1e40af→#4338ca`, etc.).
- **Tablas responsivas**: `.table-responsive` con scroll horizontal + primera columna ("Día") fija
  al desplazar en móvil; tipografía reducida en móvil. Caso principal: registro horario (`index.php`).

---

## 4. Verificaciones realizadas (en vivo, sobre `dev.calendar.favala.es`)

| Prueba | Resultado |
|---|---|
| `GET /.env` | **403** (antes 200 con credenciales) |
| `GET /.git/config` | **403** (antes 200) |
| `GET /composer.json` | **404** (fuera del webroot) |
| `GET /api.php.bak`, `/cookies.txt`, `/DEPLOYMENT.md` | **403 / 404** |
| `config.php` / `db.php` acceso directo | 200 **con cuerpo vacío** (ya no filtran) |
| Barrido de 500 en los 38 entrypoints | 0 errores (tras arreglar `homer-status.php`) |
| Autoloader `App\` | `App\Health::ok()` → OK |
| Cookie de sesión | `Secure; HttpOnly; SameSite=Lax` |
| Cabeceras de seguridad | presentes en páginas HTML |
| Login normal | GET 200, credenciales malas → "Credenciales inválidas" |
| Rate-limiting | bloquea con **429** tras superar el umbral |
| **Login autenticado real** (usuario qa temporal) | 302 → dashboard; dashboard/index/settings/holidays/users **200** |
| **CSRF** | POST sin token → **403**; POST con token válido → **302** (éxito) |
| Contraseña temporal | ya no es `Temporal123!` literal → aleatoria mostrada al admin |

---

## 5. Incidente de exposición y rotación de secretos

**2026-06-06** — un investigador externo ("White Web Security", beg-bounty legítimo) reportó por
email que `dev.calendar.favala.es` exponía `/.git/config` y `/.env`. Confirmado: además de DB/SMTP,
el **`.git/config` llevaba incrustado un Personal Access Token de GitHub** (`ghp_…` en la URL del
remote `origin`) → permitía clonar el repo privado y su historial. Asumir todo comprometido.

**Acciones:**
- ✅ Exposición ya cerrada antes del reporte (dev `/.git`,`/.env` → 403; prod VM40006 apagada → 502).
- ✅ Token de GitHub **borrado del remote** en el `.git/config` del CT (URL sin credenciales).
- ✅ **SMTP** `homelab@favala.es` rotada y actualizada en `.env`.
- ✅ **Nextcloud/CalDAV** (app-password de `nacho`) rotada y actualizada en `.env` (`CALDAV_NC_PASS`).
- ⏳ **PENDIENTE (acción del usuario en GitHub):** **revocar el PAT `ghp_24kCe…`** en
  Settings → Developer settings → Personal access tokens (revisar scopes; idealmente migrar a deploy key SSH).
- ⏳ **Opcional:** rotar `DB_PASS` (`app_user`) — solo localhost, bajo riesgo, pero estuvo público.

---

## 6. Pendiente / próximos pasos

**Hecho ya:** `App\Time` + unificación de tiempo ✅ · `App\Schema` + centralización de `CREATE TABLE` ✅ ·
eliminación de duplicados (data_quality, admin/*) ✅ · paleta elegida (**A · Índigo Sereno**) ✅ ·
re-skin global ✅ · tablas responsivas ✅.

**Queda:**
- **CSRF**: helper + token en todos los formularios POST (se aplicará junto al rediseño de formularios).
- **Forzar cambio de contraseña** en todos los entrypoints autenticados (hoy solo `dashboard.php`).
- **Quitar** la contraseña temporal hardcodeada `Temporal123!` de `settings.php`.
- **Más clases de dominio** (opcional): `App\Holidays`, `App\Guardias`, `App\Entries`, `App\Reports`
  extrayendo lógica de `holidays.php`/`guardias-*.php`/`index.php`/`api.php`.
- **Rediseño visual mobile-first (resto)**: consolidar en `assets/app.css` único, app-shell con drawer
  móvil pulido, migrar los `<style>`/`style="..."` inline a clases, eliminar los 314 `!important`,
  breakpoints estándar. *(Requiere QA visual en navegador.)*
- **Cutover dev → prod**: repuntar NPM #32 `calendar.favala.es` → `.19`, dejar VM40006 apagada.

---

## 7. Cómo revertir

- **Código**: rama git `modernizacion` en `/backup_pool/Nacho/gestionhoras-dev` (commits por hito).
- **Runtime completo**: `pct rollback 40012 pre_modernizacion` en el host Proxmox.
- **BD**: `/root/gestion_horas_baseline.sql` en el CT.

---

## 8. Bitácora de commits (rama `modernizacion`)

- `baseline: clon dev de produccion antes de modernizacion`
- `Fase 1-2: reestructura a docroot public/, cruft eliminado, .env/.git fuera del webroot, autoload PSR-4, fix leak config.php`
- `fix: homer-status.php usa __DIR__ (estaba roto, esperaba subdir api/)`
- `Fase 1 seguridad: cookies seguras+headers, rate-limiting login (web+api), creds CalDAV a .env, session_regenerate_id, borrado phpinfo/api-insecure`
- `seguridad: IP real del cliente vía X-Forwarded-For tras NPM para rate-limit`
- `docs: documentación completa de modernización + mockup de paletas (temporal)`
- `Fase 3 (inicio): re-skin global a Paleta A Indigo Sereno`
- `Fase 2 refactor: App\Time unifica funciones de tiempo; borrado tools/data_quality.php dup y admin/phpinfo.php; reactivado admin/data_quality.php + fix enlace menú`
- `Fase 2: eliminados duplicados rotos en admin/`
- `Fase 2: App\Schema fuente unica del esquema; elimina CREATE TABLE inline obsoletos`

*(Este documento se irá ampliando conforme avancen las fases de refactor y rediseño.)*
