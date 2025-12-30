<?php
require __DIR__ . '/db.php';
$pdo = get_pdo();
if (!$pdo) { echo "No DB connection\n"; exit(1); }
$dataFile = __DIR__ . '/data/entries.json';
if (!file_exists($dataFile)) { echo "No data file\n"; exit(1); }
$data = json_decode(file_get_contents($dataFile), true);
if (!$data) { echo "JSON error: ".json_last_error_msg()."\n"; exit(1); }
// find admin id
$stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
$stmt->execute(['admin']);
$u = $stmt->fetch();
$uid = $u ? $u['id'] : 1;
$insert = $pdo->prepare('INSERT INTO entries (user_id, date, start, coffee_out, coffee_in, lunch_out, lunch_in, end, note, created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE start=VALUES(start), coffee_out=VALUES(coffee_out), coffee_in=VALUES(coffee_in), lunch_out=VALUES(lunch_out), lunch_in=VALUES(lunch_in), end=VALUES(end), note=VALUES(note)');
$cnt = 0;
foreach ($data as $row) {
    $insert->execute([
        $uid,
        $row['date'] ?? null,
        $row['start'] ?? null,
        $row['coffee_out'] ?? null,
        $row['coffee_in'] ?? null,
        $row['lunch_out'] ?? null,
        $row['lunch_in'] ?? null,
        $row['end'] ?? null,
        $row['note'] ?? '',
    ]);
    $cnt++;
}
echo "Imported $cnt rows\n";
