<?php
// Recompute monthly summaries for all users and years and store in app_settings
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib.php';

$pdo = get_pdo();
if (!$pdo) { echo "No DB connection\n"; exit(1); }

$users = $pdo->query('SELECT id FROM users')->fetchAll();
$years = $pdo->query('SELECT DISTINCT YEAR(date) AS y FROM entries ORDER BY y ASC')->fetchAll();
if (empty($years)) { $years = [[ 'y' => intval(date('Y')) ]]; }

foreach ($users as $u) {
    $uid = $u['id'];
    foreach ($years as $yr) {
        $y = intval($yr['y']);
        $months = array_fill(1,12, ['worked'=>0,'expected'=>0]);
        $startTs = strtotime("$y-01-01"); $endTs = strtotime("$y-12-31");
        $cfg = get_year_config($y, $uid);
        for ($ts = $startTs; $ts <= $endTs; $ts += 86400) {
            $d = date('Y-m-d', $ts); $m = intval(date('n', $ts));
            $est = $pdo->prepare('SELECT * FROM entries WHERE user_id = ? AND date = ? LIMIT 1');
            $est->execute([$uid,$d]);
            $e = $est->fetch() ?: ['date'=>$d];
            $hstmt = $pdo->prepare('SELECT date,label,type,annual,user_id FROM holidays WHERE (YEAR(date)=? OR annual=1) AND (user_id IS NULL OR user_id = ?)');
            $hstmt->execute([$y,$uid]);
            $hols = [];
            foreach ($hstmt->fetchAll() as $hh) { $kd = $hh['date']; if (!empty($hh['annual'])) $kd = sprintf('%04d-%s', $y, substr($hh['date'],5)); $hols[$kd] = $hh; }
            if (isset($hols[$d])) { $e['is_holiday']=true; $e['special_type']=$hols[$d]['type']; }
            $calc = compute_day($e, $cfg);
            $months[$m]['worked'] += $calc['worked_minutes'] ?? 0;
            $months[$m]['expected'] += $calc['expected_minutes'] ?? 0;
        }
        $key = 'summary_' . $uid . '_' . $y;
        $pdo->exec("CREATE TABLE IF NOT EXISTS app_settings (
            name VARCHAR(191) PRIMARY KEY,
            value TEXT NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $stmt = $pdo->prepare('REPLACE INTO app_settings (name,value) VALUES (?,?)');
        $stmt->execute([$key, json_encode($months, JSON_UNESCAPED_UNICODE)]);
        echo "Wrote $key\n";
    }
}

echo "Done\n";
