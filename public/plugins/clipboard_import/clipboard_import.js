alert("clipboard_import.js cargado");
// Plugin: Importar desde portapapeles
// Requiere importFichajes.js en la página principal

document.addEventListener('DOMContentLoaded', function() {
    // Interceptar el pegado en el div para conservar saltos y espacios
    clipboardDiv.addEventListener('paste', function(e) {
      e.preventDefault();
      let text = '';
      if (e.clipboardData && e.clipboardData.getData) {
        text = e.clipboardData.getData('text/plain');
      } else if (window.clipboardData && window.clipboardData.getData) {
        text = window.clipboardData.getData('Text');
      }
      // Insertar el texto plano tal cual
      document.execCommand('insertText', false, text);
      resultDiv.innerHTML = '<span style="color:green;">¡Datos pegados manualmente! Ahora puedes ordenarlos y verlos.</span>';
    });
  const btn = document.getElementById('pasteClipboardBtn');
  const clipboardDiv = document.getElementById('clipboard_div');
  const resultDiv = document.getElementById('importResult');
  const browserWarning = document.getElementById('browser_warning');
  const clipboardContainer = document.getElementById('clipboard_container');
  const ordenarBtn = document.createElement('button');
  ordenarBtn.textContent = 'Ordenar y mostrar registros';
  ordenarBtn.style.margin = '1em 0 0.5em 0';
  ordenarBtn.style.display = 'block';
  clipboardContainer.appendChild(ordenarBtn);

  // Advertencia para navegadores problemáticos
  const ua = navigator.userAgent;
  if (/Windows/i.test(ua) && (/Chrome|Edg|Edge|MSIE|Trident/i.test(ua))) {
    browserWarning.innerHTML = '⚠️ <b>Atención:</b> En Windows con Chrome/Edge, el pegado puede perder saltos de línea o espacios. Si tienes problemas, usa Firefox o pega primero en un editor de texto plano.';
  }

  let registros = [];


  btn.addEventListener('click', async function() {
    try {
      const text = await navigator.clipboard.readText();
      if (text) {
        clipboardDiv.textContent = text;
        resultDiv.innerHTML = '<span style="color:green;">¡Datos pegados! Ahora puedes ordenarlos y verlos.</span>';
      } else {
        resultDiv.innerHTML = '<span style="color:orange;">El portapapeles está vacío o no contiene datos válidos. Puedes pegar manualmente en el área de texto.</span>';
      }
    } catch (err) {
      resultDiv.innerHTML = '<span style="color:red;">No se pudo leer del portapapeles: ' + err + '<br>Puedes pegar manualmente en el área de texto.</span>';
    }
  });

  // Permitir pegar manualmente y mostrar mensaje
  textarea.addEventListener('paste', function() {
    setTimeout(function() {
      if (clipboardDiv.innerText) {
        resultDiv.innerHTML = '<span style="color:green;">¡Datos pegados manualmente! Ahora puedes ordenarlos y verlos.</span>';
      }
    }, 100);
  });

  ordenarBtn.addEventListener('click', function() {
    const contenido = clipboardDiv.textContent;
    if (!contenido) {
      resultDiv.innerHTML = '<span style="color:red;">No hay datos para procesar.</span>';
      return;
    }
    let year = new Date().getFullYear();
    try {
      if (!window.importFichajes) {
        resultDiv.innerHTML = '<span style="color:red;">No se encontró la función de parseo (importFichajes.js).</span>';
        return;
      }
      let registrosLocales = [];
      // Intentar como HTML primero
      try {
        registrosLocales = window.importFichajes.parseFichajesHTML(contenido, year);
      } catch (e) {}
      // Si no hay registros, intentar como texto plano (copiado de la web)
      if (!registrosLocales.length && window.importFichajes.parseFichajesTextoPlano) {
        registrosLocales = window.importFichajes.parseFichajesTextoPlano(contenido, year);
      }
      // Si sigue sin haber registros, intentar como tabla transpuesta
      if (!registrosLocales.length && window.importFichajes.parseFichajesTranspuesta) {
        registrosLocales = window.importFichajes.parseFichajesTranspuesta(contenido, year);
      }
      // Si sigue sin haber registros, intentar como formato vertical/apilado
      if (!registrosLocales.length && window.importFichajes.parseFichajesVerticalApilado) {
        registrosLocales = window.importFichajes.parseFichajesVerticalApilado(contenido, year);
      }
      // Si sigue sin haber registros, intentar parser específico para pegado desde navegador
      let debugMsg = '';
      if (!registrosLocales.length && window.importFichajes.parseFichajesPegadoNavegador) {
        registrosLocales = window.importFichajes.parseFichajesPegadoNavegador(contenido, year);
        if (!registrosLocales.length) {
          // Depuración: mostrar info sobre el contenido pegado
              const rawLines = contenido.split(/\r?\n/);
              // Detectar bloques de horas: secuencias de líneas tipo HH:MM separadas por líneas vacías
              let bloques = [];
              let bloqueActual = [];
              const horaRegex = /^\d{2}:\d{2}$/;
              for (let l of rawLines) {
                const line = l.trim();
                if (line === "") {
                  if (bloqueActual.length > 0) {
                    bloques.push(bloqueActual);
                    bloqueActual = [];
                  }
                  continue;
                }
                if (horaRegex.test(line)) {
                  bloqueActual.push(line);
                } else {
                  // Si la línea no es hora, la ignoramos (cabeceras, totales, etc)
                }
              }
              if (bloqueActual.length > 0) bloques.push(bloqueActual);

              // Debug: mostrar bloques detectados
                    const textarea = document.getElementById('clipboard_div');
                debugMsg += `<b>DEBUG: lineas originales: ${rawLines.length}</b><br>`;
                debugMsg += `<b>Texto pegado (saltos de línea como ⏎):</b><br><pre style='font-size:0.95em;'>`;
                debugMsg += contenido.replace(/\n/g, '⏎\n');
                debugMsg += `</pre>`;
                debugMsg += `<b>Primeras 20 líneas (unicode):</b><br><pre style='font-size:0.95em;'>`;
                debugMsg += rawLines.slice(0,20).map((l,i) => `[${i+1}] ` + Array.from(l).map(c=>c+`(U+${c.charCodeAt(0).toString(16).padStart(4,'0')})`).join('')).join('\n');
                debugMsg += `</pre>`;
                debugMsg += `<b>Bloques de horas detectados: ${bloques.length}</b><br>`;
                bloques.forEach((b, i) => {
                  debugMsg += `Bloque ${i+1}: ${b.length} lineas -> ${b.slice(0,6).join(", ")}`;
                  if (b.length > 6) debugMsg += ", ...";
                  debugMsg += "<br>";
                          textarea.innerText = text;
                debugMsg += `</div>`;
                showError(debugMsg);
        }
      }
      if (!registrosLocales.length) throw new Error('No se encontraron registros.' + debugMsg);
      registros = registrosLocales;
      registros.sort((a, b) => a.fechaISO.localeCompare(b.fechaISO));
      let htmlTable = '<table border="1" style="margin-top:1em;width:100%;"><tr><th>Fecha</th><th>Día</th><th>Horas</th><th>Balance</th></tr>';
      for (const r of registros) {
        htmlTable += `<tr><td>${r.fechaISO}</td><td>${r.dia}</td><td>${r.horas.join(' | ')}</td><td>${r.balance}</td></tr>`;
      }
      htmlTable += '</table>';
      resultDiv.innerHTML = htmlTable + '<br><button id="insertarRegistrosBtn">Insertar en base de datos</button>';
      document.getElementById('insertarRegistrosBtn').onclick = insertarRegistros;
    } catch (e) {
      resultDiv.innerHTML = '<span style="color:red;">Error al procesar: ' + e.message + '</span>';
    }
  });

  function insertarRegistros() {
    if (!registros.length) {
      resultDiv.innerHTML = '<span style="color:red;">No hay registros para insertar.</span>';
      return;
    }
    fetch('clipboard_import_insert.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ registros })
    })
    .then(r => r.json())
    .then(data => {
      if (data.ok) {
        resultDiv.innerHTML = '<span style="color:green;">Registros insertados correctamente.</span>';
      } else {
        resultDiv.innerHTML = '<span style="color:red;">Error al insertar: ' + (data.error || 'Desconocido') + '</span>';
      }
    })
    .catch(e => {
      resultDiv.innerHTML = '<span style="color:red;">Error de red: ' + e + '</span>';
    });
  }
});
