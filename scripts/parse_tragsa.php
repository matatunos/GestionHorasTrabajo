<?php
// Server-side parser for TRAGSAnet-like HTML fichajes
header('Content-Type: application/json; charset=utf-8');

function clean_text($s){
  return trim(preg_replace('/\s+/u',' ', strip_tags($s)));
}

function parseFechaToISO($fechaTexto, $year = null) {
  if (!$fechaTexto) return null;
  $fechaTexto = trim($fechaTexto);

  // month map (spanish/english abbreviations)
  $mes_map = [
    'ene'=>'01','enero'=>'01','jan'=>'01','january'=>'01',
    'feb'=>'02','febrero'=>'02','february'=>'02',
    'mar'=>'03','marzo'=>'03','march'=>'03',
    'abr'=>'04','abril'=>'04','apr'=>'04','april'=>'04',
    'may'=>'05','mayo'=>'05','may'=>'05',
    'jun'=>'06','junio'=>'06','june'=>'06',
    'jul'=>'07','julio'=>'07','july'=>'07',
    'ago'=>'08','agosto'=>'08','aug'=>'08','august'=>'08',
    'sep'=>'09','sept'=>'09','septiembre'=>'09','september'=>'09',
    'oct'=>'10','octubre'=>'10','oct'=>'10','october'=>'10',
    'nov'=>'11','noviembre'=>'11','november'=>'11',
    'dic'=>'12','diciembre'=>'12','dec'=>'12','december'=>'12'
  ];

  // Pattern DD-MM or DD/MM or DD-MM (with month name) like 08-dic or 08 dic
  if (preg_match('/^(\d{1,2})\s*[-\/\s]\s*([A-Za-zñÑ]+|\d{1,2})(?:[\s\/-]*(\d{4}))?$/u', $fechaTexto, $m)) {
    $d = str_pad($m[1],2,'0',STR_PAD_LEFT);
    $mraw = strtolower($m[2]);
    $y = $m[3] ?? null;
    $mm = null;
    if (is_numeric($mraw)) {
      $mm = str_pad($mraw,2,'0',STR_PAD_LEFT);
    } else {
      $mraw = mb_strtolower($mraw, 'UTF-8');
      $mraw = substr($mraw,0,3);
      if (isset($mes_map[$mraw])) $mm = $mes_map[$mraw];
      else {
        // try longer
        foreach ($mes_map as $k=>$v) if (strpos($mraw, $k) !== false) { $mm = $v; break; }
      }
    }
    if (!$mm) return null;
    if ($y) $year_use = $y; else $year_use = $year ?? date('Y');
    return sprintf('%04d-%02d-%02d', $year_use, $mm, $d);
  }

  // Pattern DD/MM/YYYY or DD-MM-YYYY
  if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $fechaTexto, $m)) {
    $d = str_pad($m[1],2,'0',STR_PAD_LEFT);
    $mm = str_pad($m[2],2,'0',STR_PAD_LEFT);
    return sprintf('%04d-%02d-%02d', $m[3], $mm, $d);
  }

  // If already YYYY-MM-DD
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaTexto)) return $fechaTexto;

  return null;
}

// Support CLI testing: pass filename as first arg
$html = '';
if (php_sapi_name() === 'cli' && isset($argv[1]) && is_readable($argv[1])) {
  $html = file_get_contents($argv[1]);
} elseif (!empty($_FILES['file']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
  $html = file_get_contents($_FILES['file']['tmp_name']);
} else {
  $html = file_get_contents('php://input');
}

if (!$html) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'No se recibió contenido HTML']);
  exit;
}

libxml_use_internal_errors(true);
$doc = new DOMDocument();
$doc->loadHTML('<?xml encoding="utf-8"?>' . $html);

$xpath = new DOMXPath($doc);

// Try to detect year from POST or from select options (ddl_semanas)
$year = null;
if (!empty($_POST['year'])) $year = intval($_POST['year']);
if (!$year) {
  $optNodes = $xpath->query("//select[contains(@name,'ddl_semanas')]//option");
  foreach ($optNodes as $o) {
    $v = trim($o->getAttribute('value'));
    if (preg_match('/(20\d{2})/', $v, $mm)) { $year = intval($mm[1]); break; }
    if (preg_match('/(20\d{2})/', $o->textContent, $mm)) { $year = intval($mm[1]); break; }
  }
}
if (!$year) $year = intval(date('Y'));

