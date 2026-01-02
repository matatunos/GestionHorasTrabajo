<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib.php';

$pdo = get_pdo();
if (!$pdo) { echo "No DB\n"; exit(1); }
$year = $argv[1] ?? date('Y');
$year = intval($year);
$cfg = get_year_config($year);

$users = $pdo->query('SELECT id, username FROM users')->fetchAll(PDO::FETCH_ASSOC);
foreach ($users as $u) {
    $uid = $u['id'];
    echo "User {$u['username']} ($uid)\n";
    $dt = new DateTimeImmutable("$year-01-01");
    $end = new DateTimeImmutable("$year-12-31");
    $seen = [];
    for ($cur = $dt; $cur <= $end; $cur = $cur->modify('+1 day')) {
        $d = $cur->format('Y-m-d');
        $est = $pdo->prepare('SELECT * FROM entries WHERE user_id = ? AND date = ? LIMIT 1');
        $est->execute([$uid, $d]);
        $e = $est->fetch(PDO::FETCH_ASSOC) ?: ['date'=>$d];
        $hstmt = $pdo->prepare('SELECT date,label,type,annual,user_id FROM holidays WHERE (YEAR(date)=? OR annual=1) AND (user_id IS NULL OR user_id = ?)');
        $hstmt->execute([$year,$uid]);
        $hols = [];
        foreach ($hstmt->fetchAll(PDO::FETCH_ASSOC) as $hh) {
            $kd = $hh['date'];
            if (!empty($hh['annual'])) $kd = sprintf('%04d-%s', $year, substr($hh['date'],5));
            $hols[$kd] = $hh;
        }
        if (isset($hols[$d])) { $e['is_holiday']=true; $e['special_type']=$hols[$d]['type']; }
        $calc = compute_day($e, $cfg);
        // print when non-zero or first two days
        if (!isset($seen[$d])) {
            $seen[$d] = 0;
        }
        $seen[$d]++;
        if ($seen[$d] > 1) {
            echo "DUPLICATE PROCESSING: $d seen {$seen[$d]} times\n";
        }
        if (($cur->format('j') <= 2) || ($calc['worked_minutes'] ?? 0) !== 0) {
            echo "$d -> worked_minutes=".($calc['worked_minutes']??'') . " (seen={$seen[$d]})\n";
        }
    }
}

echo "Done\n";
