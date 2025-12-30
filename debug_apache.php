<?php
require __DIR__ . '/db.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = get_pdo();
if (!$pdo) { echo "PDO: NULL\n"; exit; }
echo "PDO: OK\n";
$stmt = $pdo->prepare('SELECT id,username,password,is_admin FROM users WHERE username=? LIMIT 1');
$stmt->execute(['admin']);
$u = $stmt->fetch();
if (!$u) { echo "admin: NOT FOUND\n"; exit; }
echo "admin id: {$u['id']} is_admin: {$u['is_admin']}\n";
echo "hash: ".$u['password']."\n";
