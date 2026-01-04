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
    
    // Obtener el año de la página o del selector de semana
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
    
    // Extraer tiempos de la fila de horas
    const hoursRow = table.querySelector('tr.horas');
    if (hoursRow) {
      const cells = hoursRow.querySelectorAll('td');
      cells.forEach((cell, idx) => {
        if (idx > 0 && idx <= dates.length) {
          const dateText = dates[idx - 1];
          const [day, month] = dateText.split('-');
          const fullDate = `${year}-${month}-${day}`;
          
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
    
    return Object.keys(data).length > 0 ? data : null;
  } catch (error) {
    console.error('[GestionHoras] Error en extractTragsaData:', error);
    return null;
  }
}

// Extraer datos formato estándar - MÁS FLEXIBLE
function extractStandardData() {
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
        console.log('[GestionHoras] Tabla estándar detectada: ' + Object.keys(data).length + ' registros');
        return data;
      }
    } catch (error) {
      console.error('[GestionHoras] Error procesando tabla:', error);
      continue;
    }
  }
  
  return null;
}
