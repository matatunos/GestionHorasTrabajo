/**
 * Utilidad para importar registros de fichajes desde un archivo HTML.
 *
 * Nota: este parser está pensado para funcionar en el navegador.
 */

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

function parseFechaToISO(fechaTexto, year) {
  if (!fechaTexto) return null;
  const t = String(fechaTexto).trim();
  if (!t) return null;

  // YYYY-MM-DD already
  if (/^\d{4}-\d{2}-\d{2}$/.test(t)) return t;

  // DD/MM/YYYY or DD-MM-YYYY
  let match = t.match(/(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})/);
  if (match) {
    const dd = match[1].padStart(2, '0');
    const mm = match[2].padStart(2, '0');
    const yy = match[3];
    return `${yy}-${mm}-${dd}`;
  }

  // DD/MM or DD-MM
  match = t.match(/(\d{1,2})[\/\-](\d{1,2})/);
  if (match) {
    const dd = match[1].padStart(2, '0');
    const mm = match[2].padStart(2, '0');
    return `${year}-${mm}-${dd}`;
  }

  // DD MMM (e.g. 15 Ene)
  match = t.match(/(\d{1,2})\s+(\w+)/);
  if (match) {
    const dd = match[1].padStart(2, '0');
    const mesTexto = match[2].toLowerCase();
    const mm = MESES_MAP[mesTexto] || MESES_MAP[mesTexto.slice(0, 3)];
    if (mm) return `${year}-${mm}-${dd}`;
  }

  return null;
}

/**
 * Parsea un archivo HTML y extrae los registros de fichajes.
 */
