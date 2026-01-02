<?php
// Server-side parser for TRAGSAnet-like HTML fichajes
header('Content-Type: application/json; charset=utf-8');

function clean_text($s){
  return trim(preg_replace('/\s+/u',' ', strip_tags($s)));
}

$html = '';
if (!empty($_FILES['file']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
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
if (!$doc->loadHTML('<?xml encoding="utf-8"?>' . $html)) {
  // fall back: still try
}

$xpath = new DOMXPath($doc);

// Try to find a table with id 'tabla_fichajes' or with caption containing 'FICHAJES'
$table = null;
$nodes = $xpath->query("//table[@id='tabla_fichajes']");
if ($nodes->length) $table = $nodes->item(0);
if (!$table) {
  $nodes = $xpath->query("//table[caption and contains(translate(normalize-space(caption/text()), 'abcdefghijklmnopqrstuvwxyz', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'), 'FICHAJES')]");
  if ($nodes->length) $table = $nodes->item(0);
}
if (!$table) {
  // fallback to first table
  $nodes = $xpath->query("//table");
  if ($nodes->length) $table = $nodes->item(0);
}

if (!$table) {
  echo json_encode(['ok'=>true,'records'=>[], 'message'=>'No se encontró tabla de fichajes']);
  exit;
}

// find row with class 'fechas' and 'horas'
$fechas = [];
$rows = $xpath->query('.//tr', $table);
foreach ($rows as $r) {
  $cls = $r->getAttribute('class');
  if (stripos($cls,'fechas') !== false) {
    $tds = $xpath->query('.//td', $r);
    foreach ($tds as $i=>$td) {
      $text = clean_text($td->textContent);
      $fechas[] = $text;
    }
  }
}

// normalize: drop first empty cell if present
if (count($fechas) && ($fechas[0] === '' || preg_match('/^SEMANA/i',$fechas[0]))) {
  array_shift($fechas);
}

$records = [];
// find horas row
foreach ($rows as $r) {
  $cls = $r->getAttribute('class');
  if (stripos($cls,'horas') !== false) {
    $tds = $xpath->query('.//td', $r);
    $cells = [];
    foreach ($tds as $td) {
      $cells[] = $td;
    }
    // drop first cell if empty
    if (count($cells) && trim($cells[0]->textContent) === '') array_shift($cells);

    foreach ($cells as $idx => $cell) {
      // find all times HH:MM inside cell
      preg_match_all('/(\d{1,2}:\d{2})/', $cell->textContent, $ms);
      $times = $ms[0];
      $day = $fechas[$idx] ?? null;
      $records[] = ['dia'=> $day ?? "col_".($idx+1), 'fecha'=> $day, 'fechaISO'=> null, 'horas'=>$times, 'balance'=>''];
    }
    break;
  }
}

echo json_encode(['ok'=>true,'records'=>$records]);

?>
