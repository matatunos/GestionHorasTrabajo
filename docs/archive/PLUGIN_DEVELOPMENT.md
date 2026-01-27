# Guía para Desarrollar Plugins

Este sistema permite ampliar funcionalidades mediante plugins PHP autocontenidos. Los plugins se integran en el menú lateral y se muestran dentro del layout principal (cabecera y pie).

## Estructura de un Plugin

Cada plugin debe estar en su propia carpeta dentro de `plugins/`:

```
plugins/
  tuplugin/
    index.php
    metadata.json
    style.css (opcional)
```

- **index.php**: Código principal del plugin. Solo debe renderizar el contenido principal (sin cabecera ni pie, ya que el wrapper los añade).
- **metadata.json**: Información del plugin (nombre, descripción, versión).
- **style.css**: (Opcional) Estilos propios del plugin.

## Ejemplo de metadata.json

```json
{
  "name": "Mi Plugin",
  "description": "Descripción breve del plugin",
  "version": "1.0"
}
```

## index.php básico

```php
<?php
// Puedes usar $user = current_user(); y $pdo = get_pdo();
?>
<h2>Hola desde mi plugin</h2>
<p>Contenido aquí...</p>
```

## Acceso a usuario y base de datos

- `$user = current_user();` — Usuario autenticado
- `$pdo = get_pdo();` — Conexión PDO a la base de datos

## Buenas prácticas

- No incluir cabecera ni pie (ya los añade el sistema)
- No hacer `require` de archivos comunes (auth.php, lib.php, db.php)
- Usar rutas relativas para recursos propios (`style.css`)
- Si necesitas JS o CSS, inclúyelos en tu carpeta y enlázalos desde `index.php`

## Integración y menú

- El sistema detecta automáticamente los plugins y los añade al menú lateral bajo "🧩 Plugins"
- El nombre mostrado es el de `metadata.json`

## Ejemplo de carpeta plugin

```
plugins/
  ejemplo/
    index.php
    metadata.json
    style.css
```

¡Con esto puedes crear y probar tus propios plugins fácilmente!
