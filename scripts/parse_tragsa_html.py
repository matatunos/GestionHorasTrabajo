#!/usr/bin/env python3
import re
import sys
from pathlib import Path

IN = Path(sys.argv[1]) if len(sys.argv) > 1 else Path('sample_Datos_de_Usuario.html')
OUT = Path(sys.argv[2]) if len(sys.argv) > 2 else Path('parsed_fichajes.csv')

html = IN.read_text(encoding='utf-8')

# extract the fechas row
m_fechas = re.search(r"<tr[^>]*class=['\"]fechas['\"][\s\S]*?</tr>", html, re.IGNORECASE)
if not m_fechas:
    print('ERROR: no se encontró la fila de fechas en el HTML', file=sys.stderr)
    sys.exit(2)
fechas_html = m_fechas.group(0)
# extract text inside each <td> (skip first empty cell)
fechas = re.findall(r"<td[^>]*>(.*?)</td>", fechas_html, re.IGNORECASE|re.DOTALL)
# strip tags and whitespace
clean = lambda s: re.sub(r"<[^>]+>", "", s).strip()
fechas = [clean(f) for f in fechas]
if fechas and (fechas[0] == '' or fechas[0].lower().startswith('semana')):
    fechas = fechas[1:]

# extract horas row
m_horas = re.search(r"<tr[^>]*class=['\"]horas['\"][\s\S]*?</tr>", html, re.IGNORECASE)
if not m_horas:
    print('ERROR: no se encontró la fila de horas en el HTML', file=sys.stderr)
    sys.exit(3)
horas_html = m_horas.group(0)
# get all <td> blocks (skip first)
tds = re.findall(r"<td[^>]*>(.*?)</td>", horas_html, re.IGNORECASE|re.DOTALL)
if tds and (tds[0].strip() == '' or 'SEMANA' in tds[0].upper()):
    tds = tds[1:]

# For each td extract times like 07:34
time_re = re.compile(r"(\d{1,2}:\d{2})")
result = []
for i, td in enumerate(tds):
    day = fechas[i] if i < len(fechas) else f'col{i+1}'
    times = time_re.findall(td)
    result.append((day, times))

# If no records found, exit with code 1
total_times = sum(len(times) for _, times in result)
if total_times == 0:
    print('ERROR: no se han encontrado registros en el archivo', file=sys.stderr)
    sys.exit(1)

# write CSV: date,time per line
with OUT.open('w', encoding='utf-8') as f:
    f.write('date,time\n')
    for day, times in result:
        for t in times:
            f.write(f'{day},{t}\n')

print(f'Wrote {OUT} with {total_times} records')
