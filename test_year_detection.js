#!/usr/bin/env node

/**
 * Test de la lógica mejorada de detección de año
 * Simula diferentes escenarios de fechas con años
 */

// Simular el comportamiento de la lógica nueva
function testYearDetection() {
  console.log("=".repeat(60));
  console.log("TEST: Detección de Año por Comparación con Hoy");
  console.log("=".repeat(60));
  
  // Fecha actual: 4 de enero de 2026
  const today = new Date('2026-01-04');
  const year = 2026;
  
  console.log(`\n📅 Fecha de referencia (HOY): ${today.toLocaleDateString('es-ES')}`);
  console.log(`📆 Año especificado: ${year}\n`);
  
  // Casos de prueba
  const testCases = [
    { date: '2026-12-15', expected: '2025-12-15', desc: 'Diciembre 15 (pasado en año actual, año anterior)' },
    { date: '2026-12-01', expected: '2025-12-01', desc: 'Diciembre 01 (pasado en año actual, año anterior)' },
    { date: '2026-01-05', expected: '2026-01-05', desc: 'Enero 05 (futuro en año actual, mantener)' },
    { date: '2026-01-31', expected: '2026-01-31', desc: 'Enero 31 (futuro en año actual, mantener)' },
    { date: '2026-01-04', expected: '2026-01-04', desc: 'Enero 04 (HOY exacto, mantener)' },
    { date: '2026-01-03', expected: '2025-01-03', desc: 'Enero 03 (pasado en año actual, año anterior)' },
  ];
  
  let passed = 0;
  let failed = 0;
  
  testCases.forEach(tc => {
    const parsedDate = new Date(tc.date);
    let result = tc.date;
    
    // Aplicar la lógica mejorada
    const dateMonth = parsedDate.getMonth();
    const dateDay = parsedDate.getDate();
    const todayMonth = today.getMonth();
    const todayDay = today.getDate();
    
    let isFromPreviousYear = false;
    
    if (dateMonth < todayMonth) {
      // Mes anterior → definitivamente del año pasado
      isFromPreviousYear = true;
    } else if (dateMonth === todayMonth && dateDay < todayDay) {
      // Mismo mes pero día anterior → año pasado
      isFromPreviousYear = true;
    } else if (dateMonth > todayMonth && todayMonth <= 2 && dateMonth >= 10) {
      // Caso especial: enero-marzo con nov-dic → año pasado
      isFromPreviousYear = true;
    }
    
    if (isFromPreviousYear) {
      const parts = tc.date.split('-');
      result = `${parseInt(parts[0]) - 1}-${parts[1]}-${parts[2]}`;
    }
    
    const isPass = result === tc.expected;
    const status = isPass ? '✅ PASS' : '❌ FAIL';
    
    console.log(`${status} | ${tc.desc}`);
    console.log(`       Input:    ${tc.date}`);
    console.log(`       Expected: ${tc.expected}`);
    console.log(`       Got:      ${result}\n`);
    
    if (isPass) passed++;
    else failed++;
  });
  
  console.log("=".repeat(60));
  console.log(`Resultados: ${passed} pasados, ${failed} fallidos`);
  console.log("=".repeat(60));
  
  return failed === 0;
}

