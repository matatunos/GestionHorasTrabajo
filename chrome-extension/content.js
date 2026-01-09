/**
 * Content script para capturar datos de fichajes cuando el usuario lo solicite
 */

// Escuchar mensajes del popup/background
chrome.runtime.onMessage.addListener((request, sender, sendResponse) => {
  console.log('[GestionHoras] Mensaje recibido:', request.action);
  
  if (request.action === 'captureFichajes') {
    try {
      console.log('[GestionHoras] Iniciando captura de datos...');
      
      const tragsaData = extractTragsaData();
      console.log('[GestionHoras] Datos TRAGSA:', tragsaData ? Object.keys(tragsaData).length + ' registros' : 'no encontrados');
      
      const standardData = extractStandardData();
      console.log('[GestionHoras] Datos estándar:', standardData ? Object.keys(standardData).length + ' registros' : 'no encontrados');
      
      const data = tragsaData || standardData;
      
      if (!data || Object.keys(data).length === 0) {
        const errorMsg = 'No se encontraron datos de fichajes en esta página';
        console.error('[GestionHoras] ' + errorMsg);
        console.log('[GestionHoras] Debug:', {
          tragsaDetected: !!tragsaData,
          tragsaTable: !!document.getElementById('tabla_fichajes'),
          standardDetected: !!standardData,
          standardTable: !!document.querySelector('table[border="1"]'),
          allTables: document.querySelectorAll('table').length,
          allTableIds: Array.from(document.querySelectorAll('table')).map(t => t.id || 'sin-id')
        });
        sendResponse({ 
          success: false, 
          error: errorMsg,
          debug: {
            tragsaDetected: !!tragsaData,
            tragsaTable: !!document.getElementById('tabla_fichajes'),
            standardDetected: !!standardData,
            standardTable: !!document.querySelector('table[border="1"]'),
            totalTables: document.querySelectorAll('table').length
          }
        });
      } else {
        console.log('[GestionHoras] ✅ Captura exitosa:', Object.keys(data).length + ' registros');
        console.log('[GestionHoras] 📦 Datos capturados:', JSON.stringify(data, null, 2).substring(0, 500) + '...');
        sendResponse({ 
          success: true, 
          data: data,
          sourceFormat: (tragsaData ? 'tragsa' : 'standard'),
          count: Object.keys(data).length
        });
      }
    } catch (error) {
      console.error('[GestionHoras] Error:', error);
      sendResponse({ 
        success: false, 
        error: error.message 
      });
    }
  }
});

