<?php
require_once __DIR__ . '/../db.php';
$pdo = get_pdo();
if (!$pdo) {
    fwrite(STDERR, "No se pudo conectar a la base de datos.\n");
    exit(1);
}

// Total
$total = $pdo->query('SELECT COUNT(*) AS c FROM entries')->fetchColumn();
fwrite(STDOUT, "Total entries: $total\n");

// Counts per year
$years = $pdo->query('SELECT YEAR(date) AS y, COUNT(*) AS c FROM entries GROUP BY y ORDER BY y DESC')->fetchAll();
if ($years) {
    fwrite(STDOUT, "\nConteo por año:\n");
    foreach ($years as $row) {
        fwrite(STDOUT, "  {$row['y']}: {$row['c']}\n");
    }
} else {
    fwrite(STDOUT, "\nNo hay entradas por año.\n");
}

// Show last 20 entries
fwrite(STDOUT, "\nÚltimas 20 entradas:\n");
$stmt = $pdo->query('SELECT id,user_id,date,start,coffee_out,coffee_in,lunch_out,lunch_in,end,note,created_at FROM entries ORDER BY date DESC, id DESC LIMIT 20');
$rows = $stmt->fetchAll();
if (!$rows) {
    fwrite(STDOUT, "  (no hay filas)\n");
    exit(0);
}
foreach ($rows as $r) {
    fwrite(STDOUT, sprintf("  id=%d user=%d date=%s start=%s coffee_out=%s coffee_in=%s lunch_out=%s lunch_in=%s end=%s note=%s created=%s\n",
        $r['id'],$r['user_id'],$r['date'],$r['start'] ?: 'NULL',$r['coffee_out'] ?: 'NULL',$r['coffee_in'] ?: 'NULL',$r['lunch_out'] ?: 'NULL',$r['lunch_in'] ?: 'NULL',$r['end'] ?: 'NULL',str_replace("\n"," ",substr($r['note']?:'',0,40)),$r['created_at']
    ));
}
