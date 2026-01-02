/**
 * Utilidad para importar registros de fichajes desde un archivo HTML
 * 
 * Parsea archivos HTML descargados del portal de horas externo y extrae
 * la información estructurada de la tabla de fichajes.
 */

// Constants
const DIAS_SEMANA = ['lun', 'mar', 'mié', 'mie', 'jue', 'vie', 'sáb', 'sab', 'dom', 
                     'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

const DIAS_SEMANA_LABELS = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];

const MESES_MAP = {
  'ene': '01', 'enero': '01', 'jan': '01', 'january': '01',
  'feb': '02', 'febrero': '02', 'february': '02',
  'mar': '03', 'marzo': '03', 'march': '03',
  'abr': '04', 'abril': '04', 'apr': '04', 'april': '04',
  'may': '05', 'mayo': '05',
  'jun': '06', 'junio': '06', 'june': '06',
  'jul': '07', 'julio': '07', 'july': '07',
  'ago': '08', 'agosto': '08', 'aug': '08', 'august': '08',
  'sep': '09', 'septiembre': '09', 'september': '09',
  'oct': '10', 'octubre': '10', 'october': '10',
  'nov': '11', 'noviembre': '11', 'november': '11',
  'dic': '12', 'diciembre': '12', 'dec': '12', 'december': '12'
};

const EMPTY_CELL_MARKER = '-';

/**
 * Parsea un archivo HTML y extrae los registros de fichajes
 * @param {string} htmlContent - Contenido del archivo HTML
 * @param {number} year - Año correspondiente al informe
 * @returns {Array} Array de objetos con los campos: dia, fecha, fechaISO, horas, balance
 */
function parseFichajesHTML(htmlContent, year) {
  const parser = new DOMParser();
  const doc = parser.parseFromString(htmlContent, 'text/html');
  
  // Buscar la tabla de fichajes - intentar varios selectores comunes
  let table = doc.querySelector('table');
  
  if (!table) {
    throw new Error('No se encontró ninguna tabla en el archivo HTML');
  }
  
  const registros = [];
  const rows = Array.from(table.querySelectorAll('tr'));
  
  // Buscar la fila de encabezado para identificar columnas
  let dataStartIndex = 0;
  for (let i = 0; i < rows.length; i++) {
    const row = rows[i];
    // Detener si llegamos a un tbody (ya encontramos el thead)
    if (row.parentElement.tagName === 'TBODY') {
      dataStartIndex = i;
      break;
    }
    const headers = row.querySelectorAll('th');
    if (headers.length > 0) {
      dataStartIndex = i + 1;
      break;
    }
  }
  
  // Si no hay encabezados <th>, asumir que las filas de datos empiezan después de la primera fila
  if (dataStartIndex === 0) {
    dataStartIndex = 1;
  }
  
  // Procesar cada fila de datos
  for (let i = dataStartIndex; i < rows.length; i++) {
    const row = rows[i];
    const cells = Array.from(row.querySelectorAll('td'));
    
    if (cells.length === 0) continue;
    
    // Extraer contenido de cada celda
    const cellTexts = cells.map(cell => cell.textContent.trim());
    
    // Intentar identificar el formato de la tabla
    // Formato esperado: [Día, Fecha, Hora1, Hora2, ..., Balance]
    // O: [Fecha, Horas, Balance]
    
    if (cellTexts.length < 2) continue;
    
    let dia = '';
    let fecha = '';
    let fechaISO = '';
    let horas = [];
    let balance = '';
    
    // Estrategia 1: Primera columna es día de la semana, segunda es fecha
    if (cellTexts.length >= 3) {
      // Verificar si primera columna parece un día de semana
      const primeraCelda = cellTexts[0].toLowerCase();
      
      if (DIAS_SEMANA.some(d => primeraCelda.includes(d))) {
        dia = cellTexts[0];
        fecha = cellTexts[1];
        
        // El balance suele estar en la última columna
        balance = cellTexts[cellTexts.length - 1];
        
        // Las horas están entre la fecha y el balance
        horas = cellTexts.slice(2, cellTexts.length - 1).filter(h => h && h !== EMPTY_CELL_MARKER);
      } else {
        // Estrategia 2: Primera columna es fecha directamente
        fecha = cellTexts[0];
        balance = cellTexts[cellTexts.length - 1];
        horas = cellTexts.slice(1, cellTexts.length - 1).filter(h => h && h !== EMPTY_CELL_MARKER);
      }
    } else {
      // Caso simple: solo fecha y una columna más
      fecha = cellTexts[0];
      if (cellTexts.length > 1) {
        // Determinar si la segunda columna es balance u hora
        if (cellTexts[1].includes(':') && !cellTexts[1].includes('-')) {
          horas = [cellTexts[1]];
        } else {
          balance = cellTexts[1];
        }
      }
    }
    
    // Parsear la fecha y convertir a formato ISO
    fechaISO = parseFechaToISO(fecha, year);
    
    if (!fechaISO) continue; // Saltar si no se pudo parsear la fecha
    
    // Extraer día de la semana de la fecha ISO si no lo tenemos
    if (!dia && fechaISO) {
      const date = new Date(fechaISO);
      dia = DIAS_SEMANA_LABELS[date.getDay()];
    }
    
    registros.push({
      dia: dia,
      fecha: fecha,
      fechaISO: fechaISO,
      horas: horas,
      balance: balance
    });
  }
  
  return registros;
}

