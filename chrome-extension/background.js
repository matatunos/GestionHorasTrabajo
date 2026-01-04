/**
 * Background service worker para manejar importaciones
 */

const API_BASE = 'http://localhost'; // Cambia esto a tu dominio

// Escuchar mensajes del content script
chrome.runtime.onMessage.addListener((request, sender, sendResponse) => {
  if (request.action === 'importFichajes') {
    importFichajes(request.data, request.sourceFormat)
      .then(result => {
        sendResponse({ success: true, count: result.count });
      })
      .catch(error => {
        console.error('Import error:', error);
        sendResponse({ success: false, error: error.message });
      });
    return true; // Indica que responderemos de forma asincrónica
  }
});

// Convertir tiempos TRAGSA a formato estándar
function parseTragsaEntry(times) {
  if (!times || times.length === 0) return null;
  
  // Si tenemos 2 tiempos: entrada y salida
  if (times.length === 2) {
    return {
      start: times[0],
      end: times[1]
    };
  }
  
  // Si tenemos 4+ tiempos: entrada, cofee_out, coffee_in, salida comida, entrada comida, salida
  if (times.length >= 6) {
    return {
      start: times[0],
      coffee_out: times[1],
      coffee_in: times[2],
      lunch_out: times[3],
      lunch_in: times[4],
      end: times[5]
    };
  }
  
  // Si tenemos 4 tiempos
  if (times.length === 4) {
    return {
      start: times[0],
      coffee_out: times[1],
      coffee_in: times[2],
      end: times[3]
    };
  }
  
  return null;
}

// Convertir formato estándar a modelo interno
function parseStandardEntry(rowData, headers) {
  const mapping = {
    'entrada': 'start',
    'salida café': 'coffee_out',
    'entrada café': 'coffee_in',
    'salida comida': 'lunch_out',
    'entrada comida': 'lunch_in',
    'salida': 'end'
  };
  
  const result = {};
  Object.entries(rowData).forEach(([key, value]) => {
    const lowerKey = key.toLowerCase();
    const mappedKey = Object.keys(mapping).find(k => lowerKey.includes(k));
    if (mappedKey && value) {
      result[mapping[mappedKey]] = value;
    }
  });
  
  return Object.keys(result).length > 0 ? result : null;
}

// Función principal de importación
async function importFichajes(data, sourceFormat) {
  // Obtener la URL de la aplicación desde storage
  const settings = await chrome.storage.sync.get(['appUrl', 'userId']);
  const appUrl = settings.appUrl || API_BASE;
  
  let imported = 0;
  const errors = [];
  
  for (const [date, entry] of Object.entries(data)) {
    try {
      let entryData = {};
      
      if (sourceFormat === 'tragsa') {
        entryData = parseTragsaEntry(entry.times);
      } else if (sourceFormat === 'standard') {
        entryData = parseStandardEntry(entry, Object.keys(entry));
      }
      
      if (!entryData || !entryData.start) {
        errors.push(`${date}: No se encontraron tiempos válidos`);
        continue;
      }
      
      // Enviar al servidor
      const response = await fetch(`${appUrl}/index.php`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
          'X-Requested-With': 'XMLHttpRequest'
        },
        credentials: 'include',
        body: new URLSearchParams({
          date: formatDate(date),
          start: entryData.start,
          end: entryData.end || '',
          coffee_out: entryData.coffee_out || '',
          coffee_in: entryData.coffee_in || '',
          lunch_out: entryData.lunch_out || '',
          lunch_in: entryData.lunch_in || '',
          note: `Importado vía extensión Chrome - ${sourceFormat} format`
        }).toString()
      });
      
      if (!response.ok) {
        errors.push(`${date}: Error HTTP ${response.status}`);
      } else {
        imported++;
      }
    } catch (error) {
      errors.push(`${date}: ${error.message}`);
    }
  }
  
  if (errors.length > 0) {
    console.warn('Errores durante importación:', errors);
  }
  
  return { count: imported, errors };
}

// Formatear fecha a YYYY-MM-DD
function formatDate(dateStr) {
  // Si ya está en formato YYYY-MM-DD
  if (/^\d{4}-\d{2}-\d{2}$/.test(dateStr)) {
    return dateStr;
  }
  
  // Si es DD-mes
  if (/^\d{2}-[a-z]{3}$/i.test(dateStr)) {
    const [day, monthText] = dateStr.split('-');
    const months = {
      'ene': '01', 'feb': '02', 'mar': '03', 'apr': '04', 'may': '05', 'jun': '06',
      'jul': '07', 'aug': '08', 'sep': '09', 'oct': '10', 'nov': '11', 'dec': '12',
      'dic': '12'
    };
    const month = months[monthText.toLowerCase()];
    const year = new Date().getFullYear();
    return `${year}-${month}-${day}`;
  }
  
  // Si es DD/MM o DD/MM/YY
  if (/^\d{2}\/\d{2}(\/\d{2,4})?$/.test(dateStr)) {
    const parts = dateStr.split('/');
    const day = parts[0];
    const month = parts[1];
    let year = parts[2] ? parseInt(parts[2]) : new Date().getFullYear();
    if (year < 100) year += 2000;
    return `${year}-${month}-${day}`;
  }
  
  return dateStr;
}
