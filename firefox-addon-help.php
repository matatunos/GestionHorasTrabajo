<?php
require_once __DIR__ . '/auth.php';
require_login();
require_once __DIR__ . '/header.php';
?>
<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Extensión Firefox - Ayuda</title><link rel="icon" type="image/svg+xml" href="images/favicon.svg"><link rel="stylesheet" href="styles.css"><style>
  .help-section { margin-bottom: 30px; }
  .help-section h3 { color: #007bff; margin-top: 20px; margin-bottom: 10px; }
  .step-number { display: inline-flex; align-items: center; justify-content: center; background: #007bff; color: white; width: 32px; height: 32px; min-width: 32px; border-radius: 50%; font-weight: bold; margin-right: 15px; flex-shrink: 0; vertical-align: top; }
  .step { margin: 15px 0; padding: 15px; padding-left: 15px; background: #f9f9f9; border-left: 3px solid #007bff; }
  .step strong { display: block; margin-bottom: 5px; margin-top: 0; }
  .step p { margin: 5px 0; }
  .help-section ul, .help-section ol { margin: 10px 0; padding-left: 25px; }
  .help-section li { margin: 5px 0; padding-left: 5px; }
  .help-section h4 { color: #333; margin-top: 15px; margin-bottom: 8px; }
  .code-block { background: #272822; color: #f8f8f2; padding: 15px; border-radius: 5px; overflow-x: auto; font-family: monospace; margin: 10px 0; }
  .feature-list { list-style: none; padding: 0; }
  .feature-list li { padding: 8px 0; padding-left: 25px; position: relative; }
  .feature-list li:before { content: "✓"; position: absolute; left: 0; color: #28a745; font-weight: bold; }
  .download-btn { display: inline-block; padding: 15px 30px; background: #007bff; color: white; border-radius: 5px; text-decoration: none; font-weight: bold; margin: 10px 5px 10px 0; }
  .download-btn:hover { background: #0056b3; text-decoration: none; }
  .warning-box { background: #fff3cd; border: 1px solid #ffc107; border-radius: 5px; padding: 15px; margin: 15px 0; }
  .warning-box ul { margin: 10px 0; padding-left: 25px; }
  .warning-box li { margin: 5px 0; padding-left: 5px; }
  .info-box { background: #e7f3ff; border: 1px solid #007bff; border-radius: 5px; padding: 15px; margin: 15px 0; }
  .screenshot { max-width: 100%; border: 1px solid #ddd; border-radius: 5px; margin: 15px 0; }
</style></head><body>
<div class="container">
  <div class="card">
    <h2>🧩 Extensión Firefox: GestionHorasTrabajo</h2>
    
    <p style="font-size: 16px;">Descarga e instala nuestra extensión de Firefox para importar datos de fichajes con un solo click desde cualquier página HTML.</p>

    <!-- Features -->
    <div class="help-section">
      <h3>✨ Características</h3>
      <ul class="feature-list">
        <li>Detección automática de páginas de fichajes</li>
        <li>Importación con un click - sin formularios</li>
        <li>Soporta múltiples formatos (EXTERNAL, HTML estándar)</li>
        <li>Extrae automáticamente horas de entrada/salida y pausas</li>
        <li>Convierte múltiples formatos de fecha</li>
        <li>Seguro - tus datos se envían a tu servidor</li>
      </ul>
    </div>

    <div class="help-section">
      <h3>📥 Descarga e Instalación</h3>
      
      <div class="info-box">
        <strong>⭐ Forma más rápida:</strong> Descarga el archivo ZIP comprimido con todo lo necesario:
        <br><a href="download-firefox-addon.php" class="download-btn" style="margin-top: 10px;">📦 Descargar extensión (ZIP)</a>
      </div>
      
      <div class="step">
        <span class="step-number">1</span>
        <strong>Descargar y descomprimir</strong>
        <p>Descarga el ZIP comprimido arriba (recomendado) y descomprime en tu computadora. Debería crear una carpeta con:</p>
        <div class="code-block">manifest.json
popup.html
popup.js
content.js
background.js
images/
  icon-16.png
  icon-48.png
  icon-128.png</div>
      </div>

      <div class="step">
        <span class="step-number">2</span>
        <strong>Abre la página de extensiones de Firefox</strong>
        <p>En tu navegador Firefox, ve a <code>chrome://extensions/</code></p>
        <p>O simplemente: Menú de Firefox (≡) → Más herramientas → Extensiones</p>
      </div>

      <div class="step">
        <span class="step-number">3</span>
        <strong>Activa el "Modo de desarrollador"</strong>
        <p>En la esquina superior derecha de la página de extensiones, encontrarás el botón "Modo de desarrollador". Haz clic para activarlo.</p>
      </div>

      <div class="step">
        <span class="step-number">4</span>
        <strong>Carga la extensión</strong>
        <p>Después de activar el modo de desarrollador, aparecerá un botón "Cargar extensión sin empaquetar". Haz clic y <strong>selecciona la carpeta que descargaste</strong> (la que contiene <code>manifest.json</code>).</p>
      </div>

      <div class="step">
        <span class="step-number">5</span>
        <strong>¡Listo! Extensión instalada</strong>
        <p>La extensión aparecerá en tu lista de extensiones. Verás un icono en la barra de herramientas de Firefox.</p>
      </div>
    </div>

    <!-- How it works -->
    <div class="help-section">
      <h3>🚀 ¿Cómo usar la extensión?</h3>
      
      <p><strong>El proceso es muy simple:</strong></p>
      
      <div class="step">
        <span class="step-number">1</span>
        <strong>Ve a tu página de fichajes</strong>
        <p>Abre en el navegador la página donde ves tus datos de fichajes (puede ser EXTERNAL, HTML personalizado, etc.)</p>
      </div>

      <div class="step">
        <span class="step-number">2</span>
        <strong>Haz clic en el icono de la extensión</strong>
        <p>En la barra de herramientas superior derecha, verás el icono de GestionHorasTrabajo. Haz clic en él.</p>
      </div>

      <div class="step">
        <span class="step-number">3</span>
        <strong>Los datos se capturan automáticamente</strong>
        <p>La extensión extraerá automáticamente los datos de fichajes de la página y mostrará una vista previa.</p>
      </div>

      <div class="step">
        <span class="step-number">4</span>
        <strong>Revisa la vista previa</strong>
        <p>Verás una lista con los datos capturados. Confirma que son correctos.</p>
      </div>

      <div class="step">
        <span class="step-number">5</span>
        <strong>Importa a GestionHorasTrabajo</strong>
        <p>Haz clic en <strong>"✅ Importar fichajes"</strong> y los datos se guardarán automáticamente en tu aplicación.</p>
      </div>

      <div class="step">
        <span class="step-number">6</span>
        <strong>¡Confirmación!</strong>
        <p>Verás un mensaje de éxito indicando cuántos fichajes se importaron. ¡Listo!</p>
      </div>
    </div>

    <!-- Configuration -->
    <div class="help-section">
      <h3>⚙️ Configuración</h3>
      
      <div class="info-box">
        <strong>✨ Preconfigured:</strong> La extensión viene automáticamente configurada con la URL de donde la descargaste. Si necesitas cambiarla, sigue los pasos abajo.
      </div>
      
      <div class="step">
        <span class="step-number">1</span>
        <strong>Abre el panel de configuración</strong>
        <p>Haz clic en el icono de la extensión en la barra de herramientas de Firefox.</p>
      </div>

      <div class="step">
        <span class="step-number">2</span>
        <strong>Verifica/Modifica la URL de tu aplicación</strong>
        <p>En el campo "URL de la aplicación", verás preconfigurada la URL desde donde descargaste la extensión. Puedes cambiarla si lo necesitas:</p>
        <div class="code-block">http://localhost
http://192.168.1.100
https://miapp.com</div>
      </div>

      <div class="step">
        <span class="step-number">3</span>
        <strong>Guarda la configuración</strong>
        <p>Haz clic en el botón "💾 Guardar" si realizaste cambios.</p>
      </div>
    </div>

    <!-- Usage -->
    <div class="help-section">
      <h3>🚀 Cómo usar</h3>
      
      <ol>
        <li><strong>Abre una página HTML con datos de fichajes</strong> - Puede ser un archivo local o una página web</li>
        <li><strong>Busca el botón flotante</strong> - En la esquina inferior derecha verás "📥 Importar a GestionHorasTrabajo"</li>
        <li><strong>Haz clic para importar</strong> - Los datos se extraerán y se importarán automáticamente</li>
        <li><strong>Confirma el resultado</strong> - Verás una notificación con el número de fichajes importados</li>
      </ol>

      <div class="info-box">
        <strong>💡 Consejo:</strong> El botón solo aparecerá si la página contiene una tabla de fichajes reconocible. Si no lo ves, verifica que la página tenga los datos en el formato correcto.
      </div>
    </div>

    <!-- Supported Formats -->
    <div class="help-section">
      <h3>📋 Formatos soportados</h3>
      
      <h4>Formato EXTERNAL</h4>
      <p>Tablas con id <code>tabla_fichajes</code> que contienen horas en bloques <code>&lt;span&gt;</code>:</p>
      <div class="code-block">&lt;table id="tabla_fichajes"&gt;
  &lt;tr class="horas"&gt;
    &lt;td&gt;
      &lt;div class="Terminal"&gt;&lt;span&gt;08:00&lt;/span&gt;&lt;/div&gt;
      &lt;div class="Terminal"&gt;&lt;span&gt;10:30&lt;/span&gt;&lt;/div&gt;
      ...
    &lt;/td&gt;
  &lt;/tr&gt;
&lt;/table&gt;</div>

      <h4>Formato HTML estándar</h4>
      <p>Tablas HTML normales con columnas de entrada, salida y fechas:</p>
      <div class="code-block">&lt;table border="1"&gt;
  &lt;thead&gt;
    &lt;tr&gt;
      &lt;th&gt;Fecha&lt;/th&gt;
      &lt;th&gt;Entrada&lt;/th&gt;
      &lt;th&gt;Salida Café&lt;/th&gt;
      ...
    &lt;/tr&gt;
  &lt;/thead&gt;
  &lt;tbody&gt;
    &lt;tr&gt;
      &lt;td&gt;02/12&lt;/td&gt;
      &lt;td&gt;08:00&lt;/td&gt;
      ...
    &lt;/tr&gt;
  &lt;/tbody&gt;
&lt;/table&gt;</div>
    </div>

    <!-- Troubleshooting -->
    <div class="help-section">
      <h3>❓ Solución de problemas</h3>
      
      <h4>El botón no aparece</h4>
      <p>
        <strong>Causas comunes:</strong>
        <ul>
          <li>La extensión no está activada - Ve a <code>chrome://extensions/</code> y comprueba</li>
          <li>La página no contiene una tabla de fichajes - Verifica que tenga una tabla con los datos</li>
          <li>La estructura HTML no coincide - Intenta ajustar el formato de la tabla</li>
        </ul>
      </p>

      <h4>Los datos no se importan</h4>
      <p>
        <strong>Causas comunes:</strong>
        <ul>
          <li>URL configurada incorrectamente - Verifica que apunte a tu servidor</li>
          <li>No iniciaste sesión - Asegúrate de estar autenticado en GestionHorasTrabajo</li>
          <li>Error de CORS - Si la URL es externa, puede haber restricciones de seguridad</li>
        </ul>
        <strong>Debugging:</strong> Abre la consola del navegador (F12) y mira los errores en la pestaña Console
      </p>

      <h4>Los datos se importan incompletos</h4>
      <p>
        <strong>Solución:</strong> La extensión intenta detectar automáticamente las pausas de café y comida. Si no las detecta:
        <ul>
          <li>Verifica que la tabla tenga columnas etiquetadas correctamente</li>
          <li>Intenta con un formato diferente (HTML estándar vs EXTERNAL)</li>
          <li>Abre un issue en GitHub con tu archivo HTML para mejorar la detección</li>
        </ul>
      </p>
    </div>

    <!-- Security -->
    <div class="help-section">
      <h3>🔒 Seguridad</h3>
      
      <div class="warning-box">
        <strong>⚠️ Importante leer:</strong>
        <ul>
          <li>La extensión envía los datos a la URL que configures</li>
          <li>Asegúrate de que la URL es de un servidor confiable</li>
          <li>Los datos se envían con tus cookies de sesión (necesario para autenticación)</li>
          <li>No almacenamos datos sensibles en el navegador (solo la URL de tu app)</li>
          <li>Todo el código de la extensión es de código abierto y está disponible en GitHub</li>
        </ul>
      </div>
    </div>

    <!-- Support -->
    <div class="help-section">
      <h3>📞 Soporte</h3>
      
      <p>Si tienes problemas o sugerencias:</p>
      <ul>
        <li>📖 Consulta la documentación local: GestionHorasTrabajo/firefox-extension</li>
        <li>🐛 Reporta incidencias en tu sistema de seguimiento local</li>
        <li>💬 Sugiere mejoras o nuevos formatos</li>
      </ul>
    </div>

    <div style="margin-top: 30px; padding: 20px; background: #f0f0f0; border-radius: 5px; text-align: center;">
      <p style="margin: 0; font-size: 14px;">¿Necesitas más ayuda? Consulta la documentación incluida en la carpeta <strong>firefox-extension</strong></p>
    </div>
  </div>
</div>
<?php include __DIR__ . '/footer.php'; ?>
</body></html>