/**
 * Convierte una fecha en formato texto a formato ISO (YYYY-MM-DD)
 * @param {string} fechaTexto - Fecha en formato "DD/MM", "DD-MM", "DD MMM", etc.
 * @param {number} year - Año a usar
 * @returns {string} Fecha en formato ISO o null si no se pudo parsear
 */
function parseFechaToISO(fechaTexto, year) {
  if (!fechaTexto) return null;
  
  // Limpiar la fecha
  fechaTexto = fechaTexto.trim();
  
  // Patrón 1: DD/MM o DD-MM
  let match = fechaTexto.match(/(\d{1,2})[\/\-](\d{1,2})/);
  if (match) {
    const dia = match[1].padStart(2, '0');
    const mes = match[2].padStart(2, '0');
    return `${year}-${mes}-${dia}`;
  }
  
  // Patrón 2: DD MMM (ej: "15 Ene", "3 Feb")
  match = fechaTexto.match(/(\d{1,2})\s+(\w+)/);
  if (match) {
    const dia = match[1].padStart(2, '0');
    const mesTexto = match[2].toLowerCase();
    
    const mes = MESES_MAP[mesTexto];
    if (mes) {
      return `${year}-${mes}-${dia}`;
    }
  }
  
  // Patrón 3: Ya está en formato YYYY-MM-DD
  if (/^\d{4}-\d{2}-\d{2}$/.test(fechaTexto)) {
    return fechaTexto;
  }
  
  // Patrón 4: DD/MM/YYYY o DD-MM-YYYY (usar el año del archivo, no el proporcionado)
  match = fechaTexto.match(/(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})/);
  if (match) {
    const dia = match[1].padStart(2, '0');
    const mes = match[2].padStart(2, '0');
    const yearFromDate = match[3];
    return `${yearFromDate}-${mes}-${dia}`;
  }
  
  return null;
}

/**
 * Valida que un array de registros tenga el formato correcto
 * @param {Array} registros - Array de registros a validar
 * @returns {Object} {valid: boolean, errors: Array}
 */
function validarRegistros(registros) {
  const errors = [];
  
  if (!Array.isArray(registros)) {
    errors.push('Los registros deben ser un array');
    return { valid: false, errors };
  }
  
  if (registros.length === 0) {
    errors.push('No se encontraron registros en el archivo');
    return { valid: false, errors };
  }
  
  registros.forEach((registro, index) => {
    if (!registro.fechaISO) {
      errors.push(`Registro ${index + 1}: falta fechaISO`);
    }
    if (!Array.isArray(registro.horas)) {
      errors.push(`Registro ${index + 1}: horas debe ser un array`);
    }
  });
  
  return {
    valid: errors.length === 0,
    errors: errors
  };
}

// Exportar funciones para uso en el navegador
if (typeof window !== 'undefined') {
  window.importFichajes = {
    parseFichajesHTML,
    parseFechaToISO,
    validarRegistros
  };
}
