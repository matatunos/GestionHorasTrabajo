# Manual de Usuario - GestionHorasTrabajo

**Versión:** 1.1.1  
**Última actualización:** 2026-01-27

## Tabla de Contenidos

1. [Introducción](#introducción)
2. [Primeros pasos](#primeros-pasos)
3. [Panel de usuario](#panel-de-usuario)
4. [Registrar fichajes](#registrar-fichajes)
5. [Administración de horarios](#administración-de-horarios)
6. [Reportes y análisis](#reportes-y-análisis)
7. [Configuración personal](#configuración-personal)
8. [Troubleshooting](#troubleshooting)

---

## Introducción

GestionHorasTrabajo es un sistema web para gestionar y registrar horas de trabajo de forma centralizada. Permite:

- ✅ Registrar entrada y salida del trabajo
- ✅ Visualizar horas trabajadas por período
- ✅ Generar reportes de asistencia
- ✅ Configurar horarios y ausencias
- ✅ Análisis de productividad

### Requisitos

- Navegador web moderno (Chrome, Firefox, Safari, Edge)
- Conexión a internet
- Credenciales de usuario válidas

---

## Primeros pasos

### Acceso al sistema

1. Abre tu navegador y ve a: `https://calendar.favala.es`
2. Verás la pantalla de login
3. Ingresa:
   - **Usuario:** Tu nombre de usuario
   - **Contraseña:** Tu contraseña
4. Haz clic en "Entrar"

### Primer acceso

Si es tu primer acceso y el admin no ha configurado tu contraseña:

- Usuario: `admin`
- Contraseña: `admin`
- **Importante:** Deberás cambiar la contraseña al primer acceso

### Cambiar contraseña

1. En el menú superior derecho, haz clic en tu usuario
2. Selecciona "Cambiar contraseña"
3. Ingresa tu contraseña actual
4. Ingresa la nueva contraseña (mínimo 8 caracteres)
5. Confirma la nueva contraseña
6. Haz clic en "Actualizar"

---

## Panel de usuario

### Dashboard

Al entrar, verás el panel principal con:

**Información actual:**
- Hora entrada/salida de hoy
- Horas trabajadas hasta ahora
- Estado actual (en pausa, trabajando, ausencia)

**Periodos disponibles:**
- Resumen semanal
- Resumen mensual
- Resumen anual

### Acciones rápidas

Desde el dashboard puedes:
- ✅ Registrar entrada (botón "Marcar entrada")
- ✅ Registrar salida (botón "Marcar salida")
- ✅ Registrar pausa (botón "Iniciar pausa")
- ✅ Ver calendario completo

---

## Registrar fichajes

### Registro manual de entrada/salida

1. Haz clic en **"Marcar entrada"** al llegar al trabajo
2. El sistema registra automáticamente la hora actual
3. Al salir, haz clic en **"Marcar salida"**

### Editar un fichaje

Si necesitas corregir una hora:

1. Ve a **"Reportes"** → **"Mi actividad"**
2. Busca el día que necesitas editar
3. Haz clic en el campo de hora
4. Edita la hora y confirma
5. El sistema recalculará automáticamente

### Ausencias y permisos

Para registrar una ausencia:

1. Ve a **"Ausencias"**
2. Selecciona el tipo:
   - Vacaciones
   - Permiso sin sueldo
   - Enfermedad
   - Otro
3. Selecciona el rango de fechas
4. Agrega un comentario (opcional)
5. Haz clic en "Crear ausencia"

El administrador deberá aprobar la solicitud.

---

## Administración de horarios

### Horario semanal

Tu horario por defecto es:

- **Lunes a jueves:** 8 horas
- **Viernes:** 6 horas
- **Pausa de almuerzo:** 30 minutos (no cuenta como trabajo)
- **Pausa de café:** 15 minutos (cuenta como trabajo)

### Periodos especiales

**Verano (15 de junio al 30 de septiembre):**
- Lunes a jueves: 7.5 horas
- Viernes: 6 horas

### Modificar tu horario

1. Ve a **"Configuración"** → **"Mi horario"**
2. Selecciona el período (invierno/verano)
3. Modifica los valores si es necesario
4. Haz clic en "Guardar"

---

## Reportes y análisis

### Ver mis horas

1. Ve a **"Reportes"** → **"Mi actividad"**
2. Selecciona el período:
   - Hoy
   - Esta semana
   - Este mes
   - Personalizado
3. Verás un desglose completo de:
   - Entrada y salida
   - Horas trabajadas
   - Ausencias
   - Observaciones

### Exportar datos

Desde cualquier reporte puedes:
- Descargar en Excel (botón "Descargar")
- Imprimir (Ctrl+P)
- Copiar al portapapeles

### Análisis de productividad

En **"Análisis"** encontrarás:

- **Horas por semana:** Gráfico de horas semanales
- **Tendencias:** Análisis de patrones
- **Comparativas:** Vs. objetivo semanal/mensual
- **Alertas:** Si estás cerca de límites o excesos

---

## Configuración personal

### Perfil

1. Haz clic en tu usuario (menú superior derecho)
2. Selecciona "Mi perfil"
3. Puedes actualizar:
   - Nombre completo
   - Email
   - Teléfono
   - Foto de perfil

### Preferencias

En **"Configuración"** → **"Preferencias"** puedes:

- Cambiar idioma (si está disponible)
- Cambiar zona horaria
- Notificaciones (email)
- Tema claro/oscuro
- Formato de hora (12h/24h)

### Tokens de acceso

Si usas aplicaciones externas o extensiones:

1. Ve a **"Configuración"** → **"Tokens"**
2. Haz clic en "Crear nuevo token"
3. Dale un nombre descriptivo
4. Selecciona los permisos
5. Cópialo y guárdalo en lugar seguro
   - **⚠️ Solo aparece una vez**

---

## Troubleshooting

### No puedo entrar

**Error: "Credenciales inválidas"**
- Verifica que escribiste bien tu usuario y contraseña
- Las contraseñas son sensibles a mayúsculas/minúsculas
- Si olvidaste la contraseña, contacta al administrador

**Error: "Base de datos no disponible"**
- El servidor está fuera de servicio
- Espera unos minutos e intenta de nuevo
- Contacta al soporte técnico si persiste

### Las horas no se guardan

- Verifica tu conexión a internet
- Recarga la página (F5)
- Prueba desde otro navegador
- Vacía el caché del navegador

### Reportes muestran datos incorrectos

- Espera 5 minutos para que se sincronicen
- Recarga la página
- Si persiste, contacta al administrador

### Extensión Chrome no funciona

Si usas la extensión para registro rápido:

1. Verifica que esté habilitada en Chrome
2. Genera un token nuevo en "Configuración" → "Tokens"
3. Reinstala la extensión si es necesario

---

## Contacto y soporte

**Para reportar problemas:**
- Contacta a tu administrador
- Email: [tu email de soporte]
- Horario de atención: Lunes a viernes, 9 AM - 6 PM

**Información útil al reportar:**
- Descripción clara del problema
- Pasos para reproducirlo
- Navegador y versión
- Hora en que ocurrió

---

## Notas finales

- **Responsabilidad:** Eres responsable de registrar tus horas correctamente
- **Precisión:** Intenta registrar entrada/salida a la hora exacta
- **Reportes:** Se generan automáticamente cada mes
- **Privacidad:** Tus datos están protegidos y solo accesibles a administradores autorizados

¡Gracias por usar GestionHorasTrabajo!