function parseFichajesHTML(htmlContent, year) {
  const parser = new DOMParser();
  const doc = parser.parseFromString(htmlContent, 'text/html');

  const table = doc.querySelector('table');
  if (!table) throw new Error('No se encontró ninguna tabla en el archivo HTML');

  const registros = [];
  const rows = Array.from(table.querySelectorAll('tr'));

  // Find where data starts
  let dataStartIndex = 0;
  for (let i = 0; i < rows.length; i++) {
    const row = rows[i];
    if (row.parentElement && row.parentElement.tagName === 'TBODY') {
      dataStartIndex = i;
      break;
    }
    const headers = row.querySelectorAll('th');
    if (headers.length > 0) {
      dataStartIndex = i + 1;
      break;
    }
  }
  if (dataStartIndex === 0) dataStartIndex = 1;

  for (let i = dataStartIndex; i < rows.length; i++) {
    const cells = Array.from(rows[i].querySelectorAll('td'));
    if (cells.length === 0) continue;

    const cellTexts = cells.map(cell => String(cell.textContent || '').trim());
    if (cellTexts.length < 2) continue;

    let dia = '';
    let fecha = '';
    let horas = [];
    let balance = '';

    if (cellTexts.length >= 3) {
      const primeraCelda = (cellTexts[0] || '').toLowerCase();
      if (DIAS_SEMANA.some(d => primeraCelda.includes(d))) {
        dia = cellTexts[0];
        fecha = cellTexts[1];
        balance = cellTexts[cellTexts.length - 1];
        horas = cellTexts.slice(2, cellTexts.length - 1).filter(h => h && h !== EMPTY_CELL_MARKER);
      } else {
        fecha = cellTexts[0];
        balance = cellTexts[cellTexts.length - 1];
        horas = cellTexts.slice(1, cellTexts.length - 1).filter(h => h && h !== EMPTY_CELL_MARKER);
      }
    } else {
      fecha = cellTexts[0];
      if (cellTexts.length > 1) {
        if (cellTexts[1].includes(':') && !cellTexts[1].includes('-')) horas = [cellTexts[1]];
        else balance = cellTexts[1];
      }
    }

    let fechaISO = parseFechaToISO(fecha, year);
    if (!fechaISO) continue;

    if (!dia) {
      const d = new Date(fechaISO);
      dia = DIAS_SEMANA_LABELS[d.getDay()];
    }

    registros.push({ dia, fecha, fechaISO, horas, balance });
  }

  // ✅ LOGICA DE AÑO MEJORADA: Detectar si una fecha es del año anterior
  // Partimos de la base de que todo va al año actual (year)
  // Pero si la fecha parseada es POSTERIOR a hoy, asumimos que es del año anterior
  // Esto maneja automáticamente saltos de año (Dec→Jan) sin necesidad de lógica especial
  // 
  // Ejemplos (hoy = 4 de enero de 2026, year = 2026):
  //   - "12-12" (dic 12) → 2026-12-12 > 2026-01-04 → TRUE → 2025-12-12 ✅
  //   - "01-05" (ene 5) → 2026-01-05 > 2026-01-04 → TRUE → 2025-01-05 ❌ INCORRECTO
  //   - "01-03" (ene 3) → 2026-01-03 > 2026-01-04 → FALSE → 2026-01-03 ✅
  //
  // Para evitar el problema anterior, comparamos solo mes/día en el contexto del año actual
  const today = new Date();
  
  registros.forEach(r => {
    if (!r.fechaISO) return;
    
    const iso = String(r.fechaISO);
    // Si la fecha está en el año especificado
    if (iso.startsWith(String(year) + '-')) {
      const parsedDate = new Date(iso);
      const dateMonth = parsedDate.getMonth();
      const dateDay = parsedDate.getDate();
      const todayMonth = today.getMonth();
      const todayDay = today.getDate();
      
      // ✅ LOGICA CORRECTA: Detectar si la fecha está "en el pasado" dentro del año
      // Si el mes es anterior → pasado
      // Si el mes es igual pero el día es anterior → pasado  
      // Si el mes es posterior (ej: dic>ene) → futuro en el año, pero podría ser del año pasado
      // 
      // Mejor: Si estamos en enero-febrero y vemos nov-dic → año pasado
      //        Si vemos un mes menor que hoy → año actual
      //        Si vemos un mes mayor que hoy:
      //          - Si hoy es enero-marzo y fecha es nov-dic → año pasado
      //          - Si hoy es otro mes → año actual
      
      let isFromPreviousYear = false;
      
      if (dateMonth < todayMonth) {
        // Mes anterior → definitivamente del año pasado
        isFromPreviousYear = true;
      } else if (dateMonth === todayMonth && dateDay < todayDay) {
        // Mismo mes pero día anterior → año pasado
        isFromPreviousYear = true;
      } else if (dateMonth > todayMonth && todayMonth <= 2 && dateMonth >= 10) {
        // Caso especial: enero-marzo con nov-dic → año pasado
        // (ej: hoy 4 de enero vemos diciembre → es del año pasado)
        isFromPreviousYear = true;
      }
      
      if (isFromPreviousYear) {
        r.fechaISO = String(year - 1) + iso.slice(4);
      }
    }
  });

  return registros;
}

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
    if (!registro || !registro.fechaISO) errors.push(`Registro ${index + 1}: falta fechaISO`);
    if (!registro || !Array.isArray(registro.horas)) errors.push(`Registro ${index + 1}: horas debe ser un array`);
  });
  return { valid: errors.length === 0, errors };
}

/**
 * Parsea texto plano copiado de la web y extrae los registros de fichajes.
 * Cada línea debe corresponder a una fila de la tabla, separada por tabuladores o múltiples espacios.
 */
function parseFichajesTextoPlano(texto, year) {
  const registros = [];
  const lineas = texto.split(/\r?\n/).map(l => l.trim()).filter(l => l);
  for (const linea of lineas) {
    // Separar por tabulador o 2+ espacios
    const celdas = linea.split(/\t|  +/).map(c => c.trim());
    if (celdas.length < 2) continue;
    let dia = '';
    let fecha = '';
    let horas = [];
    let balance = '';
    if (celdas.length >= 3) {
      const primeraCelda = (celdas[0] || '').toLowerCase();
      if (DIAS_SEMANA.some(d => primeraCelda.includes(d))) {
        dia = celdas[0];
        fecha = celdas[1];
        balance = celdas[celdas.length - 1];
        horas = celdas.slice(2, celdas.length - 1).filter(h => h && h !== EMPTY_CELL_MARKER);
      } else {
        fecha = celdas[0];
        balance = celdas[celdas.length - 1];
        horas = celdas.slice(1, celdas.length - 1).filter(h => h && h !== EMPTY_CELL_MARKER);
      }
    } else {
      fecha = celdas[0];
      if (celdas.length > 1) {
        if (celdas[1].includes(':') && !celdas[1].includes('-')) horas = [celdas[1]];
        else balance = celdas[1];
      }
    }
    let fechaISO = parseFechaToISO(fecha, year);
    if (!fechaISO) continue;
    if (!dia) {
      const d = new Date(fechaISO);
      dia = DIAS_SEMANA_LABELS[d.getDay()];
    }
    registros.push({ dia, fecha, fechaISO, horas, balance });
  }
  return registros;
}

