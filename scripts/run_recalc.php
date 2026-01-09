<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib.php';

$pdo = get_pdo();
if (!$pdo) { echo "No DB\n"; exit(1); }
$year = $argv[1] ?? date('Y');
$year = intval($year);

$pdo->exec("CREATE TABLE IF NOT EXISTS app_settings (
            name VARCHAR(191) PRIMARY KEY,
            value TEXT NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$users = $pdo->query('SELECT id, username FROM users')->fetchAll(PDO::FETCH_ASSOC);
foreach ($users as $u) {
    $uid = $u['id'];
    $months = array_fill(1,12, ['worked'=>0,'expected'=>0]);
    $cfg = get_year_config($year);
    $dt = new DateTimeImmutable("$year-01-01");
    $end = new DateTimeImmutable("$year-12-31");
    for ($cur = $dt; $cur <= $end; $cur = $cur->modify('+1 day')) {
        $d = $cur->format('Y-m-d');
        $est = $pdo->prepare('SELECT * FROM entries WHERE user_id = ? AND date = ? LIMIT 1');
        $est->execute([$uid,$d]);
        $e = $est->fetch(PDO::FETCH_ASSOC) ?: ['date'=>$d];
        $hstmt = $pdo->prepare('SELECT date,label,type,annual,user_id FROM holidays WHERE (YEAR(date)=? OR annual=1) AND (user_id IS NULL OR user_id = ?)');
        $hstmt->execute([$year,$uid]);
        $hols = [];
        foreach ($hstmt->fetchAll(PDO::FETCH_ASSOC) as $hh) {
            $kd = $hh['date']; if (!empty($hh['annual'])) $kd = sprintf('%04d-%s', $year, substr($hh['date'],5)); $hols[$kd] = $hh;
        }
        if (isset($hols[$d])) { $e['is_holiday']=true; $e['special_type']=$hols[$d]['type']; }
        $calc = compute_day($e, $cfg);
        $m = intval($cur->format('n'));
        $months[$m]['worked'] += $calc['worked_minutes'] ?? 0;
        $months[$m]['expected'] += $calc['expected_minutes'] ?? 0;
    }
    $key = 'summary_' . $uid . '_' . $year;
    $stmt = $pdo->prepare('REPLACE INTO app_settings (name,value) VALUES (?,?)');
    $stmt->execute([$key, json_encode($months)]);
    echo "Recalculated user {$u['username']} ($uid)\n";
}

echo "Done recalculation for $year\n";
