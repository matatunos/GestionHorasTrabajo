/**
 * Background service worker para manejar importaciones
 */

// Escuchar mensajes del content script y popup
chrome.runtime.onMessage.addListener((request, sender, sendResponse) => {
  if (request.action === 'importFichajes') {
    importFichajes(request.data, request.sourceFormat, request.appUrl)
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
async function importFichajes(data, sourceFormat, appUrl) {
  // Usar la URL proporcionada o buscar en storage
  let finalUrl = appUrl;
  if (!finalUrl) {
    const settings = await chrome.storage.sync.get(['appUrl']);
    finalUrl = settings.appUrl || 'http://localhost';
  }
  
  // Construir entrada para cada fecha
  const entries = [];
  for (const [date, entry] of Object.entries(data)) {
    try {
      let entryData = {};
      
      if (sourceFormat === 'tragsa') {
        entryData = parseTragsaEntry(entry.times);
      } else if (sourceFormat === 'standard') {
        entryData = parseStandardEntry(entry, Object.keys(entry));
      }
      
      if (!entryData || !entryData.start) {
        console.warn(`[Background] ${date}: No se encontraron tiempos válidos`);
        continue;
      }
      
      // Agregar entrada con fecha formateada
      entries.push({
        date: formatDate(date),
        start: entryData.start,
        end: entryData.end || null,
        coffee_out: entryData.coffee_out || null,
        coffee_in: entryData.coffee_in || null,
        lunch_out: entryData.lunch_out || null,
        lunch_in: entryData.lunch_in || null,
        note: `Importado vía extensión Chrome - ${sourceFormat} format`
      });
    } catch (error) {
      console.error(`[Background] Error procesando ${date}:`, error);
    }
  }
  
  if (entries.length === 0) {
    return { count: 0, errors: ['No se encontraron entradas válidas para importar'] };
  }
  
  try {
    // Enviar al nuevo endpoint /api.php
    // Incluir token si está disponible
    const headers = {
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest'
    };
    
    const body = { entries: entries };
    
    // Si está disponible, incluir token en el payload
    if (typeof EXTENSION_TOKEN !== 'undefined' && EXTENSION_TOKEN) {
      body.token = EXTENSION_TOKEN;
    }
    
    const response = await fetch(`${finalUrl}/api.php`, {
      method: 'POST',
      headers: headers,
      credentials: 'include',  // Para sesión
      body: JSON.stringify(body)
    });
    
    if (!response.ok) {
      throw new Error(`HTTP Error: ${response.status}`);
    }
    
    const result = await response.json();
    
    if (result.ok) {
      console.log(`[Background] ✅ Importación exitosa: ${result.imported}/${result.total}`);
      return { count: result.imported, errors: result.errors || [] };
    } else {
      throw new Error(result.message || 'Error del servidor');
    }
  } catch (error) {
    console.error('[Background] Error de importación:', error);
    return { count: 0, errors: [error.message] };
  }
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
      'jul': '07', 'ago': '08', 'sep': '09', 'oct': '10', 'nov': '11', 'dic': '12',
      'jan': '01', 'feb': '02', 'mar': '03', 'apr': '04', 'may': '05', 'jun': '06',
      'jul': '07', 'aug': '08', 'sep': '09', 'oct': '10', 'nov': '11', 'dec': '12'
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
