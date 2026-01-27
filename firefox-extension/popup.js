/**
 * Popup script para captura e importación de fichajes
 */

const DEFAULT_URL = 'https://calendar.favala.es';

document.addEventListener('DOMContentLoaded', () => {
  chrome.storage.sync.get(['appUrl'], (result) => {
    document.getElementById('appUrl').value = result.appUrl || DEFAULT_URL;
  });
  
  document.getElementById('captureBtn').addEventListener('click', captureData);
  document.getElementById('importBtn').addEventListener('click', importData);
  document.getElementById('settingsToggle').addEventListener('click', toggleSettings);
  document.getElementById('saveBtn').addEventListener('click', saveSettings);
  document.getElementById('resetBtn').addEventListener('click', resetSettings);
  
  // Auto-capturar datos al abrir el popup
  setTimeout(autoCaptureData, 300);
});

// Captura automática al abrir popup
function autoCaptureData() {
  const captureBtn = document.getElementById('captureBtn');
  captureBtn.disabled = true;
  captureBtn.textContent = 'Capturando...';
  
  chrome.tabs.query({ active: true, currentWindow: true }, (tabs) => {
    if (!tabs || tabs.length === 0) {
      captureBtn.disabled = false;
      captureBtn.textContent = '📥 Capturar datos';
      document.getElementById('preview').innerHTML = 
        '<div style="color: #e11d48; padding: 1rem;">No se encontró pestaña activa</div>';
      return;
    }
    
    chrome.tabs.sendMessage(tabs[0].id, { action: 'captureFichajes' }, (response) => {
      captureBtn.disabled = false;
      captureBtn.textContent = '📥 Re-capturar datos';
      
      if (chrome.runtime.lastError) {
        console.error('[Popup] Error de comunicación:', chrome.runtime.lastError);
        document.getElementById('preview').innerHTML = 
          '<div style="color: #e11d48; padding: 1rem;">❌ No se pudo comunicar con la página.<br>Abre una página con tabla de fichajes e intenta de nuevo.</div>';
        return;
      }
      
      if (response && response.success) {
        showCapturedData(response.data, response.count, response.sourceFormat);
        document.getElementById('importBtn').disabled = false;
        window.capturedData = response.data;
        window.sourceFormat = response.sourceFormat;
      } else {
        const errorMsg = response?.error || 'Error desconocido';
        console.error('[Popup] Error de captura:', response);
        document.getElementById('preview').innerHTML = 
          '<div style="color: #e11d48; padding: 1rem;">❌ No se encontraron datos de fichajes en esta página.</div>';
      }
    });
  });
}

// Capturar datos de la página (función manual)
function captureData() {
  const captureBtn = document.getElementById('captureBtn');
  captureBtn.disabled = true;
  captureBtn.textContent = 'Capturando...';
  
  chrome.tabs.query({ active: true, currentWindow: true }, (tabs) => {
    if (!tabs || tabs.length === 0) {
      alert('❌ No se encontró pestaña activa');
      captureBtn.disabled = false;
      captureBtn.textContent = '📥 Capturar datos';
      return;
    }
    
    chrome.tabs.sendMessage(tabs[0].id, { action: 'captureFichajes' }, (response) => {
      captureBtn.disabled = false;
      captureBtn.textContent = '📥 Re-capturar datos';
      
      if (chrome.runtime.lastError) {
        console.error('[Popup] Error de comunicación:', chrome.runtime.lastError);
        alert('❌ Error: No se pudo comunicar con la página.\n\nVerifica que:\n1. Estés en una página web (no en chrome://, edge://, etc)\n2. La extensión esté habilitada');
        return;
      }
      
      if (response && response.success) {
        showCapturedData(response.data, response.count, response.sourceFormat);
        document.getElementById('importBtn').disabled = false;
        window.capturedData = response.data;
        window.sourceFormat = response.sourceFormat;
      } else {
        const errorMsg = response?.error || 'Error desconocido';
        console.error('[Popup] Error de captura:', response);
        
        // Si hay debug info, mostrarla
        if (response?.debug) {
          console.table(response.debug);
          alert('❌ No se encontraron datos\n\n' + errorMsg + '\n\n📋 Verifica la consola (F12) para más detalles.\n\nDebug:\n' + JSON.stringify(response.debug, null, 2));
        } else {
          alert('❌ ' + errorMsg + '\n\n💡 Abre la consola (F12) para ver detalles');
        }
      }
    });
  });
}

