<?php
require_once __DIR__ . '/../../auth.php';
require_login();
$user = current_user();
?>
<h2>Importar datos desde el portapapeles</h2>
<ol>
  <li>Copia la tabla de fichajes desde el HTML fuente (por ejemplo, desde la web de fichajes).</li>
  <li>Pega el contenido usando el botón de abajo.</li>
  <li>Haz clic en "Ordenar y mostrar registros" para ver los datos procesados.</li>
  <li>Si todo es correcto, pulsa "Insertar en base de datos" para guardar los registros.</li>
</ol>
<button id="pasteClipboardBtn">Pegar desde portapapeles (o pega manualmente abajo)</button>
<div id="clipboard_container" style="margin-bottom:1em;">
  <div id="clipboard_div" contenteditable="true" style="width:100%;min-height:8em;font-size:1.1em;border:1px solid #888;padding:6px;background:#fff;"></div>
  <div id="browser_warning" style="color:#b00;font-size:1em;margin:0.5em 0 0.5em 0;"></div>
  <!-- El botón se insertará aquí por JS -->
</div>
<div style="color:#888; font-size:0.95em; margin-top:0.5em;">Si el botón no funciona, pega manualmente usando Ctrl+V o clic derecho en el área de texto.</div>
<div id="importResult" style="margin-top:1em;"></div>
<script src="../../importFichajes.js"></script>
<script src="clipboard_import/clipboard_import.js"></script>