/**
 * Parsea texto plano en formato tabla transpuesta (días como columnas, fechas en la segunda fila).
 * Devuelve registros por día con sus fichajes.
 */
function parseFichajesTranspuesta(texto, year) {
  const lineas = texto.split(/\r?\n/).map(l => l.trim()).filter(l => l);
  // Buscar la fila de días y la fila de fechas
  let idxDias = -1, idxFechas = -1;
  const regexDias = /lunes.*martes.*mi[eé]rcoles.*jueves.*viernes/i;
  const regexFecha = /\d{1,2}[-\/]?(ene|enero|feb|febrero|mar|marzo|abr|abril|may|mayo|jun|junio|jul|julio|ago|agosto|sep|septiembre|oct|octubre|nov|noviembre|dic|diciembre)/i;
  for (let i = 0; i < lineas.length; i++) {
    if (regexDias.test(lineas[i])) idxDias = i;
    if (regexFecha.test(lineas[i])) idxFechas = i;
    if (idxDias !== -1 && idxFechas !== -1) break;
  }
  if (idxDias === -1 || idxFechas === -1) return [];
  // Extraer fechas (permitir separadores flexibles)
  const fechas = lineas[idxFechas].split(/[\t ]+/).map(f => f.trim()).filter(f => f);
  // Extraer filas de fichajes (las siguientes a la fila de fechas, hasta que no haya más horas)
  const fichajesPorFila = [];
  for (let i = idxFechas + 1; i < lineas.length; i++) {
    if (!/\d{1,2}:\d{2}/.test(lineas[i])) break;
    const fichajes = lineas[i].split(/[\t ]+/).map(f => f.trim());
    fichajesPorFila.push(fichajes);
  }
  // Transponer: cada columna es un día
  const registros = [];
  for (let col = 0; col < fechas.length; col++) {
    const horas = [];
    for (let fila = 0; fila < fichajesPorFila.length; fila++) {
      const valor = fichajesPorFila[fila][col];
      if (valor && /\d{1,2}:\d{2}/.test(valor)) horas.push(valor);
    }
    const fechaTexto = fechas[col];
    const fechaISO = parseFechaToISO(fechaTexto, year);
    if (!fechaISO) continue;
    const d = new Date(fechaISO);
    const dia = DIAS_SEMANA_LABELS[d.getDay()];
    registros.push({ dia, fecha: fechaTexto, fechaISO, horas, balance: '' });
  }
  return registros;
}

/**
 * Parsea texto plano en formato vertical/apilado (fechas en una línea, luego bloques de fichajes por línea, cada columna es un día).
 */