// Mostrar datos capturados
function showCapturedData(data, count, sourceFormat) {
  const preview = document.getElementById('preview');
  let html = `<strong>✅ ${count} registros capturados (${sourceFormat})</strong><br><br>`;
  
  Object.keys(data).slice(0, 5).forEach(date => {
    const entry = data[date];
    if (entry.times && Array.isArray(entry.times)) {
      html += `<small><strong>${date}:</strong> ${entry.times.join(' → ')}</small><br>`;
    }
  });
  
  if (count > 5) {
    html += `<small><em>... y ${count - 5} registros más</em></small><br>`;
  }
  
  preview.innerHTML = html;
  document.getElementById('dataSection').style.display = 'block';
}

// Importar datos capturados
function importData() {
  if (!window.capturedData) {
    alert('Por favor capture datos primero');
    return;
  }
  
  const importBtn = document.getElementById('importBtn');
  importBtn.disabled = true;
  importBtn.textContent = 'Importando...';
  
  chrome.storage.sync.get(['appUrl'], (result) => {
    const appUrl = result.appUrl || DEFAULT_URL;
    
    chrome.runtime.sendMessage({
      action: 'importFichajes',
      data: window.capturedData,
      sourceFormat: window.sourceFormat,
      appUrl: appUrl
    }, (response) => {
      importBtn.disabled = false;
      importBtn.textContent = '✅ Importar fichajes';
      
      if (response && (response.success || response.ok)) {
        let message = `✅ ${response.count} fichajes importados correctamente`;
        
        // Mostrar errores si los hay
        if (response.errors && response.errors.length > 0) {
          message += `\n\n⚠️ ${response.errors.length} advertencia(s):\n`;
          response.errors.slice(0, 3).forEach(err => {
            message += `• ${err}\n`;
          });
          if (response.errors.length > 3) {
            message += `... y ${response.errors.length - 3} más`;
          }
        }
        
        document.getElementById('preview').innerHTML = 
          `<strong style="color: green;">${message.replace(/\n/g, '<br>')}</strong>`;
        
        setTimeout(() => {
          document.getElementById('dataSection').style.display = 'none';
          document.getElementById('preview').innerHTML = '';
          window.capturedData = null;
          document.getElementById('importBtn').disabled = true;
          document.getElementById('importBtn').textContent = '✅ Importar fichajes';
        }, 3000);
      } else {
        const errorMsg = response?.error || 'Error desconocido';
        alert('❌ Error al importar: ' + errorMsg + '\n\n💡 Verifica la consola (F12) para más detalles');
        console.error('[Popup] Error de importación:', response);
      }
    });
  });
}

function toggleSettings() {
  const settings = document.getElementById('settings');
  settings.style.display = settings.style.display === 'none' ? 'block' : 'none';
}

function saveSettings() {
  const url = document.getElementById('appUrl').value.trim();
  if (!url) {
    alert('Por favor ingrese una URL');
    return;
  }
  
  chrome.storage.sync.set({ appUrl: url }, () => {
    alert('✅ Configuración guardada');
    toggleSettings();
  });
}

function resetSettings() {
  document.getElementById('appUrl').value = DEFAULT_URL;
  chrome.storage.sync.set({ appUrl: DEFAULT_URL }, () => {
    alert('✅ Reiniciado a valores por defecto');
  });
}
