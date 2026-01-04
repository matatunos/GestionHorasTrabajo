/**
 * Popup script para configuración de la extensión
 */

// DEFAULT_URL puede ser definido en config.js si está disponible (inyectado dinámicamente)
// Si no está disponible, usar localhost como fallback
const DEFAULT_URL = (typeof DEFAULT_APP_URL !== 'undefined') ? DEFAULT_APP_URL : 'http://localhost';

// Elementos del DOM
const appUrlInput = document.getElementById('appUrl');
const saveBtn = document.getElementById('saveBtn');
const resetBtn = document.getElementById('resetBtn');
const statusDiv = document.getElementById('status');

// Cargar configuración guardada
chrome.storage.sync.get(['appUrl'], (result) => {
  appUrlInput.value = result.appUrl || DEFAULT_URL;
});

// Guardar configuración
saveBtn.addEventListener('click', () => {
  const url = appUrlInput.value.trim();
  
  if (!url) {
    showStatus('Por favor ingresa una URL válida', 'error');
    return;
  }
  
  chrome.storage.sync.set({ appUrl: url }, () => {
    showStatus('✅ Configuración guardada correctamente', 'success');
  });
});

// Restablecer a valores por defecto
resetBtn.addEventListener('click', () => {
  appUrlInput.value = DEFAULT_URL;
  chrome.storage.sync.set({ appUrl: DEFAULT_URL }, () => {
    showStatus('🔄 Restablecido a valores por defecto', 'success');
  });
});

// Mostrar estado
function showStatus(message, type) {
  statusDiv.textContent = message;
  statusDiv.className = `status ${type}`;
  setTimeout(() => {
    statusDiv.className = 'status';
  }, 3000);
}