function parseFichajesVerticalApilado(texto, year) {
  const lineas = texto.split(/\r?\n/).map(l => l.trim()).filter(l => l);
  // Buscar la fila de fechas
  let idxFechas = -1;
  const regexFecha = /\d{1,2}[-\/]?(ene|enero|feb|febrero|mar|marzo|abr|abril|may|mayo|jun|junio|jul|julio|ago|agosto|sep|septiembre|oct|octubre|nov|noviembre|dic|diciembre)/i;
  for (let i = 0; i < lineas.length; i++) {
    if (regexFecha.test(lineas[i])) { idxFechas = i; break; }
  }
  if (idxFechas === -1) return [];
  // Extraer fechas (permitir separadores flexibles)
  const fechas = lineas[idxFechas].split(/[\t ]+/).map(f => f.trim()).filter(f => f);
  // Leer bloques de fichajes (6 líneas por bloque, o menos si hay días con menos fichajes)
  const bloques = [];
  let i = idxFechas + 1;
  while (i < lineas.length) {
    // Saltar líneas vacías
    if (!/\d{1,2}:\d{2}/.test(lineas[i])) { i++; continue; }
    // Leer hasta 6 líneas de fichajes
    const bloque = [];
    for (let j = 0; j < 6 && i < lineas.length; j++, i++) {
      if (/\d{1,2}:\d{2}/.test(lineas[i])) {
        bloque.push(lineas[i].split(/[\t ]+/).map(f => f.trim()));
      } else {
        break;
      }
    }
    if (bloque.length > 0) bloques.push(bloque);
  }
  // Para cada día (columna), recolectar los fichajes de cada bloque
  const registros = [];
  for (let col = 0; col < fechas.length; col++) {
    const horas = [];
    for (const bloque of bloques) {
      if (bloque.length > 0 && bloque[0][col] && /\d{1,2}:\d{2}/.test(bloque[0][col])) {
        for (let fila = 0; fila < bloque.length; fila++) {
          const valor = bloque[fila][col];
          if (valor && /\d{1,2}:\d{2}/.test(valor)) horas.push(valor);
        }
      }
    }
    const fechaTexto = fechas[col];
    const fechaISO = parseFechaToISO(fechaTexto, year);
    if (!fechaISO) continue;
    const d = new Date(fechaISO);
    const dia = DIAS_SEMANA_LABELS[d.getDay()];
    registros.push({ dia, fecha: fechaTexto, fechaISO, horas, balance: '' });
  }
  return registros;
}

/**
 * Parsea texto plano pegado desde navegador: fechas en una línea, luego líneas de fichajes (una por hora), agrupando en bloques de N (N=fechas).
 */
function parseFichajesPegadoNavegador(texto, year) {
  const lineas = texto.split(/\r?\n/).map(l => l.trim()).filter(l => l);
  // Buscar la fila de fechas
  let idxFechas = -1;
  const regexFecha = /\d{1,2}[-\/]?(ene|enero|feb|febrero|mar|marzo|abr|abril|may|mayo|jun|junio|jul|julio|ago|agosto|sep|septiembre|oct|octubre|nov|noviembre|dic|diciembre)/i;
  for (let i = 0; i < lineas.length; i++) {
    if (regexFecha.test(lineas[i])) { idxFechas = i; break; }
  }
  if (idxFechas === -1) return [];
  // Extraer fechas
  const fechas = lineas[idxFechas].split(/[\t ]+/).map(f => f.trim()).filter(f => f);
  // Leer todas las líneas siguientes que sean horas (hh:mm)
  const horasLineas = [];
  for (let i = idxFechas + 1; i < lineas.length; i++) {
    if (/^\d{1,2}:\d{2}$/.test(lineas[i])) horasLineas.push(lineas[i]);
  }
  // Agrupar en bloques de N (N=fechas)
  const fichajesPorDia = Array.from({ length: fechas.length }, () => []);
  for (let i = 0; i < horasLineas.length; i++) {
    const dia = i % fechas.length;
    fichajesPorDia[dia].push(horasLineas[i]);
  }
  // Crear registros
  const registros = [];
  for (let col = 0; col < fechas.length; col++) {
    const horas = fichajesPorDia[col].filter(h => /\d{1,2}:\d{2}/.test(h));
    const fechaTexto = fechas[col];
    const fechaISO = parseFechaToISO(fechaTexto, year);
    if (!fechaISO) continue;
    const d = new Date(fechaISO);
    const dia = DIAS_SEMANA_LABELS[d.getDay()];
    registros.push({ dia, fecha: fechaTexto, fechaISO, horas, balance: '' });
  }
  return registros;
}

if (typeof window !== 'undefined') {
  window.importFichajes = { ...window.importFichajes, parseFichajesTextoPlano, parseFichajesTranspuesta, parseFichajesVerticalApilado, parseFichajesPegadoNavegador };
}