// Find candidate table: prefer #tabla_fichajes, else table with caption containing FICHAJES, else first table
$table = null;
$nodes = $xpath->query("//table[@id='tabla_fichajes']");
if ($nodes->length) $table = $nodes->item(0);
if (!$table) {
  $nodes = $xpath->query("//table[caption and contains(translate(normalize-space(caption/text()), 'abcdefghijklmnopqrstuvwxyz', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'), 'FICHAJES')]");
  if ($nodes->length) $table = $nodes->item(0);
}
if (!$table) {
  $nodes = $xpath->query('//table');
  if ($nodes->length) $table = $nodes->item(0);
}

if (!$table) {
  echo json_encode(['ok'=>true,'records'=>[], 'message'=>'No se encontró tabla de fichajes']);
  exit;
}

// collect rows
$rows = [];
foreach ($xpath->query('.//tr', $table) as $r) $rows[] = $r;

// attempt to find 'fechas' row
$fechas = [];
foreach ($rows as $r) {
  $cls = $r->getAttribute('class');
  if ($cls && stripos($cls,'fechas') !== false) {
    foreach ($xpath->query('.//td', $r) as $td) $fechas[] = clean_text($td->textContent);
    break;
  }
}

// if not found, try to detect a row where many cells look like dates
if (empty($fechas)) {
  foreach ($rows as $r) {
    $tds = $xpath->query('.//td', $r);
    if ($tds->length < 3) continue;
    $count_dates = 0; $vals = [];
    foreach ($tds as $td) {
      $t = clean_text($td->textContent);
      $vals[] = $t;
      if (preg_match('/\d{1,2}([\-\/\s][A-Za-zñÑ]+|[\-\/]\d{1,2})/', $t)) $count_dates++;
    }
    if ($count_dates >= max(1,intval($tds->length/2))) { $fechas = $vals; break; }
  }
}

// normalize fechas: drop leading empty or 'SEMANA' cell
if (count($fechas) && (trim($fechas[0]) === '' || preg_match('/^SEMANA/i', $fechas[0]))) array_shift($fechas);

// find horas row: class 'horas' or a row with many time patterns
$records = [];
foreach ($rows as $r) {
  $cls = $r->getAttribute('class');
  if ($cls && stripos($cls,'horas') !== false) {
    $cells = [];
    foreach ($xpath->query('.//td', $r) as $td) $cells[] = $td;
    if (count($cells) && trim($cells[0]->textContent) === '') array_shift($cells);
    foreach ($cells as $idx=>$cell) {
      preg_match_all('/(\d{1,2}:\d{2})/', $cell->textContent, $ms);
      $times = $ms[0];
      $day = $fechas[$idx] ?? null;
      $fechaISO = parseFechaToISO($day, $year);
      $records[] = ['dia'=>$day ?? "col_".($idx+1), 'fecha'=>$day, 'fechaISO'=>$fechaISO, 'horas'=>$times, 'balance'=>''];
    }
    break;
  }
}

// fallback: find first row with many time patterns
if (empty($records)) {
  foreach ($rows as $r) {
    $tds = $xpath->query('.//td', $r);
    $cells = [];
    foreach ($tds as $td) $cells[] = $td;
    if (count($cells) === 0) continue;
    $time_counts = 0;
    foreach ($cells as $td) if (preg_match('/\d{1,2}:\d{2}/', $td->textContent)) $time_counts++;
    if ($time_counts >= 2) {
      if (count($cells) && trim($cells[0]->textContent) === '') array_shift($cells);
      foreach ($cells as $idx=>$cell) {
        preg_match_all('/(\d{1,2}:\d{2})/', $cell->textContent, $ms);
        $times = $ms[0];
        $day = $fechas[$idx] ?? null;
        $fechaISO = parseFechaToISO($day, $year);
        $records[] = ['dia'=>$day ?? "col_".($idx+1), 'fecha'=>$day, 'fechaISO'=>$fechaISO, 'horas'=>$times, 'balance'=>''];
      }
      break;
    }
  }
}

// final: ensure fechaISO filled using parse on 'fecha' text if missing
foreach ($records as &$rec) {
  if (empty($rec['fechaISO']) && !empty($rec['fecha'])) {
    $rec['fechaISO'] = parseFechaToISO($rec['fecha'], $year);
  }
}

// remove records with no times (optional: include empty days too)
$valid = array_filter($records, function($r){ return is_array($r['horas']) && count($r['horas'])>0; });

echo json_encode(['ok'=>true,'records'=>array_values($records),'found'=>count($records),'found_with_times'=>count($valid)]);

?>