// Prueba 2: Simular importFichajes.js
function testImportFichajes() {
  console.log("\n" + "=".repeat(60));
  console.log("TEST: Escenario importFichajes.js");
  console.log("=".repeat(60));
  
  const registros = [
    { fechaISO: '2026-12-10', horas: ['07:30', '17:00'] },
    { fechaISO: '2026-12-15', horas: ['07:30', '17:00'] },
    { fechaISO: '2026-01-05', horas: ['07:30', '17:00'] },
    { fechaISO: '2026-01-10', horas: ['07:30', '17:00'] },
  ];
  
  const today = new Date('2026-01-04');
  const year = 2026;
  
  console.log(`Registros antes de ajuste:\n`);
  registros.forEach(r => console.log(`  - ${r.fechaISO}`));
  
  // Aplicar la lógica
  registros.forEach(r => {
    if (r.fechaISO) {
      const parsedDate = new Date(r.fechaISO);
      const dateMonth = parsedDate.getMonth();
      const dateDay = parsedDate.getDate();
      const todayMonth = today.getMonth();
      const todayDay = today.getDate();
      
      let isFromPreviousYear = false;
      
      if (dateMonth < todayMonth) {
        isFromPreviousYear = true;
      } else if (dateMonth === todayMonth && dateDay < todayDay) {
        isFromPreviousYear = true;
      } else if (dateMonth > todayMonth && todayMonth <= 2 && dateMonth >= 10) {
        isFromPreviousYear = true;
      }
      
      if (isFromPreviousYear) {
        const iso = r.fechaISO;
        const newIso = String(year - 1) + iso.slice(4);
        console.log(`  Ajustando: ${iso} → ${newIso} (es anterior en el calendario)`);
        r.fechaISO = newIso;
      }
    }
  });
  
  console.log(`\nRegistros después de ajuste:\n`);
  registros.forEach(r => console.log(`  - ${r.fechaISO}`));
  
  const expectedDates = ['2025-12-10', '2025-12-15', '2026-01-05', '2026-01-10'];
  const actualDates = registros.map(r => r.fechaISO);
  const allCorrect = actualDates.every((d, i) => d === expectedDates[i]);
  
  console.log(`\nVerificación: ${allCorrect ? '✅ PASS' : '❌ FAIL'}`);
  
  return allCorrect;
}

// Prueba 3: Extension TRAGSA format
function testTragsa() {
  console.log("\n" + "=".repeat(60));
  console.log("TEST: Formato TRAGSA en extensión");
  console.log("=".repeat(60));
  
  const data = {
    '2026-12-10': { times: ['07:30', '17:00'] },
    '2026-12-15': { times: ['07:30', '17:00'] },
    '2026-01-05': { times: ['07:30', '17:00'] },
    '2026-01-10': { times: ['07:30', '17:00'] },
  };
  
  const today = new Date('2026-01-04');
  
  console.log(`Fechas antes de ajuste:\n`);
  Object.keys(data).forEach(d => console.log(`  - ${d}`));
  
  // Aplicar la lógica
  const dataNew = {};
  for (let dateStr of Object.keys(data)) {
    const parsedDate = new Date(dateStr);
    let finalDate = dateStr;
    
    const dateMonth = parsedDate.getMonth();
    const dateDay = parsedDate.getDate();
    const todayMonth = today.getMonth();
    const todayDay = today.getDate();
    
    let isFromPreviousYear = false;
    
    if (dateMonth < todayMonth) {
      isFromPreviousYear = true;
    } else if (dateMonth === todayMonth && dateDay < todayDay) {
      isFromPreviousYear = true;
    } else if (dateMonth > todayMonth && todayMonth <= 2 && dateMonth >= 10) {
      isFromPreviousYear = true;
    }
    
    if (isFromPreviousYear) {
      const parts = dateStr.split('-');
      finalDate = `${parseInt(parts[0]) - 1}-${parts[1]}-${parts[2]}`;
      console.log(`  Ajustando: ${dateStr} → ${finalDate}`);
    }
    
    dataNew[finalDate] = data[dateStr];
  }
  
  console.log(`\nFechas después de ajuste:\n`);
  Object.keys(dataNew).forEach(d => console.log(`  - ${d}`));
  
  const expectedDates = ['2025-12-10', '2025-12-15', '2026-01-05', '2026-01-10'];
  const actualDates = Object.keys(dataNew).sort();
  const allCorrect = actualDates.every((d, i) => d === expectedDates[i]);
  
  console.log(`\nVerificación: ${allCorrect ? '✅ PASS' : '❌ FAIL'}`);
  
  return allCorrect;
}

// Ejecutar tests
if (require.main === module) {
  const test1 = testYearDetection();
  const test2 = testImportFichajes();
  const test3 = testTragsa();
  
  console.log("\n" + "=".repeat(60));
  if (test1 && test2 && test3) {
    console.log("✅ TODOS LOS TESTS PASARON");
    process.exit(0);
  } else {
    console.log("❌ ALGUNOS TESTS FALLARON");
    process.exit(1);
  }
}