// Extraer datos formato TRAGSA
function extractTragsaData() {
  const table = document.getElementById('tabla_fichajes');
  if (!table) return null;
  
  try {
    const rows = table.querySelectorAll('tr');
    const data = {};
    
    // Obtener las fechas de la fila de fechas
    const dateRow = table.querySelector('tr.fechas');
    const dates = [];
    if (dateRow) {
      dateRow.querySelectorAll('td').forEach((td, idx) => {
        if (idx > 0) { // Skip first empty cell
          const dateText = td.textContent.trim();
          if (dateText) dates.push(dateText);
        }
      });
    }
    
    if (dates.length === 0) {
      console.log('[GestionHoras] TRAGSA: No se encontraron fechas en tr.fechas');
      return null;
    }
    
    // Obtener el año - estrategia:
    // 1. Intentar detectar del HTML si hay indicación del año
    // 2. Si hay datos de diciembre/noviembre y estamos en enero-marzo → año pasado
    // 3. Si no, usar el año actual
    let year = new Date().getFullYear();
    
    // Primero: chequear si estamos en enero-marzo y hay meses de nov-dic
    // Si es así, el año debería ser el anterior
    const today = new Date();
    if (today.getMonth() <= 2) { // enero=0, febrero=1, marzo=2
      // Chequear si hay fechas con mes dic o nov
      const hasDecOrNov = dates.some(d => {
        const monthText = d.split('-')[1].toLowerCase();
        return ['dic', 'diciembre', 'dec', 'december', 'nov', 'noviembre', 'november'].includes(monthText);
      });
      
      if (hasDecOrNov) {
        // Si hay diciembre/noviembre en enero-marzo, el año es anterior
        year = year - 1;
        console.log('[GestionHoras] TRAGSA: Detectado mes de año anterior, usando año:', year);
      }
    }
    
    // Intenta encontrar el año en la página si no se ha ajustado arriba
    // Busca en el contexto de "tabla" o "fichajes"
    const tableText = table.innerText || '';
    const yearMatches = tableText.match(/20\d{2}/g);
    if (yearMatches && yearMatches.length > 0) {
      // Usa el año más frecuente en la tabla
      const potentialYear = parseInt(yearMatches[0]);
      if (potentialYear > 2000 && potentialYear < 2100) {
        console.log('[GestionHoras] TRAGSA: Año detectado de tabla:', potentialYear);
        // Si la tabla tiene un año explícito y es diferente al nuestro, úsalo
        if (potentialYear !== year && !hasDecOrNov) {
          year = potentialYear;
        }
      }
    }
    
    console.log('[GestionHoras] TRAGSA: Año final a usar:', year);
    
    // Extraer tiempos de la fila de horas
    const hoursRow = table.querySelector('tr.horas');
    if (hoursRow) {
      // Función para convertir nombre de mes a número
      const monthMap = {
        'ene': '01', 'enero': '01', 'jan': '01', 'january': '01',
        'feb': '02', 'febrero': '02',
        'mar': '03', 'marzo': '03',
        'apr': '04', 'abr': '04', 'abril': '04',
        'may': '05', 'mayo': '05',
        'jun': '06', 'junio': '06', 'june': '06',
        'jul': '07', 'julio': '07', 'july': '07',
        'ago': '08', 'agosto': '08', 'aug': '08', 'august': '08',
        'sep': '09', 'septiembre': '09', 'sept': '09',
        'oct': '10', 'octubre': '10',
        'nov': '11', 'noviembre': '11',
        'dic': '12', 'diciembre': '12', 'dec': '12', 'december': '12'
      };
      
      const cells = hoursRow.querySelectorAll('td');
      cells.forEach((cell, idx) => {
        if (idx > 0 && idx <= dates.length) {
          const dateText = dates[idx - 1];
          const [day, monthText] = dateText.split('-');
          
          // Convertir nombre del mes a número
          const monthKey = monthText.toLowerCase().trim();
          const monthNum = monthMap[monthKey];
          
          if (!monthNum) {
            console.warn(`[GestionHoras] Mes desconocido: "${monthText}"`);
            return;
          }
          
          const fullDate = `${year}-${monthNum}-${day.padStart(2, '0')}`;
          
          const times = [];
          cell.querySelectorAll('span').forEach(span => {
            const time = span.textContent.trim();
            if (time && /\d{2}:\d{2}/.test(time)) times.push(time);
          });
          
          if (times.length > 0) {
            data[fullDate] = {
              times: times,
              format: 'tragsa'
            };
          }
        }
      });
    } else {
      console.log('[GestionHoras] TRAGSA: No se encontró tr.horas');
      return null;
    }
    
    // Nota: La lógica de ajuste de año para marzo/diciembre está arriba
    // en la sección de obtención del año
    
    return Object.keys(data).length > 0 ? data : null;
  } catch (error) {
    console.error('[GestionHoras] Error en extractTragsaData:', error);
    return null;
  }
}

