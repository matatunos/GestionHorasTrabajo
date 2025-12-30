<?php
require __DIR__ . '/config.php';
$cfg = get_config()['db'];
$dsn = "mysql:host={$cfg['host']};dbname={$cfg['name']};charset={$cfg['charset']}";
header('Content-Type: text/plain; charset=utf-8');
echo "DSN: $dsn\n";
try {
    $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    echo "CONNECTED\n";
} catch (PDOException $e) {
    echo "ERR: ".$e->getMessage()."\n";
}
