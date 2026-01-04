/**
 * Content script para detectar páginas de fichajes y agregar botón de importación
 */

// Detectar si estamos en una página de fichajes
function detectFicharPage() {
  // Buscar tabla de fichajes (TRAGSA format)
  const tragsaTable = document.getElementById('tabla_fichajes');
  const isTragsaFormat = !!tragsaTable;
  
  // Buscar tablas estándar de fichajes
  const standardTable = document.querySelector('table[border="1"]');
  const hasDataColumns = standardTable && (
    Array.from(standardTable.querySelectorAll('th')).some(th => 
      ['Entrada', 'Salida', 'Fecha', 'Día'].some(text => th.textContent.includes(text))
    )
  );
  
  return isTragsaFormat || hasDataColumns;
}

// Extraer datos formato TRAGSA
function extractTragsaData() {
  const table = document.getElementById('tabla_fichajes');
  if (!table) return null;
  
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
  }
  
  return Object.keys(data).length > 0 ? data : null;
}

// Extraer datos formato estándar
function extractStandardData() {
  const table = document.querySelector('table[border="1"]');
  if (!table) return null;
  
  const headers = [];
  const data = {};
  
  // Obtener headers
  table.querySelectorAll('thead th').forEach(th => {
    headers.push(th.textContent.trim());
  });
  
  if (headers.length === 0) return null;
  
  // Obtener datos
  table.querySelectorAll('tbody tr').forEach(row => {
    const cells = row.querySelectorAll('td');
    if (cells.length === 0) return;
    
    const rowData = {};
    cells.forEach((cell, idx) => {
      rowData[headers[idx] || `col_${idx}`] = cell.textContent.trim();
    });
    
    // Intentar crear key por fecha
    const dateCol = Object.keys(rowData).find(k => k.includes('Fecha'));
    if (dateCol && rowData[dateCol]) {
      data[rowData[dateCol]] = {
        ...rowData,
        format: 'standard'
      };
    }
  });
  
  return Object.keys(data).length > 0 ? data : null;
}

// Agregar botón de importación
function addImportButton() {
  if (document.getElementById('gestionhoras-import-btn')) return; // Ya existe
  
  const button = document.createElement('button');
  button.id = 'gestionhoras-import-btn';
  button.textContent = '📥 Importar a GestionHorasTrabajo';
  button.style.cssText = `
    position: fixed;
    bottom: 20px;
    right: 20px;
    padding: 12px 20px;
    background-color: #007bff;
    color: white;
    border: none;
    border-radius: 5px;
    cursor: pointer;
    font-size: 14px;
    font-weight: bold;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
    z-index: 10000;
    transition: background-color 0.3s;
  `;
  
  button.onmouseover = () => button.style.backgroundColor = '#0056b3';
  button.onmouseout = () => button.style.backgroundColor = '#007bff';
  
  button.addEventListener('click', importData);
  
  document.body.appendChild(button);
}

// Importar datos
function importData() {
  const tragsaData = extractTragsaData();
  const standardData = extractStandardData();
  const data = tragsaData || standardData;
  
  if (!data || Object.keys(data).length === 0) {
    alert('No se encontraron datos de fichajes para importar');
    return;
  }
  
  // Enviar mensaje al background script
  chrome.runtime.sendMessage({
    action: 'importFichajes',
    data: data,
    sourceFormat: (tragsaData ? 'tragsa' : 'standard')
  }, response => {
    if (response && response.success) {
      alert(`✅ ${response.count} fichajes importados correctamente`);
    } else {
      alert('❌ Error al importar fichajes: ' + (response?.error || 'Error desconocido'));
    }
  });
}

// Inicializar
if (detectFicharPage()) {
  addImportButton();
}