// Extraer datos formato estándar - USA importFichajes.js
function extractStandardData() {
  // Usar la lógica sofisticada de importFichajes.js
  if (typeof window.importFichajes === 'undefined' || !window.importFichajes.parseFichajesHTML) {
    console.log('[GestionHoras] importFichajes.js no disponible, usando parser básico');
    return extractStandardDataBasic();
  }
  
  try {
    // Obtener año - preferir del selector de semana o usar actual
    let year = new Date().getFullYear();
    const weekSelect = document.getElementById('ddl_semanas');
    if (weekSelect && weekSelect.value) {
      const [day, month] = weekSelect.value.split('-');
      const selectedDate = new Date(year, 
        new Date(`${month} 1, ${year}`).getMonth(), 
        parseInt(day)
      );
      if (selectedDate > new Date()) year--;
    }
    
    // Obtener HTML de la página
    const htmlContent = document.documentElement.innerHTML;
    
    // Usar parseFichajesHTML que tiene:
    // - Detección de cambio de año (enero+diciembre)
    // - Parsing robusto de fechas
    // - Extracción de horarios
    const registros = window.importFichajes.parseFichajesHTML(htmlContent, year);
    
    if (!registros || registros.length === 0) {
      console.log('[GestionHoras] parseFichajesHTML no encontró datos');
      return null;
    }
    
    // Convertir array de registros a formato compatible con extensión
    const data = {};
    registros.forEach(reg => {
      if (reg.fechaISO && reg.horas && reg.horas.length > 0) {
        data[reg.fechaISO] = {
          times: reg.horas,
          format: 'standard-via-importFichajes'
        };
      }
    });
    
    if (Object.keys(data).length > 0) {
      console.log('[GestionHoras] ✅ Datos parseados por importFichajes: ' + Object.keys(data).length + ' registros');
      return data;
    }
    
    return null;
  } catch (error) {
    console.error('[GestionHoras] Error en extractStandardData:', error);
    // Fallback a parser básico
    return extractStandardDataBasic();
  }
}

// Parser estándar básico (fallback)
function extractStandardDataBasic() {
  // Buscar cualquier tabla con datos
  const tables = document.querySelectorAll('table');
  if (tables.length === 0) return null;
  
  for (let table of tables) {
    try {
      const headers = [];
      const data = {};
      
      // Intentar obtener headers de diferentes formas
      let headerRow = table.querySelector('thead tr');
      if (!headerRow) headerRow = table.querySelector('tr:first-child');
      
      if (!headerRow) continue;
      
      const headerCells = headerRow.querySelectorAll('th, td');
      headerCells.forEach(th => {
        const text = th.textContent.trim();
        if (text) headers.push(text);
      });
      
      if (headers.length < 2) continue; // Tabla muy pequeña
      
      // Buscar filas de datos (tbody o directamente tr después del header)
      let dataRows = table.querySelectorAll('tbody tr');
      if (dataRows.length === 0) {
        // Si no hay tbody, buscar todas las tr excepto la primera
        dataRows = Array.from(table.querySelectorAll('tr')).slice(1);
      }
      
      if (dataRows.length === 0) continue;
      
      // Extraer datos
      dataRows.forEach(row => {
        const cells = row.querySelectorAll('td');
        if (cells.length === 0) return;
        
        const rowData = {};
        cells.forEach((cell, idx) => {
          const headerName = headers[idx] || `col_${idx}`;
          rowData[headerName] = cell.textContent.trim();
        });
        
        // Intentar encontrar columna de fecha
        const dateCol = Object.keys(rowData).find(k => 
          k.toLowerCase().includes('fecha') || 
          k.toLowerCase().includes('date') ||
          k.toLowerCase().includes('day')
        );
        
        if (dateCol && rowData[dateCol]) {
          data[rowData[dateCol]] = {
            ...rowData,
            format: 'standard'
          };
        }
      });
      
      if (Object.keys(data).length > 0) {
        console.log('[GestionHoras] Tabla estándar detectada (básica): ' + Object.keys(data).length + ' registros');
        return data;
      }
    } catch (error) {
      console.error('[GestionHoras] Error procesando tabla:', error);
      continue;
    }
  }
  
  return null;
}
