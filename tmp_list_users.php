<?php
require __DIR__ . '/db.php';
try{
  $pdo = get_pdo();
  $rows = $pdo->query('SELECT id,username,is_admin FROM users')->fetchAll(PDO::FETCH_ASSOC);
  echo json_encode($rows, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){ echo 'ERR:'.$e->getMessage(); }
