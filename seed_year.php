<?php
require __DIR__ . '/db.php';
$pdo = get_pdo();
if (!$pdo) { echo "No DB connection\n"; exit(1); }
// find admin id
$stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
$stmt->execute(['admin']);
$u = $stmt->fetch();
$uid = $u ? $u['id'] : 1;
$start = new DateTime('2025-01-01');
$end = new DateTime('2025-12-31');
$interval = new DateInterval('P1D');
$period = new DatePeriod($start, $interval, (clone $end)->add(new DateInterval('P1D')));
$ins = $pdo->prepare('INSERT INTO entries (user_id, date, start, coffee_out, coffee_in, lunch_out, lunch_in, end, note, created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE start=VALUES(start), coffee_out=VALUES(coffee_out), coffee_in=VALUES(coffee_in), lunch_out=VALUES(lunch_out), lunch_in=VALUES(lunch_in), end=VALUES(end), note=VALUES(note)');

function rand_time($base, $range_minutes){
    $t = strtotime($base);
    $offset = mt_rand(-$range_minutes, $range_minutes);
    return date('H:i:s', $t + $offset*60);
}

function ensure_after($later, $earlier){
    if (strtotime($later) > strtotime($earlier)) return $later;
    return date('H:i:s', strtotime($earlier) + 10*60);
}

$count = 0;
foreach ($period as $d) {
    $dow = (int)$d->format('N'); // 1 (Mon) .. 7 (Sun)
    if ($dow >= 6) continue; // skip weekends

    // base schedule
    $base_start = '07:30:00';
    $base_coffee_out = '10:30:00';
    $base_coffee_in = '10:45:00';
    $base_lunch_out = '14:00:00';
    $base_lunch_in = '14:30:00';
    $base_end = '18:00:00';

    // vary ranges a bit by type
    $start_t = rand_time($base_start, 12);
    $co_out = rand_time($base_coffee_out, 8);
    $co_in = rand_time($base_coffee_in, 8);
    $lu_out = rand_time($base_lunch_out, 20);
    $lu_in = rand_time($base_lunch_in, 20);

    $co_in = ensure_after($co_in, $co_out);
    $lu_in = ensure_after($lu_in, $lu_out);
    $end_t = ensure_after($base_end, $lu_in);

    $ins->execute([$uid, $d->format('Y-m-d'), $start_t, $co_out, $co_in, $lu_out, $lu_in, $end_t, '']);
    $count++;
}

echo "Inserted/updated $count rows for user_id $uid\n";
